<?php

namespace Timbrs\DatabaseDumps\Bridge\Laravel;

use Illuminate\Support\ServiceProvider;
use Timbrs\DatabaseDumps\Adapter\LaravelDatabaseAdapter;
use Timbrs\DatabaseDumps\Bridge\Laravel\Command\ApplyAnalysisCommand;
use Timbrs\DatabaseDumps\Bridge\Laravel\Command\RepairConfigsCommand;
use Timbrs\DatabaseDumps\Bridge\Laravel\Command\ValidateConfigCommand;
use Timbrs\DatabaseDumps\Bridge\Laravel\Command\ConfigureLlmCommand;
use Timbrs\DatabaseDumps\Bridge\Laravel\Command\DbInitCommand;
use Timbrs\DatabaseDumps\Bridge\Laravel\Command\DumpExportCommand;
use Timbrs\DatabaseDumps\Bridge\Laravel\Command\PrepareAnalysisCommand;
use Timbrs\DatabaseDumps\Bridge\Laravel\Command\PrepareConfigCommand;
use Timbrs\DatabaseDumps\Config\AiConfig;
use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\EnvironmentConfig;
use Timbrs\DatabaseDumps\Contract\AiClientInterface;
use Timbrs\DatabaseDumps\Contract\ConfigLoaderInterface;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Contract\DatabasePlatformInterface;
use Timbrs\DatabaseDumps\Contract\FakerInterface;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\HttpTransportInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;
use Timbrs\DatabaseDumps\Service\Ai\AiClientFactory;
use Timbrs\DatabaseDumps\Service\Ai\CurlHttpTransport;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;
use Timbrs\DatabaseDumps\Service\Analysis\AnalysisIngestor;
use Timbrs\DatabaseDumps\Service\Analysis\CriteriaSqlTester;
use Timbrs\DatabaseDumps\Service\Analysis\AnalysisPackageBuilder;
use Timbrs\DatabaseDumps\Service\Analysis\AnalysisReportWriter;
use Timbrs\DatabaseDumps\Service\Analysis\CodeHintScanner;
use Timbrs\DatabaseDumps\Service\Analysis\ConfigCriteriaRepairer;
use Timbrs\DatabaseDumps\Service\Analysis\ConfigEnricher;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ColumnStatisticsInspector;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ConfigGenerator;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ConfigSplitter;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\CriteriaSuggester;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ForeignKeyInspector;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ModeParser;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\RegenerationGuard;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ServiceTableFilter;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\TableInspector;
use Timbrs\DatabaseDumps\Service\ConnectionRegistry;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\PrimaryKeyInspector;
use Timbrs\DatabaseDumps\Service\Db\PgStatsReader;
use Timbrs\DatabaseDumps\Service\Db\RowCounter;
use Timbrs\DatabaseDumps\Service\Db\SafeQueryPolicy;
use Timbrs\DatabaseDumps\Service\Dumper\CascadeWhereResolver;
use Timbrs\DatabaseDumps\Service\Dumper\DatabaseDumper;
use Timbrs\DatabaseDumps\Service\Dumper\DataFetcher;
use Timbrs\DatabaseDumps\Service\Dumper\SampleQueryBuilder;
use Timbrs\DatabaseDumps\Service\Dumper\SelectedPkRegistry;
use Timbrs\DatabaseDumps\Service\Dumper\TableConfigResolver;
use Timbrs\DatabaseDumps\Service\Faker\LlmPatternDetector;
use Timbrs\DatabaseDumps\Service\Faker\PatternDetector;
use Timbrs\DatabaseDumps\Service\Faker\RussianFaker;
use Timbrs\DatabaseDumps\Service\Generator\DeferredUpdateGenerator;
use Timbrs\DatabaseDumps\Service\Generator\InsertGenerator;
use Timbrs\DatabaseDumps\Service\Generator\SequenceGenerator;
use Timbrs\DatabaseDumps\Service\Generator\SqlGenerator;
use Timbrs\DatabaseDumps\Service\Generator\TruncateGenerator;
use Timbrs\DatabaseDumps\Service\Graph\TableDependencyResolver;
use Timbrs\DatabaseDumps\Service\Graph\TopologicalSorter;
use Timbrs\DatabaseDumps\Service\Importer\DatabaseImporter;
use Timbrs\DatabaseDumps\Service\Importer\SchemaValidator;
use Timbrs\DatabaseDumps\Service\Importer\ScriptExecutor;
use Timbrs\DatabaseDumps\Service\Importer\TransactionManager;
use Timbrs\DatabaseDumps\Service\Parser\SqlParser;
use Timbrs\DatabaseDumps\Service\Parser\StatementSplitter;
use Timbrs\DatabaseDumps\Service\Security\ProductionGuard;
use Timbrs\DatabaseDumps\Service\Verification\DumpValueReader;
use Timbrs\DatabaseDumps\Util\EnvFileWriter;
use Timbrs\DatabaseDumps\Service\Validation\AuditFixer;
use Timbrs\DatabaseDumps\Service\Validation\ConfigAuditor;
use Timbrs\DatabaseDumps\Service\Validation\JsonReportWriter;
use Timbrs\DatabaseDumps\Util\FileSystemHelper;
use Timbrs\DatabaseDumps\Util\YamlConfigLoader;

