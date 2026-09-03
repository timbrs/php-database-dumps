<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Analysis\CodeHints;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Service\Analysis\CodeHints\EnumBinder;
use Timbrs\DatabaseDumps\Service\Analysis\CodeHints\UseStatementResolver;

class EnumBinderTest extends TestCase
{
    /**
     * @var array{values: array<string, array<int, string>>, cases: array<string, array<string, string>>, backing: array<string, string>, by_short_name: array<string, array<int, string>>}
     */
    private $enums = [
        'values' => [
            'App\\Enum\\Tasks\\JobTypeEnum' => ['1', '2'],
            'App\\Enum\\Tasks\\ResultIdEnum' => ['1', '2', '-4'],
            'App\\Enum\\Leads\\TypeEnum' => ['a'],
            'App\\Enum\\Tasks\\TypeEnum' => ['b'],
            'App\\Enum\\Clients\\ClientAttrDictEnum' => ['4', '5'],
        ],
        'cases' => [
            'App\\Enum\\Tasks\\JobTypeEnum' => ['READY' => '1', 'DONE' => '2'],
            'App\\Enum\\Tasks\\ResultIdEnum' => ['OK' => '1', 'FAIL' => '2', 'OVERDUE_CLOSED' => '-4'],
        ],
        'backing' => [
            'App\\Enum\\Tasks\\JobTypeEnum' => 'int',
            'App\\Enum\\Tasks\\ResultIdEnum' => 'int',
        ],
        'by_short_name' => [
            'JobTypeEnum' => ['App\\Enum\\Tasks\\JobTypeEnum'],
            'ResultIdEnum' => ['App\\Enum\\Tasks\\ResultIdEnum'],
            'TypeEnum' => ['App\\Enum\\Leads\\TypeEnum', 'App\\Enum\\Tasks\\TypeEnum'],
            'ClientAttrDictEnum' => ['App\\Enum\\Clients\\ClientAttrDictEnum'],
        ],
    ];

    public function testSetterBridgeUsesEntityColumnNames(): void
    {
        $file = $this->file('src/Entity/Tasks/Job.php', <<<'PHP'
<?php
namespace App\Entity\Tasks;

use App\Enum\Tasks\JobTypeEnum;
use Doctrine\ORM\Mapping as ORM;

class Job
{
    #[ORM\Column(name: 'job_type')]
    private ?int $jobType = null;

    public function markReady(): void
    {
        $this->setJobType(JobTypeEnum::READY->value);
    }
}
PHP
        );

        $bound = (new EnumBinder())->bind('tasks', 'jobs', ['id', 'job_type'], [$file], $this->enums);

        self::assertArrayHasKey('job_type', $bound);
        self::assertSame('App\\Enum\\Tasks\\JobTypeEnum', $bound['job_type']['class']);
        self::assertSame('high', $bound['job_type']['confidence']);
        self::assertSame('setter', $bound['job_type']['bridge']);
        self::assertSame('int', $bound['job_type']['backing']);
        self::assertSame(['READY' => '1', 'DONE' => '2'], $bound['job_type']['cases']);
        self::assertSame('src/Entity/Tasks/Job.php', $bound['job_type']['evidence'][0]['file']);
    }

    public function testDqlBridgePairsParameterWithAliasedProperty(): void
    {
        $file = $this->file('src/Repository/JobRepository.php', <<<'PHP'
<?php
namespace App\Repository;

use App\Enum\Tasks\ResultIdEnum;

class JobRepository
{
    public function overdue()
    {
        return $this->createQueryBuilder('j')
            ->andWhere('j.resultId = :result')
            ->setParameter('result', ResultIdEnum::OVERDUE_CLOSED)
            ->getQuery();
    }
}
PHP
        );

        $bound = (new EnumBinder())->bind('tasks', 'jobs', ['result_id'], [$file], $this->enums);

        self::assertSame('App\\Enum\\Tasks\\ResultIdEnum', $bound['result_id']['class']);
        self::assertSame('high', $bound['result_id']['confidence']);
        self::assertSame('dql', $bound['result_id']['bridge']);
        // Именно этот case не попал в ручной конфиг — теперь он виден.
        self::assertArrayHasKey('OVERDUE_CLOSED', $bound['result_id']['cases']);
    }

