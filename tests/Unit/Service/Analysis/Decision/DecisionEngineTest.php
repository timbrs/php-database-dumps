<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Analysis\Decision;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\Decision;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\DecisionEngine;
use Timbrs\DatabaseDumps\Service\Faker\PatternDetector;

class DecisionEngineTest extends TestCase
{
    public function testHugeFullExportBecomesPartialWithSlices(): void
    {
        $decisions = $this->decide(['jobs' => $this->table([
            'config' => ['mode' => 'full_export'],
            'row_count' => ['value' => 4000000, 'estimated' => true],
            'columns' => [
                'id' => $this->column('bigint'),
                'status_id' => $this->column('integer', ['categorical' => true, 'distinct_count' => 4]),
            ],
        ])]);

        $kinds = $this->kindsOf($decisions, 'R1');
        self::assertContains(Decision::KIND_MODE, $kinds);
        self::assertContains(Decision::KIND_STRATIFY, $kinds);
        self::assertContains(Decision::KIND_ORDER_BY, $kinds);
        self::assertSame('partial_export', $this->first($decisions, 'R1', Decision::KIND_MODE)['proposed']);
        self::assertFalse($this->first($decisions, 'R1', Decision::KIND_MODE)['auto'], 'состав выборки меняет человек');
    }

    public function testDictionaryFilledByMigrationIsPromotedToFullExport(): void
    {
        $decisions = $this->decide(['sources_dict' => $this->table([
            'config' => ['mode' => 'partial_export', 'limit' => 100],
            'row_count' => ['value' => 186],
            'traits' => ['dict' => true, 'in_degree' => 1],
            'migrations' => ['dml_rows' => 186, 'last_changed_in' => 'Version20240101000000'],
        ])]);

        $decision = $this->first($decisions, 'R2', Decision::KIND_MODE);
        self::assertSame('full_export', $decision['proposed']);
        self::assertStringContainsString('наполняется миграциями', $decision['why']);
        self::assertSame('migration', $decision['evidence'][1]['source']);
    }

    public function testEavTableGetsNestedStratification(): void
    {
        $decisions = $this->decide(['clients_attrs' => $this->table([
            'config' => ['mode' => 'partial_export', 'limit' => 1000],
            'traits' => ['eav' => ['role' => 'values', 'pair' => 'clients_attrs_dict']],
            'columns' => [
                'attr_id' => $this->column('integer', ['categorical' => true, 'distinct_count' => 12]),
                'value_string' => $this->column('character varying'),
            ],
        ])]);

        $decision = $this->first($decisions, 'R3', Decision::KIND_STRATIFY);
        self::assertSame([['column' => 'attr_id', 'then' => ['column' => 'value_string']]], $decision['proposed']);
    }

    public function testPersonalDataGetsFakerAutomaticallyWithTypeAwarePattern(): void
    {
        $decisions = $this->decide(['persons' => $this->table([
            'config' => ['mode' => 'partial_export', 'limit' => 100],
            'columns' => [
                'fio' => $this->column('character varying'),
                'inn' => $this->column('bigint'),
                'birth_date' => $this->column('date'),
                'phone_id' => $this->column('bigint'),
            ],
        ])]);

        $byColumn = [];
        foreach ($decisions['decisions'] as $decision) {
            if ($decision['rule'] === 'R4') {
                $byColumn[$decision['column']] = $decision;
            }
        }

        self::assertSame(PatternDetector::PATTERN_FIO, $byColumn['fio']['proposed']);
        self::assertTrue($byColumn['fio']['auto'], 'замена ПД не меняет состав выборки — применяется сама');
        // ИНН в bigint остаётся числом, дата рождения — датой.
        self::assertSame(PatternDetector::PATTERN_INN, $byColumn['inn']['proposed']);
        self::assertSame(PatternDetector::PATTERN_BIRTH_DATE, $byColumn['birth_date']['proposed']);
        // «phone» в числовой колонке — случай, где инструмент молчит и зовёт человека.
        self::assertArrayNotHasKey('phone_id', $byColumn);
    }

    public function testForeignKeyLinkIsAutoWhileCodeLinkNeedsReview(): void
    {
        $decisions = $this->decide(['orders' => $this->table([
            'config' => ['mode' => 'partial_export', 'limit' => 100],
            'edges' => [
                ['dir' => 'out', 'table' => 'public.clients', 'column' => 'client_id', 'parent_column' => 'id', 'source' => 'db_fk', 'in_db_fk' => true],
                ['dir' => 'out', 'table' => 'public.managers', 'column' => 'manager_id', 'parent_column' => 'id', 'source' => 'code_doctrine', 'in_db_fk' => false, 'evidence' => ['file' => 'src/Entity/Order.php', 'line' => 42]],
            ],
        ])]);

        $byColumn = [];
        foreach ($decisions['decisions'] as $decision) {
            if ($decision['rule'] === 'R5') {
                $byColumn[$decision['column']] = $decision;
            }
        }

        self::assertTrue($byColumn['client_id']['auto']);
        self::assertSame('high', $byColumn['client_id']['confidence']);
        self::assertFalse($byColumn['manager_id']['auto']);
        self::assertSame('src/Entity/Order.php', $byColumn['manager_id']['evidence'][1]['file']);
    }

