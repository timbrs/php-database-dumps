<?php

namespace Timbrs\DatabaseDumps\Service\Analysis;

use Timbrs\DatabaseDumps\Contract\ConfigLoaderInterface;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;

/**
 * Режим repair-configs: пройтись по УЖЕ сгенерированному dump_config.yaml (+ dump-settings/*.yaml)
 * и прогнать каждый sample.criterion в БД ровно так, как это делает дампер.
 *
 * Команда ДИАГНОСТИРУЕТ, а не чинит. Раньше она пыталась чинить сама — перепромптом внешнего
 * агента через exec() из PHP, — и это оказалось не тем разделением труда: PHP хорош ровно в той
 * части, где ответ детерминирован (какой criterion падает и с какой ошибкой от БД), а решение
 * «как переписать WHERE» требует чтения кода, сущностей и энумов. Теперь первое остаётся здесь,
 * второе делает агент снаружи, читая отчёт этой команды.
 *
 * Поток: load config → CriteriaSqlTester по каждому criterion → падающие группируются по схемам
 * и пишутся в {data_dir}/analysis/failing-criteria.json вместе с текстом ошибки СУБД.
 *
 * Экспорт на битых criteria не падает и без этого (SampleQueryBuilder их пропускает) — отчёт
 * нужен, чтобы довести criteria до рабочего вида, а не чтобы спасти прогон.
 */
class ConfigCriteriaRepairer
{
    /** Файл отчёта относительно каталога анализа. */
    public const REPORT_FILE = 'failing-criteria.json';

    /** @var FileSystemInterface */
    private $fileSystem;

    /** @var ConfigLoaderInterface */
    private $configLoader;

    /** @var CriteriaSqlTester */
    private $sqlTester;

    /** @var LoggerInterface */
    private $logger;

    /** @var string */
    private $projectDir;

    /** @var DbdumpConfigStore */
    private $store;

    public function __construct(
        FileSystemInterface $fileSystem,
        ConfigLoaderInterface $configLoader,
        CriteriaSqlTester $sqlTester,
        LoggerInterface $logger,
        string $projectDir,
        DbdumpConfigStore $store
    ) {
        $this->fileSystem = $fileSystem;
        $this->configLoader = $configLoader;
        $this->sqlTester = $sqlTester;
        $this->logger = $logger;
        $this->projectDir = rtrim($projectDir, '/\\');
        $this->store = $store;
    }

    /**
     * Прогнать все criteria в БД и записать отчёт о падающих.
     *
     * @return array{tested:int, failing:int, schemas:int, report:string|null}
     */
    public function inspect(string $configPath, ?string $connectionName): array
    {
        $config = $this->configLoader->load($configPath);

        $tested = 0;
        $failing = 0;
        /** @var array<string, array<int, array{table:string, name:string, sql_where:string, error:string}>> $failingBySchema */
        $failingBySchema = [];

        foreach ($config->getAllPartialExportSchemas() as $schema) {
            foreach ($config->getPartialExportTables($schema) as $table => $tableConf) {
                if (!is_array($tableConf)) {
                    continue;
                }
                $criteria = isset($tableConf['sample']['criteria']) && is_array($tableConf['sample']['criteria'])
                    ? $tableConf['sample']['criteria']
                    : [];
                foreach ($criteria as $crit) {
                    if (!is_array($crit) || !isset($crit['where'], $crit['name'])) {
                        continue;
                    }
                    $tested++;
                    $where = (string) $crit['where'];
                    $name = (string) $crit['name'];
                    $error = $this->sqlTester->test($schema, (string) $table, $where, $connectionName);
                    if ($error !== null) {
                        $failing++;
                        $this->logger->info(sprintf(
                            '  <comment>%s.%s</comment> / %s — падает: %s',
                            $schema,
                            (string) $table,
                            $name,
                            $error
                        ));
                        $failingBySchema[$schema][] = [
                            'table' => $schema . '.' . (string) $table,
                            'name' => $name,
                            'sql_where' => $where,
                            // Текст ошибки СУБД — самое ценное здесь: по нему видно, колонки нет,
                            // тип не сошёлся или синтаксис битый. Без него агент гадает.
                            'error' => $error,
                        ];
                    }
                }
            }
        }

        if ($failing === 0) {
            $this->logger->info(sprintf('Проверено criteria: %d — все исполняются в БД.', $tested));

            return ['tested' => $tested, 'failing' => 0, 'schemas' => 0, 'report' => null];
        }

        $report = $this->writeReport($configPath, $failingBySchema, $tested, $failing);

        return [
            'tested' => $tested,
            'failing' => $failing,
            'schemas' => count($failingBySchema),
            'report' => $report,
        ];
    }

    /**
     * @param array<string, array<int, array{table:string, name:string, sql_where:string, error:string}>> $failingBySchema
     */
    private function writeReport(string $configPath, array $failingBySchema, int $tested, int $failing): ?string
    {
        $analysisDir = $this->projectDir . '/' . $this->store->getDataDir($this->projectDir)
            . '/' . AnalysisPackageBuilder::ANALYSIS_DIR;
        if (!$this->fileSystem->exists($analysisDir)) {
            $this->fileSystem->createDirectory($analysisDir);
        }

        $path = $analysisDir . '/' . self::REPORT_FILE;
        $payload = [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'config_path' => $configPath,
            'tested' => $tested,
            'failing' => $failing,
            'schemas' => $failingBySchema,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->logger->warning('Не удалось сериализовать отчёт о падающих criteria.');

            return null;
        }

        $this->fileSystem->write($path, $json);

        return $path;
    }
}
