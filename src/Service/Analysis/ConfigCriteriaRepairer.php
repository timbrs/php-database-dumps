<?php

namespace Timbrs\DatabaseDumps\Service\Analysis;

use Timbrs\DatabaseDumps\Contract\ConfigLoaderInterface;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;

/**
 * Режим repair-configs: пройтись по УЖЕ сгенерированному dump_config.yaml (+ dump-settings/*.yaml),
 * прогнать каждый sample.criterion в БД (как дампер) и точечно доисправить только упавшие — через
 * тот же цикл перепромпта opencode, что и prepare-analysis --run, но БЕЗ полного пересбора инвентаря
 * и скана кода. Полезно, когда yaml почти готовы и надо лишь починить несколько битых criteria.
 *
 * Поток: load config → CriteriaSqlTester по каждому criterion → падающие засеваем в out/<schema>.json →
 * AnalysisRepairLoop (перепромпт opencode с реальной ошибкой) → AnalysisIngestor → ConfigEnricher
 * (self-heal: исправленный criterion заменяет битый одноимённый). Экспорт и так не падает на битых
 * (SampleQueryBuilder их пропускает), это — чтобы довести criteria до рабочего вида.
 */
class ConfigCriteriaRepairer
{
    /** @var FileSystemInterface */
    private $fileSystem;

    /** @var ConfigLoaderInterface */
    private $configLoader;

    /** @var CriteriaSqlTester */
    private $sqlTester;

    /** @var OpencodeRunner */
    private $runner;

    /** @var AnalysisRepairLoop */
    private $repairLoop;

    /** @var AnalysisIngestor */
    private $ingestor;

    /** @var ConfigEnricher */
    private $enricher;

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
        OpencodeRunner $runner,
        AnalysisRepairLoop $repairLoop,
        AnalysisIngestor $ingestor,
        ConfigEnricher $enricher,
        LoggerInterface $logger,
        string $projectDir,
        DbdumpConfigStore $store
    ) {
        $this->fileSystem = $fileSystem;
        $this->configLoader = $configLoader;
        $this->sqlTester = $sqlTester;
        $this->runner = $runner;
        $this->repairLoop = $repairLoop;
        $this->ingestor = $ingestor;
        $this->enricher = $enricher;
        $this->logger = $logger;
        $this->projectDir = rtrim($projectDir, '/\\');
        $this->store = $store;
    }

    /**
     * @param int $maxAttempts корректирующих перепрогонов на схему; 0 — только проверка (без починки)
     *
     * @return array{tested:int, failing:int, schemas:int, cascade_added:int, criteria_added:int, repaired:bool}
     */
    public function repair(string $configPath, int $maxAttempts, ?string $connectionName): array
    {
        $config = $this->configLoader->load($configPath);
        $dataDir = $this->store->getDataDir($this->projectDir);
        $outDir = $this->projectDir . '/' . $dataDir . '/' . AnalysisPackageBuilder::OUT_DIR;

        // 1. Прогнать каждый criterion в БД, собрать падающие по схемам.
        $tested = 0;
        $failing = 0;
        /** @var array<string, array<int, array{table: string, name: string, sql_where: string}>> $failingBySchema */
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
                        $this->logger->info(sprintf('  <comment>%s.%s</comment> / %s — падает: %s', $schema, (string) $table, $name, $error));
                        $failingBySchema[$schema][] = [
                            'table' => $schema . '.' . (string) $table,
                            'name' => $name,
                            'sql_where' => $where,
                        ];
                    }
                }
            }
        }

        $result = [
            'tested' => $tested, 'failing' => $failing, 'schemas' => count($failingBySchema),
            'cascade_added' => 0, 'criteria_added' => 0, 'repaired' => false,
        ];

        if (empty($failingBySchema)) {
            $this->logger->info(sprintf('Проверено criteria: %d — все исполняются в БД.', $tested));
            return $result;
        }

        // Только проверка (0 попыток) или нет opencode — отчёт без починки.
        if ($maxAttempts < 1 || !$this->runner->isAvailable()) {
            $reason = $maxAttempts < 1 ? 'проверка без починки (--repair-attempts=0)' : 'opencode недоступен';
            $this->logger->warning(sprintf(
                'Падающих criteria: %d в %d схемах — %s. Экспорт их пропустит; для авто-починки нужен opencode.',
                $failing,
                count($failingBySchema),
                $reason
            ));
            return $result;
        }

        // 2. Засеять out/<schema>.json падающими criteria (агентский формат) и прогнать repair-цикл.
        if (!$this->fileSystem->exists($outDir)) {
            $this->fileSystem->createDirectory($outDir);
        }
        $schemaFiles = [];
        foreach ($failingBySchema as $schema => $crits) {
            $json = json_encode(['criteria' => $crits], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->fileSystem->write($outDir . '/' . $schema . '.json', $json === false ? '{"criteria":[]}' : $json);
            $schemaFiles[$schema] = $this->projectDir . '/' . $dataDir . '/'
                . AnalysisPackageBuilder::ANALYSIS_DIR . '/schema_inventory.' . $schema . '.json';
        }

        $this->logger->info(sprintf('Починка %d падающих criteria в %d схемах…', $failing, count($failingBySchema)));
        $this->repairLoop->run($dataDir, $schemaFiles, $maxAttempts, $connectionName);

        // 3. Поглотить исправленные + обогатить конфиг (self-heal заменит битые одноимённые).
        $ingested = $this->ingestor->ingest($outDir);
        $stats = $this->enricher->enrich($configPath, $ingested);

        $result['cascade_added'] = $stats['cascade_added'];
        $result['criteria_added'] = $stats['criteria_added'];
        $result['repaired'] = true;

        return $result;
    }
}