    public function testVersionedTableGetsThreeStateCriteria(): void
    {
        $decisions = $this->decide(['clients' => $this->table([
            'config' => ['mode' => 'partial_export', 'limit' => 100],
            'traits' => ['scd2' => true, 'active_flag' => true],
            'columns' => ['active_flg' => $this->column('boolean'), 'date_to' => $this->column('timestamp')],
        ])]);

        $criteria = $this->first($decisions, 'R6', Decision::KIND_CRITERIA)['proposed'];
        $names = array_column($criteria, 'name');
        self::assertSame(['current', 'history', 'deactivated'], $names);
    }

    public function testPhantomTableIsRemovedAutomatically(): void
    {
        $decisions = $this->decide(['ghost' => ['phantom' => true, 'config' => ['mode' => 'partial_export']]]);

        $decision = $this->first($decisions, 'R7', Decision::KIND_REMOVE_TABLE);
        self::assertTrue($decision['auto']);
    }

    public function testEnumCaseMissingInDatabaseIsReported(): void
    {
        $decisions = $this->decide(['activities' => $this->table([
            'config' => ['mode' => 'partial_export', 'limit' => 100],
            'columns' => [
                'result_id' => $this->column('integer', ['codes' => ['1', '2', '-1', '-2', '-3'], 'codes_complete' => true, 'categorical' => true, 'distinct_count' => 5], [
                    'class' => 'App\\Enum\\ResultIdEnum',
                    'cases' => ['OK' => '1', 'FAIL' => '2', 'A' => '-1', 'B' => '-2', 'C' => '-3', 'OVERDUE_CLOSED' => '-4'],
                ]),
            ],
        ])]);

        $decision = $this->first($decisions, 'R9', Decision::KIND_CRITERIA);
        self::assertStringContainsString('OVERDUE_CLOSED', $decision['why']);
        self::assertSame('high', $decision['confidence']);
    }

    public function testQuotaAboveLimitLowersPerValue(): void
    {
        $decisions = $this->decide(['clients' => $this->table([
            'config' => [
                'mode' => 'partial_export',
                'limit' => 100,
                'sample' => ['stratify_by' => 'status', 'per_value' => 100],
            ],
            'columns' => ['status' => $this->column('character varying', ['categorical' => true, 'distinct_count' => 10])],
        ])]);

        $decision = $this->first($decisions, 'R10', Decision::KIND_PER_VALUE);
        self::assertSame(10, $decision['proposed']);
        self::assertSame(100, $decision['current']);
    }

    public function testPhonePatternOnInnColumnIsReplaced(): void
    {
        $decisions = $this->decide(['clients' => $this->table([
            'config' => ['mode' => 'partial_export', 'limit' => 100],
            'columns' => ['inn' => $this->column('character varying', [], null, PatternDetector::PATTERN_PHONE)],
        ])]);

        $decision = $this->first($decisions, 'R11', Decision::KIND_FAKER);
        self::assertSame(PatternDetector::PATTERN_PHONE, $decision['current']);
        self::assertSame(PatternDetector::PATTERN_INN, $decision['proposed']);
        self::assertTrue($decision['auto']);
    }

    public function testIdIsStableAcrossRunsAndChangesWithProposal(): void
    {
        $dossier = ['jobs' => $this->table([
            'config' => ['mode' => 'partial_export', 'limit' => 100],
            'columns' => ['fio' => $this->column('character varying')],
        ])];

        $first = $this->decide($dossier);
        $second = $this->decide($dossier);
        self::assertSame(
            array_column($first['decisions'], 'id'),
            array_column($second['decisions'], 'id')
        );

        self::assertNotSame(
            Decision::makeId('public.jobs', 'fio', Decision::KIND_FAKER, 'R4', 'fio'),
            Decision::makeId('public.jobs', 'fio', Decision::KIND_FAKER, 'R4', 'name')
        );
    }

    /**
     * @param array<string, array<string, mixed>> $tables
     * @return array<string, mixed>
     */
    private function decide(array $tables): array
    {
        return (new DecisionEngine())->decide(['schema' => 'public', 'tables' => $tables]);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function table(array $overrides): array
    {
        return array_merge([
            'row_count' => ['value' => 1000, 'estimated' => false],
            'config' => ['mode' => 'partial_export', 'limit' => 100],
            'traits' => [],
            'edges' => [],
            'columns' => [],
            'migrations' => null,
        ], $overrides);
    }

    /**
     * @param array<string, mixed>      $profile
     * @param array<string, mixed>|null $enum
     * @return array<string, mixed>
     */
    private function column(string $type, array $profile = [], ?array $enum = null, ?string $faker = null): array
    {
        return [
            'type' => $type,
            'profile' => $profile,
            'enum' => $enum,
            'usages' => [],
            'pii' => ['faker' => $faker],
            'coverage' => ['covered_by' => null, 'detail' => null],
            'ambiguous' => [],
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<int, string>
     */
    private function kindsOf(array $result, string $rule): array
    {
        $kinds = [];
        foreach ($result['decisions'] as $decision) {
            if ($decision['rule'] === $rule) {
                $kinds[] = $decision['kind'];
            }
        }

        return $kinds;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function first(array $result, string $rule, string $kind): array
    {
        foreach ($result['decisions'] as $decision) {
            if ($decision['rule'] === $rule && $decision['kind'] === $kind) {
                return $decision;
            }
        }
        self::fail('нет решения ' . $rule . '/' . $kind);
    }
}