/**
 * Laravel ServiceProvider для timbrs/database-dumps.
 *
 * Изменения:
 *  - DumpConfig зарегистрирован как bind() (НЕ singleton) — после prepare-config
 *    последующие команды получат обновлённый конфиг.
 *  - LaravelLogger пишет в Log facade (не молча теряет логи).
 *  - DatabaseDumper получает ProductionGuard (защита от утечки PII с prod).
 *  - ensureDumpConfigExists вызывается только в runningInConsole.
 *  - ConnectionRegistry формируется аккуратно: при отсутствии подключения
 *    выводится понятное сообщение.
 */
class DatabaseDumpsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/database-dumps.php', 'database-dumps');

        $this->app->singleton(FileSystemInterface::class, FileSystemHelper::class);
        $this->app->singleton(ConfigLoaderInterface::class, YamlConfigLoader::class);

        $this->app->singleton(LoggerInterface::class, function () {
            return new LaravelLogger();
        });

        $this->app->singleton(EnvironmentConfig::class, function () {
            return EnvironmentConfig::fromEnv();
        });

        // bind (не singleton) — чтобы после prepare-config конфиг обновлялся
        $this->app->bind(DumpConfig::class, function ($app) {
            $configPath = $app['config']->get('database-dumps.config_path');

            if (!file_exists($configPath)) {
                return new DumpConfig([], []);
            }

            /** @var ConfigLoaderInterface $loader */
            $loader = $app->make(ConfigLoaderInterface::class);
            return $loader->load($configPath);
        });

        // Обратная совместимость: connection/platform для legacy-кода
        $this->app->singleton(DatabaseConnectionInterface::class, function ($app) {
            return new LaravelDatabaseAdapter($app['db']->connection());
        });

        $this->app->singleton(DatabasePlatformInterface::class, function ($app) {
            /** @var DatabaseConnectionInterface $connection */
            $connection = $app->make(DatabaseConnectionInterface::class);
            return PlatformFactory::create($connection->getPlatformName(), $app->make(LoggerInterface::class));
        });

        // Бережный доступ к БД: один разделяемый экземпляр — профиль переключают дампер и импортёр.
        $this->app->singleton(SafeQueryPolicy::class, function ($app) {
            $settings = $app['config']->get('database-dumps.db', []);
            return new SafeQueryPolicy(is_array($settings) ? $settings : []);
        });
        $this->app->singleton(PgStatsReader::class, function ($app) {
            return new PgStatsReader($app->make(ConnectionRegistryInterface::class));
        });
        $this->app->singleton(RowCounter::class, function ($app) {
            return new RowCounter($app->make(TableInspector::class), $app->make(SafeQueryPolicy::class));
        });

        $this->app->singleton(ConnectionRegistryInterface::class, function ($app) {
            $registry = new ConnectionRegistry(
                'default',
                $app->make(SafeQueryPolicy::class),
                $app->make(LoggerInterface::class)
            );
            $registry->register('default', new LaravelDatabaseAdapter($app['db']->connection()));

            /** @var DumpConfig $dumpConfig */
            $dumpConfig = $app->make(DumpConfig::class);
            foreach (array_keys($dumpConfig->getConnectionConfigs()) as $connName) {
                try {
                    $registry->register($connName, new LaravelDatabaseAdapter(
                        $app['db']->connection($connName)
                    ));
                } catch (\Throwable $e) {
                    throw new \RuntimeException(sprintf(
                        'Connection "%s" из dump_config.yaml не найдено в config/database.php. '
                        . 'Зарегистрируйте подключение в Laravel-конфиге.',
                        $connName
                    ), 0, $e);
                }
            }
            return $registry;
        });

        // ProductionGuard теперь принимает EnvironmentConfig напрямую (EnvironmentChecker удалён)
        $this->app->singleton(ProductionGuard::class, function ($app) {
            return new ProductionGuard($app->make(EnvironmentConfig::class));
        });

        $this->app->singleton(StatementSplitter::class);
        $this->app->singleton(SqlParser::class);
        $this->app->singleton(TransactionManager::class);
        $this->app->singleton(ScriptExecutor::class);
        $this->app->singleton(TableConfigResolver::class);
        $this->app->singleton(ModeParser::class);
        $this->app->singleton(RegenerationGuard::class, function ($app) {
            return new RegenerationGuard($app->make(FileSystemInterface::class));
        });

        $this->app->singleton(TruncateGenerator::class);
        $this->app->singleton(InsertGenerator::class, function ($app) {
            /** @var DumpConfig $dumpConfig */
            $dumpConfig = $app->make(DumpConfig::class);
            return new InsertGenerator(
                $app->make(ConnectionRegistryInterface::class),
                $dumpConfig->getBatchSize()
            );
        });
        $this->app->singleton(SequenceGenerator::class);
        $this->app->singleton(DeferredUpdateGenerator::class);
        $this->app->singleton(SqlGenerator::class, function ($app) {
            return new SqlGenerator(
                $app->make(TruncateGenerator::class),
                $app->make(InsertGenerator::class),
                $app->make(SequenceGenerator::class),
                $app->make(DeferredUpdateGenerator::class)
            );
        });

        // Реестр выбранных PK — один общий инстанс на запрос (cascade-консистентность).
        $this->app->singleton(SelectedPkRegistry::class);
        $this->app->singleton(PrimaryKeyInspector::class);
        $this->app->singleton(SampleQueryBuilder::class, function ($app) {
            return new SampleQueryBuilder(
                $app->make(ConnectionRegistryInterface::class),
                $app->make(PrimaryKeyInspector::class),
                $app->make(SelectedPkRegistry::class),
                $app->make(LoggerInterface::class)
            );
        });

        $this->app->singleton(DataFetcher::class, function ($app) {
            return new DataFetcher(
                $app->make(ConnectionRegistryInterface::class),
                $app->make(CascadeWhereResolver::class),
                $app->make(DumpConfig::class),
                $app->make(SampleQueryBuilder::class)
            );
        });

        $this->app->singleton(ServiceTableFilter::class);
        $this->app->singleton(TableInspector::class, function ($app) {
            return new TableInspector(
                $app->make(ConnectionRegistryInterface::class),
                $app->make(PgStatsReader::class)
            );
        });
        $this->app->singleton(ForeignKeyInspector::class);
        $this->app->singleton(TopologicalSorter::class);
        $this->app->singleton(TableDependencyResolver::class);
        $this->app->singleton(CascadeWhereResolver::class, function ($app) {
            /** @var DumpConfig $dumpConfig */
            $dumpConfig = $app->make(DumpConfig::class);
            return new CascadeWhereResolver(
                $app->make(ConnectionRegistryInterface::class),
                $dumpConfig->getMaxCascadeDepth(),
                $app->make(LoggerInterface::class),
                $app->make(SelectedPkRegistry::class)
            );
        });
        $this->app->singleton(PatternDetector::class, function ($app) {
            /** @var DumpConfig $dumpConfig */
            $dumpConfig = $app->make(DumpConfig::class);
            return new PatternDetector(
                $app->make(ConnectionRegistryInterface::class),
                $dumpConfig->getSampleSize(),
                $app->make(SafeQueryPolicy::class)
            );
        });

        // Единое хранилище настроек (data_dir + LLM) + запись токена в .env
        $this->app->singleton(DbdumpConfigStore::class, function ($app) {
            return new DbdumpConfigStore(
                $app->make(FileSystemInterface::class),
                $app->make(EnvironmentConfig::class)
            );
        });
        $this->app->singleton(EnvFileWriter::class, function ($app) {
            return new EnvFileWriter($app->make(FileSystemInterface::class));
        });
        $this->app->singleton(AiConfig::class, function ($app) {
            $projectDir = $app['config']->get('database-dumps.project_dir');
            // Store читает config/database-dumps.php (та же секция llm) + накладывает
            // токен из окружения и prod-гейтинг. Явная секция llm — как fallback.
            $resolved = $app->make(DbdumpConfigStore::class)->resolve($projectDir);
            if ($resolved->getUrl() !== '') {
                return $resolved;
            }
            $cfg = $app['config']->get('database-dumps.llm', []);
            if (is_array($cfg) && !empty($cfg['url'])) {
                return AiConfig::fromArray($cfg);
            }
            return $resolved;
        });
        $this->app->singleton(HttpTransportInterface::class, CurlHttpTransport::class);
        $this->app->singleton(AiClientInterface::class, function ($app) {
            return AiClientFactory::create(
                $app->make(HttpTransportInterface::class),
                $app->make(AiConfig::class),
                $app->make(LoggerInterface::class)
            );
        });
        $this->app->singleton(LlmPatternDetector::class, function ($app) {
            return new LlmPatternDetector(
                $app->make(AiClientInterface::class),
                $app->make(PatternDetector::class),
                $app->make(ConnectionRegistryInterface::class),
                $app->make(LoggerInterface::class)
            );
        });

        $this->app->singleton(SchemaValidator::class);
        $this->app->singleton(FakerInterface::class, RussianFaker::class);
        $this->app->singleton(ConfigSplitter::class);

        // Профилирование и авто-критерии (deep-анализ)
        $this->app->singleton(ColumnStatisticsInspector::class, function ($app) {
            /** @var DumpConfig $dumpConfig */
            $dumpConfig = $app->make(DumpConfig::class);
            return new ColumnStatisticsInspector(
                $app->make(ConnectionRegistryInterface::class),
                $dumpConfig->getSampleSize(),
                $app->make(SafeQueryPolicy::class),
                $app->make(PgStatsReader::class)
            );
        });
        $this->app->singleton(CriteriaSuggester::class);
        $this->app->singleton(AnalysisReportWriter::class);

        $this->app->singleton(ConfigGenerator::class, function ($app) {
            return new ConfigGenerator(
                $app->make(TableInspector::class),
                $app->make(ServiceTableFilter::class),
                $app->make(FileSystemInterface::class),
                $app->make(LoggerInterface::class),
                $app->make(ConnectionRegistryInterface::class),
                $app->make(TableDependencyResolver::class),
                $app->make(ConfigSplitter::class),
                $app->make(PatternDetector::class),
                true,
                true,
                true,
                $app->make(LlmPatternDetector::class),
                $app->make(ColumnStatisticsInspector::class),
                $app->make(CriteriaSuggester::class),
                $app->make(AnalysisReportWriter::class),
                $app['config']->get('database-dumps.project_dir'),
                $app->make(DbdumpConfigStore::class),
                $app->make(RowCounter::class)
            );
        });

        $this->app->singleton(PrepareConfigCommand::class, function ($app) {
            return new PrepareConfigCommand(
                $app->make(ConfigGenerator::class),
                $app->make(ModeParser::class),
                $app->make(LoggerInterface::class),
                $app['config']->get('database-dumps.config_path'),
                $app->make(DbdumpConfigStore::class),
                $app->make(HttpTransportInterface::class),
                $app['config']->get('database-dumps.project_dir'),
                $app->make(EnvFileWriter::class),
                $app->make(RegenerationGuard::class)
            );
        });

        // Анализ кода через OPENCODE
        // Grep-сканер использований таблиц в коде хоста (projectDir + logger).
        $this->app->singleton(CodeHintScanner::class, function ($app) {
            return new CodeHintScanner(
                $app['config']->get('database-dumps.project_dir'),
                $app->make(LoggerInterface::class)
            );
        });
        $this->app->singleton(AnalysisPackageBuilder::class, function ($app) {
            return new AnalysisPackageBuilder(
                $app->make(FileSystemInterface::class),
                $app->make(ConnectionRegistryInterface::class),
                $app->make(TableInspector::class),
                $app->make(ServiceTableFilter::class),
                $app->make(TableDependencyResolver::class),
                $app->make(ColumnStatisticsInspector::class),
                $app->make(LoggerInterface::class),
                $app['config']->get('database-dumps.project_dir'),
                $app->make(CodeHintScanner::class),
                $app->make(DbdumpConfigStore::class),
                $app->make(RowCounter::class),
                $app->make(PgStatsReader::class)
            );
        });
        $this->app->singleton(AnalysisIngestor::class, function ($app) {
            return new AnalysisIngestor(
                $app->make(FileSystemInterface::class),
                $app->make(LoggerInterface::class)
            );
        });
        $this->app->singleton(ConfigEnricher::class, function ($app) {
            return new ConfigEnricher(
                $app->make(FileSystemInterface::class),
                $app->make(ConfigSplitter::class),
                $app->make(LoggerInterface::class),
                $app['config']->get('database-dumps.project_dir'),
                $app->make(DbdumpConfigStore::class)
            );
        });
        $this->app->singleton(CriteriaSqlTester::class, function ($app) {
            return new CriteriaSqlTester($app->make(ConnectionRegistryInterface::class));
        });

        $this->app->singleton(ConfigureLlmCommand::class, function ($app) {
            return new ConfigureLlmCommand(
                $app->make(DbdumpConfigStore::class),
                $app->make(HttpTransportInterface::class),
                $app['config']->get('database-dumps.project_dir'),
                $app->make(EnvFileWriter::class)
            );
        });

        $this->app->singleton(PrepareAnalysisCommand::class, function ($app) {
            return new PrepareAnalysisCommand(
                $app->make(AnalysisPackageBuilder::class),
                $app->make(LoggerInterface::class),
                $app['config']->get('database-dumps.project_dir'),
                $app->make(DbdumpConfigStore::class)
            );
        });
        $this->app->singleton(ApplyAnalysisCommand::class, function ($app) {
            return new ApplyAnalysisCommand(
                $app->make(AnalysisIngestor::class),
                $app->make(ConfigEnricher::class),
                $app->make(LoggerInterface::class),
                $app['config']->get('database-dumps.project_dir'),
                $app['config']->get('database-dumps.config_path'),
                $app->make(DbdumpConfigStore::class)
            );
        });
        $this->app->singleton(ConfigCriteriaRepairer::class, function ($app) {
            return new ConfigCriteriaRepairer(
                $app->make(FileSystemInterface::class),
                $app->make(ConfigLoaderInterface::class),
                $app->make(CriteriaSqlTester::class),
                $app->make(LoggerInterface::class),
                (string) $app['config']->get('database-dumps.project_dir'),
                $app->make(DbdumpConfigStore::class)
            );
        });
        $this->app->singleton(RepairConfigsCommand::class, function ($app) {
            return new RepairConfigsCommand(
                $app->make(ConfigCriteriaRepairer::class),
                $app['config']->get('database-dumps.config_path'),
                $app->make(LoggerInterface::class)
            );
        });

        // Аудит конфига без БД (validate): слепок схемы вместо подключения.
        $this->app->singleton(ConfigAuditor::class, function ($app) {
            return new ConfigAuditor($app->make(FileSystemInterface::class));
        });
        $this->app->singleton(AuditFixer::class, function ($app) {
            return new AuditFixer(
                $app->make(FileSystemInterface::class),
                $app->make(LoggerInterface::class)
            );
        });
        $this->app->singleton(JsonReportWriter::class, function ($app) {
            return new JsonReportWriter($app->make(FileSystemInterface::class));
        });
        $this->app->singleton(ValidateConfigCommand::class, function ($app) {
            return new ValidateConfigCommand(
                $app->make(ConfigAuditor::class),
                $app->make(AuditFixer::class),
                $app->make(JsonReportWriter::class),
                $app->make(FileSystemInterface::class),
                $app->make(DbdumpConfigStore::class),
                (string) $app['config']->get('database-dumps.project_dir'),
                (string) $app['config']->get('database-dumps.config_path'),
                $app->make(LoggerInterface::class)
            );
        });

        $this->app->singleton(DatabaseDumper::class, function ($app) {
            return new DatabaseDumper(
                $app->make(DataFetcher::class),
                $app->make(SqlGenerator::class),
                $app->make(FileSystemInterface::class),
                $app->make(LoggerInterface::class),
                $app['config']->get('database-dumps.project_dir'),
                $app->make(TableDependencyResolver::class),
                $app->make(FakerInterface::class),
                $app->make(DumpConfig::class),
                $app->make(ProductionGuard::class),
                $app->make(DbdumpConfigStore::class),
                $app->make(SafeQueryPolicy::class)
            );
        });

        $this->app->singleton(DatabaseImporter::class, function ($app) {
            return new DatabaseImporter(
                $app->make(ConnectionRegistryInterface::class),
                $app->make(DumpConfig::class),
                $app->make(FileSystemInterface::class),
                $app->make(ProductionGuard::class),
                $app->make(TransactionManager::class),
                $app->make(ScriptExecutor::class),
                $app->make(SqlParser::class),
                $app->make(LoggerInterface::class),
                $app['config']->get('database-dumps.project_dir'),
                $app->make(TableDependencyResolver::class),
                $app->make(SchemaValidator::class),
                $app->make(DbdumpConfigStore::class),
                $app->make(SafeQueryPolicy::class),
                new DumpValueReader()
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config/database-dumps.php' => config_path('database-dumps.php'),
        ], 'database-dumps-config');

        if ($this->app->runningInConsole()) {
            $this->ensureDumpConfigExists();
            $this->commands([
                DumpExportCommand::class,
                DbInitCommand::class,
                PrepareConfigCommand::class,
                ConfigureLlmCommand::class,
                PrepareAnalysisCommand::class,
                ApplyAnalysisCommand::class,
                RepairConfigsCommand::class,
                ValidateConfigCommand::class,
            ]);
        }
    }

    private function ensureDumpConfigExists(): void
    {
        /** @var string $configPath */
        $configPath = $this->app['config']->get('database-dumps.config_path');

        if (file_exists($configPath)) {
            return;
        }

        /** @var FileSystemInterface $fs */
        $fs = $this->app->make(FileSystemInterface::class);
        $dir = dirname($configPath);
        if (!$fs->isDirectory($dir)) {
            $fs->createDirectory($dir);
        }

        $stub = __DIR__ . '/stubs/dump_config.yaml';
        if (file_exists($stub)) {
            $fs->write($configPath, $fs->read($stub));
        }
    }
}
