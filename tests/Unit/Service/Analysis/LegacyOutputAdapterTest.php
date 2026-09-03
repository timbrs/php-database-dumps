<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Analysis;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\Decision;
use Timbrs\DatabaseDumps\Service\Analysis\LegacyOutputAdapter;

class LegacyOutputAdapterTest extends TestCase
{
    /** @var LegacyOutputAdapter */
    private $adapter;

    protected function setUp(): void
    {
        $this->adapter = new LegacyOutputAdapter();
    }

    public function testCascadeEdgeBecomesDecision(): void
    {
        $decisions = $this->adapter->toDecisions([
            'cascade_from' => [[
                'schema' => 'clients',
                'table' => 'clients_attrs',
                'parent' => 'clients.clients',
                'fk_column' => 'core_id',
                'parent_column' => 'core_id',
                'source' => 'code',
                'confidence' => 90,
                'kind' => 'belongs_to',
            ]],
        ]);

        $this->assertCount(1, $decisions);
        $entry = $decisions[0]->toArray();
        $this->assertSame('clients.clients_attrs', $entry['table']);
        $this->assertSame('core_id', $entry['column']);
        $this->assertSame(Decision::KIND_CASCADE_FROM, $entry['kind']);
        $this->assertSame(LegacyOutputAdapter::RULE, $entry['rule']);
        $this->assertSame('high', $entry['confidence']);
        $this->assertSame('clients.clients', $entry['proposed']['parent']);
        $this->assertSame(Decision::SOURCE_AGENT, $entry['evidence'][0]['source']);
    }

    /**
     * Старый контракт не различал механическое и меняющее состав выборки —
     * применять его вывод без подтверждения нельзя.
     */
    public function testLegacyDecisionsAreNeverAuto(): void
    {
        $package = $this->adapter->toPackage([
            'cascade_from' => [[
                'schema' => 's',
                'table' => 't',
                'parent' => 's.p',
                'fk_column' => 'p_id',
                'parent_column' => 'id',
            ]],
            'sample_criteria' => [[
                'schema' => 's',
                'table' => 't',
                'name' => 'active',
                'where' => 'active_flg = true',
                'limit' => 50,
                'confidence' => 40,
            ]],
        ], 's');

        $this->assertSame(0, $package['summary']['auto']);
        $this->assertSame(2, $package['summary']['needs_review']);
        $this->assertSame(['legacy' => 2], $package['summary']['by_rule']);
        $this->assertSame(['cascade_from' => 1, 'criteria' => 1], $package['summary']['by_kind']);
        foreach ($package['decisions'] as $entry) {
            $this->assertFalse($entry['auto']);
        }
    }

    public function testCriterionKeepsLimitAndDowngradesConfidence(): void
    {
        $decisions = $this->adapter->toDecisions([
            'sample_criteria' => [[
                'schema' => 'tasks',
                'table' => 'activities',
                'name' => 'overdue',
                'where' => 'result_id = -4',
                'limit' => 100,
                'confidence' => 40,
            ]],
        ]);

        $entry = $decisions[0]->toArray();
        $this->assertSame(Decision::KIND_CRITERIA, $entry['kind']);
        $this->assertNull($entry['column']);
        $this->assertSame('low', $entry['confidence']);
        $this->assertSame(
            ['name' => 'overdue', 'where' => 'result_id = -4', 'limit' => 100],
            $entry['proposed']
        );
    }

    public function testIncompleteEntriesAreSkipped(): void
    {
        $decisions = $this->adapter->toDecisions([
            'cascade_from' => [
                ['schema' => 's', 'table' => 't', 'parent' => '', 'fk_column' => 'a', 'parent_column' => 'b'],
                'мусор',
            ],
            'sample_criteria' => [
                ['schema' => 's', 'table' => 't', 'name' => 'x', 'where' => ''],
            ],
        ]);

        $this->assertSame([], $decisions);
    }

    /**
     * columns[] в конфиг выгрузки не идут — раньше их просто выбрасывали,
     * теперь они оседают заметками в досье.
     */
    public function testColumnsBecomeDossierAnnotations(): void
    {
        $annotations = $this->adapter->toAnnotations([
            'columns' => [
                [
                    'table' => 'tasks.activities',
                    'column' => 'result_id',
                    'usages' => ['filter', 'read', 'filter'],
                    'is_key' => true,
                    'note' => 'статус завершения',
                ],
                ['table' => '', 'column' => 'x'],
            ],
        ]);

        $this->assertSame(['tasks.activities'], array_keys($annotations));
        $note = $annotations['tasks.activities']['result_id'];
        $this->assertSame(['filter', 'read'], $note['usages']);
        $this->assertTrue($note['is_key']);
        $this->assertSame('статус завершения', $note['agent_note']);
        $this->assertSame(Decision::SOURCE_AGENT, $note['source']);
    }

    public function testEmptyInputGivesEmptyPackage(): void
    {
        $package = $this->adapter->toPackage([]);

        $this->assertSame(0, $package['summary']['total']);
        $this->assertSame([], $package['summary']['by_rule']);
        $this->assertSame([], $package['decisions']);
        $this->assertSame([], $this->adapter->toAnnotations([]));
    }
}
