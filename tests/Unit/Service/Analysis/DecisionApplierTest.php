<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Analysis;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\Decision;
use Timbrs\DatabaseDumps\Service\Analysis\DecisionApplier;

class DecisionApplierTest extends TestCase
{
    /** @var DecisionApplier */
    private $applier;

    protected function setUp(): void
    {
        $this->applier = new DecisionApplier();
    }

    public function testAutoDecisionAppliesWithoutAcceptance(): void
    {
        $config = ['faker' => []];
        $status = $this->apply($config, [
            'table' => 'persons.persons',
            'column' => 'last_name',
            'kind' => Decision::KIND_FAKER,
            'current' => null,
            'proposed' => 'lastname',
            'auto' => true,
        ]);

        $this->assertSame(DecisionApplier::STATUS_APPLIED, $status);
        $this->assertSame('lastname', $config['faker']['persons']['persons']['last_name']);
    }

    /**
     * Всё, что меняет состав выборки, ждёт отметки: иначе тулза сама решала бы,
     * какие данные попадут в дамп.
     */
    public function testNonAutoDecisionNeedsAcceptance(): void
    {
        $config = ['partial_export' => ['public' => ['orders' => []]]];
        $decision = [
            'table' => 'public.orders',
            'kind' => Decision::KIND_LIMIT,
            'current' => null,
            'proposed' => 1000,
            'auto' => false,
        ];

        $this->assertSame(DecisionApplier::STATUS_SKIPPED_NOT_ACCEPTED, $this->apply($config, $decision));
        $this->assertSame([], $config['partial_export']['public']['orders']);

        $decision['accepted'] = true;
        $this->assertSame(DecisionApplier::STATUS_APPLIED, $this->apply($config, $decision));
        $this->assertSame(1000, $config['partial_export']['public']['orders']['limit']);
    }

    /**
     * accepted: false — человек посмотрел и отказал. Даже auto-решение это уважает.
     */
    public function testExplicitRefusalBeatsAuto(): void
    {
        $config = [];
        $status = $this->apply($config, [
            'table' => 'public.orders',
            'kind' => Decision::KIND_REMOVE_TABLE,
            'current' => null,
            'proposed' => null,
            'auto' => true,
            'accepted' => false,
        ]);

        $this->assertSame(DecisionApplier::STATUS_SKIPPED_NOT_ACCEPTED, $status);
    }

    public function testExistingValueWinsWithoutOverride(): void
    {
        $config = ['partial_export' => ['public' => ['orders' => ['limit' => 500]]]];
        $decision = [
            'table' => 'public.orders',
            'kind' => Decision::KIND_LIMIT,
            'current' => 500,
            'proposed' => 1000,
            'accepted' => true,
        ];

        $this->assertSame(DecisionApplier::STATUS_SKIPPED_EXISTS, $this->apply($config, $decision));
        $this->assertSame(500, $config['partial_export']['public']['orders']['limit']);

        $decision['override'] = true;
        $this->assertSame(DecisionApplier::STATUS_APPLIED, $this->apply($config, $decision));
        $this->assertSame(1000, $config['partial_export']['public']['orders']['limit']);
    }

    /**
     * Конфиг правили руками после анализа — решение исходило из другого значения,
     * применять его нельзя: оно затрёт правку, которой не видело.
     */
    public function testDecisionFromOutdatedConfigIsStale(): void
    {
        $config = ['partial_export' => ['public' => ['orders' => ['limit' => 777]]]];
        $status = $this->apply($config, [
            'table' => 'public.orders',
            'kind' => Decision::KIND_LIMIT,
            'current' => 500,
            'proposed' => 1000,
            'accepted' => true,
            'override' => true,
        ]);

        $this->assertSame(DecisionApplier::STATUS_STALE, $status);
        $this->assertSame(777, $config['partial_export']['public']['orders']['limit']);
    }

    public function testCriteriaAccumulateInsteadOfReplacing(): void
    {
        $config = ['partial_export' => ['public' => ['orders' => ['limit' => 500, 'sample' => [
            'criteria' => [['name' => 'open', 'where' => 'status_id = 1', 'limit' => 100]],
        ]]]]];

        $status = $this->apply($config, [
            'table' => 'public.orders',
            'kind' => Decision::KIND_CRITERIA,
            'current' => [['name' => 'open', 'where' => 'status_id = 1', 'limit' => 100]],
            'proposed' => ['name' => 'closed', 'where' => 'status_id = 2', 'limit' => 100],
            'accepted' => true,
        ]);

        $this->assertSame(DecisionApplier::STATUS_APPLIED, $status);
        $criteria = $config['partial_export']['public']['orders']['sample']['criteria'];
        $this->assertCount(2, $criteria);
        $this->assertSame(['open', 'closed'], array_column($criteria, 'name'));
    }

    public function testCriterionWithExistingNameIsSkipped(): void
    {
        $existing = [['name' => 'open', 'where' => 'status_id = 1', 'limit' => 100]];
        $config = ['partial_export' => ['public' => ['orders' => ['sample' => ['criteria' => $existing]]]]];

        $status = $this->apply($config, [
            'table' => 'public.orders',
            'kind' => Decision::KIND_CRITERIA,
            'current' => $existing,
            'proposed' => ['name' => 'open', 'where' => 'status_id = 9', 'limit' => 5],
            'accepted' => true,
        ]);

        $this->assertSame(DecisionApplier::STATUS_SKIPPED_EXISTS, $status);
        $this->assertSame($existing, $config['partial_export']['public']['orders']['sample']['criteria']);
    }

