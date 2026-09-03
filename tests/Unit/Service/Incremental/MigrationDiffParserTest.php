<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Incremental;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Service\Analysis\Dossier\MigrationScanner;
use Timbrs\DatabaseDumps\Service\Incremental\MigrationDiffParser;

class MigrationDiffParserTest extends TestCase
{
    public function testVersionsSinceSkipsOlderAndTheBoundaryItself(): void
    {
        $parser = $this->parser();

        $this->assertSame(
            ['Version20250301000000', 'Version20250601000000'],
            $parser->versionsSince('Version20250101000000')
        );
        // Граница исключается: отметка означает «эта версия уже проверена».
        $this->assertSame(['Version20250601000000'], $parser->versionsSince('Version20250301000000'));
        $this->assertSame([], $parser->versionsSince('Version20250601000000'));
    }

    public function testNullSinceMeansEverything(): void
    {
        $this->assertCount(3, $this->parser()->versionsSince(null));
    }

    public function testNewestVersionIsTheLastOne(): void
    {
        $this->assertSame('Version20250601000000', $this->parser()->newestVersion());
    }

    /**
     * `ddl` и `dml_rows` считаются только по новым миграциям: иначе в причине оказалось бы
     * «наполняется миграцией» из-за миграции трёхлетней давности.
     */
    public function testFactsCountOnlyMigrationsAfterTheCheckpoint(): void
    {
        $changed = $this->parser()->tablesChangedSince('Version20250101000000');

        $this->assertSame(['public.clients', 'public.dict'], array_keys($changed));
        $this->assertSame(['Version20250301000000'], $changed['public.clients']['versions']);
        $this->assertSame(0, $changed['public.clients']['dml_rows']);
        $this->assertSame(3, $changed['public.dict']['dml_rows']);
        $this->assertSame('Наполнение словаря', $changed['public.dict']['description']);
    }

    public function testTableTouchedTwiceCollectsBothVersions(): void
    {
        $changed = $this->parser()->tablesChangedSince(null);

        $this->assertSame(
            ['Version20250101000000', 'Version20250301000000'],
            $changed['public.clients']['versions']
        );
        $this->assertContains('create_table', $changed['public.clients']['ddl']);
        $this->assertContains('alter_table', $changed['public.clients']['ddl']);
    }

    /**
     * git-сенсор отдаёт пути файлов — сопоставлять их с таблицами умеет только разбор.
     */
    public function testFilePathsMapToVersionsAndTables(): void
    {
        $parser = $this->parser();
        $versions = $parser->versionsOfFiles([
            'migrations/2025/03/Version20250301000000.php',
            'README.md',
            'migrations/Version99999999999999.php',
        ]);

        $this->assertSame(['Version20250301000000'], $versions);
        $this->assertSame(['public.clients'], $parser->tablesOfVersions($versions));
        $this->assertSame([], $parser->tablesOfVersions([]));
    }

    private function parser(): MigrationDiffParser
    {
        return new MigrationDiffParser($this->scanner([
            'Version20250101000000' => "<?php\n\$this->addSql('CREATE TABLE public.clients (id int)');\n",
            'Version20250301000000' => "<?php\n\$this->addSql('ALTER TABLE public.clients ADD COLUMN c int');\n",
            'Version20250601000000' => "<?php\n"
                . "public function getDescription(): string { return 'Наполнение словаря'; }\n"
                . "\$this->addSql('INSERT INTO public.dict (id, name) VALUES (1, %s), (2, %s), (3, %s)');\n",
        ]));
    }

    /**
     * @param array<string, string> $migrations
     */
    private function scanner(array $migrations): MigrationScanner
    {
        return new class('/proj', $migrations) extends MigrationScanner {
            /** @var array<string, string> */
            private $migrations;

            /**
             * @param array<string, string> $migrations
             */
            public function __construct(string $dir, array $migrations)
            {
                parent::__construct($dir);
                $this->migrations = $migrations;
            }

            protected function files(): array
            {
                $files = [];
                foreach (array_keys($this->migrations) as $version) {
                    $files[] = ['path' => '/proj/migrations/' . $version . '.php', 'name' => $version];
                }

                return $files;
            }

            protected function read(string $path): ?string
            {
                $version = basename($path, '.php');

                return isset($this->migrations[$version]) ? $this->migrations[$version] : null;
            }
        };
    }
}