    public function testRawSqlBridgeTakesColumnFromTheQueryText(): void
    {
        $file = $this->file('src/Command/Sync.php', <<<'PHP'
<?php
namespace App\Command;

use App\Enum\Tasks\JobTypeEnum;

class Sync
{
    public function sql(): string
    {
        return 'SELECT * FROM tasks.jobs t1 WHERE t1.job_type = ' . JobTypeEnum::READY->value;
    }
}
PHP
        );

        $bound = (new EnumBinder())->bind('tasks', 'jobs', ['job_type'], [$file], $this->enums);

        self::assertSame('App\\Enum\\Tasks\\JobTypeEnum', $bound['job_type']['class']);
        self::assertSame('raw_sql', $bound['job_type']['bridge']);
        self::assertSame('med', $bound['job_type']['confidence']);
    }

    public function testConventionBindsEavAttributeDictionary(): void
    {
        $bound = (new EnumBinder())->bind('clients', 'clients_attrs', ['attr_id', 'value_int'], [], $this->enums);

        self::assertSame('App\\Enum\\Clients\\ClientAttrDictEnum', $bound['attr_id']['class']);
        self::assertSame('convention', $bound['attr_id']['bridge']);
        self::assertSame('low', $bound['attr_id']['confidence']);
        self::assertArrayNotHasKey('value_int', $bound);
    }

    public function testConventionForIdColumnsRequiresMatchingDomain(): void
    {
        // tasks.jobs.result_id ↔ App\Enum\Tasks\ResultIdEnum — домен совпал.
        $tasks = (new EnumBinder())->bind('tasks', 'jobs', ['result_id'], [], $this->enums);
        self::assertArrayHasKey('result_id', $tasks);

        // Такая же колонка в чужой схеме этот enum не притягивает.
        $leads = (new EnumBinder())->bind('leads', 'leads', ['result_id'], [], $this->enums);
        self::assertSame([], $leads);
    }

    public function testCodesFromStatisticsRaiseConventionConfidence(): void
    {
        $bound = (new EnumBinder())->bind('clients', 'clients_attrs', ['attr_id'], [], $this->enums, ['attr_id' => ['4', '5', '6']]);

        self::assertSame('med', $bound['attr_id']['confidence']);
        self::assertSame('convention+codes', $bound['attr_id']['bridge']);
    }

    public function testSeveralCandidatesAreMarkedAmbiguousWithAlternatives(): void
    {
        $file = $this->file('src/Entity/Leads/Lead.php', <<<'PHP'
<?php
namespace App\Entity\Leads;

use App\Enum\Leads\TypeEnum;
use Doctrine\ORM\Mapping as ORM;

class Lead
{
    #[ORM\Column(name: 'type')]
    private ?string $type = null;

    public function init(): void
    {
        $this->setType(TypeEnum::A->value);
    }
}
PHP
        );
        $other = $this->file('src/Service/Mixer.php', <<<'PHP'
<?php
namespace App\Service;

use App\Enum\Tasks\TypeEnum;

class Mixer
{
    public function sql(): string
    {
        return 'SELECT * FROM leads.leads WHERE type = ' . TypeEnum::B->value;
    }
}
PHP
        );

        $bound = (new EnumBinder())->bind('leads', 'leads', ['type'], [$file, $other], $this->enums);

        self::assertSame('App\\Enum\\Leads\\TypeEnum', $bound['type']['class'], 'выигрывает мост с большей уверенностью');
        self::assertTrue($bound['type']['ambiguous']);
        self::assertSame('App\\Enum\\Tasks\\TypeEnum', $bound['type']['alternatives'][0]['class']);
    }

    public function testUseStatementResolverHandlesAliasesAndLeadingSlash(): void
    {
        $imports = UseStatementResolver::imports("<?php\nnamespace App\\Service;\nuse App\\Enum\\Tasks\\TypeEnum as TaskType;\n");

        self::assertSame('App\\Enum\\Tasks\\TypeEnum', UseStatementResolver::resolve('TaskType', $imports));
        self::assertSame('App\\Service\\Helper', UseStatementResolver::resolve('Helper', $imports));
        self::assertSame('Other\\Thing', UseStatementResolver::resolve('\\Other\\Thing', $imports));
    }

    /**
     * @return array{rel: string, content: string, lines: array<int, string>}
     */
    private function file(string $rel, string $content): array
    {
        return ['rel' => $rel, 'content' => $content, 'lines' => explode("\n", $content)];
    }
}