    public function testCascadeFromAcceptsListOfEdges(): void
    {
        $config = ['partial_export' => ['public' => ['orders' => []]]];
        $status = $this->apply($config, [
            'table' => 'public.orders',
            'column' => 'client_id',
            'kind' => Decision::KIND_CASCADE_FROM,
            'current' => null,
            'proposed' => [
                ['parent' => 'public.clients', 'fk_column' => 'client_id', 'parent_column' => 'id'],
                ['parent' => 'public.managers', 'fk_column' => 'manager_id', 'parent_column' => 'id'],
            ],
            'auto' => true,
        ]);

        $this->assertSame(DecisionApplier::STATUS_APPLIED, $status);
        $this->assertCount(2, $config['partial_export']['public']['orders']['cascade_from']);
    }

    public function testStratifyGoesUnderSample(): void
    {
        $config = ['partial_export' => ['clients' => ['clients_attrs' => ['limit' => 1000]]]];
        $status = $this->apply($config, [
            'table' => 'clients.clients_attrs',
            'column' => 'attr_id',
            'kind' => Decision::KIND_STRATIFY,
            'current' => null,
            'proposed' => [['column' => 'attr_id', 'per_value' => 100, 'then' => ['column' => 'value_int']]],
            'accepted' => true,
        ]);

        $this->assertSame(DecisionApplier::STATUS_APPLIED, $status);
        $sample = $config['partial_export']['clients']['clients_attrs']['sample'];
        $this->assertSame('attr_id', $sample['stratify'][0]['column']);
        // limit таблицы не пострадал.
        $this->assertSame(1000, $config['partial_export']['clients']['clients_attrs']['limit']);
    }

    /**
     * Валидация до мутации: битое предложение не должно оставить конфиг полуприменённым.
     */
    public function testInvalidProposalLeavesConfigUntouched(): void
    {
        $config = ['partial_export' => ['public' => ['orders' => ['limit' => 500]]]];
        $before = $config;

        $status = $this->apply($config, [
            'table' => 'public.orders',
            'kind' => Decision::KIND_WHERE,
            'current' => null,
            'proposed' => ['не строка'],
            'accepted' => true,
        ]);

        $this->assertSame(DecisionApplier::STATUS_INVALID, $status);
        $this->assertSame($before, $config);
    }

    public function testModeToFullExportMovesTableBetweenSections(): void
    {
        $config = ['partial_export' => ['public' => ['dict' => []]]];
        $status = $this->apply($config, [
            'table' => 'public.dict',
            'kind' => Decision::KIND_MODE,
            'current' => 'partial_export',
            'proposed' => 'full_export',
            'accepted' => true,
        ]);

        $this->assertSame(DecisionApplier::STATUS_APPLIED, $status);
        $this->assertSame(['dict'], $config['full_export']['public']);
        $this->assertArrayNotHasKey('partial_export', $config);
    }

    /**
     * Перевод в full_export выбрасывает limit/where/sample — без override не трогаем.
     */
    public function testModeToFullExportKeepsConfiguredSelection(): void
    {
        $config = ['partial_export' => ['public' => ['orders' => ['limit' => 10, 'where' => 'active']]]];
        $status = $this->apply($config, [
            'table' => 'public.orders',
            'kind' => Decision::KIND_MODE,
            'current' => 'partial_export',
            'proposed' => 'full_export',
            'accepted' => true,
        ]);

        $this->assertSame(DecisionApplier::STATUS_SKIPPED_EXISTS, $status);
        $this->assertArrayHasKey('orders', $config['partial_export']['public']);
    }

    public function testRemoveTableClearsEverySection(): void
    {
        $config = [
            'full_export' => ['public' => ['ghost']],
            'partial_export' => ['public' => ['ghost' => ['limit' => 1]]],
            'faker' => ['public' => ['ghost' => ['name' => 'fio']]],
        ];

        $status = $this->apply($config, [
            'table' => 'public.ghost',
            'kind' => Decision::KIND_REMOVE_TABLE,
            'current' => null,
            'proposed' => null,
            'auto' => true,
        ]);

        $this->assertSame(DecisionApplier::STATUS_APPLIED, $status);
        $this->assertSame([], $config);
    }

    public function testFakerCanBeRemoved(): void
    {
        $config = ['faker' => ['public' => ['orders' => ['inn' => 'phone', 'name' => 'fio']]]];
        $status = $this->apply($config, [
            'table' => 'public.orders',
            'column' => 'inn',
            'kind' => Decision::KIND_FAKER,
            'current' => 'phone',
            'proposed' => null,
            'accepted' => true,
        ]);

        $this->assertSame(DecisionApplier::STATUS_APPLIED, $status);
        $this->assertSame(['name' => 'fio'], $config['faker']['public']['orders']);
    }

    /**
     * Имена из JSON — недоверенный ввод: они становятся ключами конфига, а те —
     * путями файлов в ConfigSplitter.
     */
    public function testMalformedTableNameIsRejected(): void
    {
        foreach (['../../etc', 'public', 'public.orders.extra', 'public.../x'] as $name) {
            $config = [];
            $status = $this->apply($config, [
                'table' => $name,
                'kind' => Decision::KIND_LIMIT,
                'current' => null,
                'proposed' => 10,
                'auto' => true,
            ]);
            $this->assertSame(DecisionApplier::STATUS_INVALID, $status, $name);
            $this->assertSame([], $config);
        }
    }

    public function testUnknownKindIsReportedNotApplied(): void
    {
        $config = [];
        $status = $this->apply($config, [
            'table' => 'public.orders',
            'kind' => 'partition_by_moon_phase',
            'current' => null,
            'proposed' => 'full',
            'auto' => true,
        ]);

        $this->assertSame(DecisionApplier::STATUS_UNSUPPORTED, $status);
        $this->assertSame([], $config);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $decision
     */
    private function apply(array &$config, array $decision): string
    {
        return $this->applier->apply($config, $decision)['status'];
    }
}
