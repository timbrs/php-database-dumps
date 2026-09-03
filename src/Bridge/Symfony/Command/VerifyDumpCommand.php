<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Service\Dumper\TableConfigResolver;
use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Validation\InventoryReader;
use Timbrs\DatabaseDumps\Service\Verification\DumpVerificationInput;
use Timbrs\DatabaseDumps\Service\Verification\DumpVerificationRunner;

/**
 * verify-dump: проверить УЖЕ ВЫГРУЖЕННЫЕ файлы, а не правила.
 *
 * validate сверяет конфиг со слепком схемы, check-criteria проверяет, что критерии
 * исполняются в БД. Ни один из них не смотрит в dumps/. Эта команда закрывает
 * стадию «что реально легло в дамп»:
 *
 *   V-1..V-4  замкнутость связей — cascade_from и внешние ключи БД (CascadeClosureVerifier)
 *   V-5       покрытие значений: все ли коды/категории из БД попали в дамп (ValueCoverageVerifier)
 *   V-7       персональные данные в колонках без faker (PiiLeakVerifier)
 *   V-8       число строк против limit/квот/слепка (RowCountVerifier)
 *
 * К базе не подключается: читает файлы дампа (каждый один раз) и слепок схемы.
 * Значения данных в отчёт не попадают.
 */
class VerifyDumpCommand extends Command
{
    private const DEFAULT_INVENTORY = 'analysis/schema_inventory.json';

    /** @var TableConfigResolver */
    private $resolver;

    /** @var DumpVerificationRunner */
    private $runner;

    /** @var DumpConfig */
    private $dumpConfig;

    /** @var FileSystemInterface */
    private $fileSystem;

    /** @var string */
    private $projectDir;

    /** @var string */
    private $dataDir;

    public function __construct(
        TableConfigResolver $resolver,
        DumpVerificationRunner $runner,
        DumpConfig $dumpConfig,
        FileSystemInterface $fileSystem,
        string $projectDir,
        string $dataDir
    ) {
        $this->resolver = $resolver;
        $this->runner = $runner;
        $this->dumpConfig = $dumpConfig;
        $this->fileSystem = $fileSystem;
        $this->projectDir = $projectDir;
        $this->dataDir = $dataDir;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:dbdump:verify-dump')
            ->setDescription('Проверить выгруженные дампы: замкнутость связей, покрытие значений, ПД без faker, число строк')
            ->addOption('schema', 's', InputOption::VALUE_REQUIRED, 'Проверить только эту схему')
            ->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'Имя подключения')
            ->addOption('inventory', null, InputOption::VALUE_REQUIRED, 'Слепок схемы (по умолчанию {data_dir}/' . self::DEFAULT_INVENTORY . '); без него V-5, V-8 по слепку и FK-рёбра пропускаются')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text | json', 'text')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Записать отчёт в файл');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $schemaFilter = $input->getOption('schema');
        $connectionFilter = $input->getOption('connection');
        $format = (string) $input->getOption('format');

        $dataRoot = rtrim($this->projectDir, '/\\') . '/' . trim($this->dataDir, '/\\');
        $dumpsRoot = $dataRoot . '/' . DumpConfig::DUMPS_DIR;

        if (!is_dir($dumpsRoot)) {
            $io->error('Каталог дампов не найден: ' . $dumpsRoot . '. Сначала выполните app:dbdump:export.');

            return Command::FAILURE;
        }

        $tables = $this->resolver->resolveAll(
            $schemaFilter !== null ? (string) $schemaFilter : null,
            $connectionFilter !== null ? (string) $connectionFilter : null
        );

        $inventoryOption = $input->getOption('inventory');
        $inventoryPath = $inventoryOption !== null && $inventoryOption !== ''
            ? $this->absolutize((string) $inventoryOption)
            : $dataRoot . '/' . self::DEFAULT_INVENTORY;
        $inventory = null;
        $inventoryNote = null;
        if ($this->fileSystem->exists($inventoryPath)) {
            try {
                $inventory = new InventoryReader($this->fileSystem, $inventoryPath);
            } catch (\Throwable $e) {
                $inventoryNote = 'слепок не прочитан: ' . $e->getMessage();
            }
        } else {
            $inventoryNote = 'слепок не найден: ' . $inventoryPath . ' — выполните app:dbdump:prepare-analysis';
        }

        $result = $this->runner->run(new DumpVerificationInput($dumpsRoot, $tables, $inventory, $this->dumpConfig));
        $payload = $this->buildPayload($result, $dumpsRoot, $schemaFilter, $inventory !== null ? $inventoryPath : null, $inventoryNote);

        $rendered = $format === 'json'
            ? (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '';

        $out = $input->getOption('out');
        if ($out !== null && $out !== '') {
            $path = $this->absolutize((string) $out);
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents(
                $path,
                ($format === 'json'
                    ? $rendered
                    : (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ) . PHP_EOL
            );
            if ($format !== 'json') {
                $io->writeln('Отчёт записан: ' . $path);
            }
        }

        if ($format === 'json') {
            $output->writeln($rendered, OutputInterface::OUTPUT_RAW);

            return $payload['summary']['error'] > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        $this->renderText($io, $payload, $result['findings'], $inventoryNote);

        return $payload['summary']['error'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param array{findings: array<int, Finding>, stats: array<string, array<string, int>>} $result
     *
     * @return array<string, mixed>
     */
    private function buildPayload(array $result, string $dumpsRoot, ?string $schemaFilter, ?string $inventoryPath, ?string $inventoryNote): array
    {
        $bySeverity = [Finding::SEVERITY_ERROR => 0, Finding::SEVERITY_WARNING => 0, Finding::SEVERITY_NOTE => 0];
        $byCode = [];
        $findings = [];

        foreach ($result['findings'] as $finding) {
            ++$bySeverity[$finding->getSeverity()];
            $code = $finding->getCode();
            $byCode[$code] = isset($byCode[$code]) ? $byCode[$code] + 1 : 1;
            $findings[] = $finding->toArray();
        }
        ksort($byCode);

        $cascade = $result['stats']['CascadeClosureVerifier'] ?? [];

        return [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'dumps_root' => $dumpsRoot,
            'schema_filter' => $schemaFilter,
            'inventory' => $inventoryPath,
            'inventory_note' => $inventoryNote,
            'summary' => [
                'total' => count($result['findings']),
                'error' => $bySeverity[Finding::SEVERITY_ERROR],
                'warning' => $bySeverity[Finding::SEVERITY_WARNING],
                'note' => $bySeverity[Finding::SEVERITY_NOTE],
                'by_code' => $byCode,
            ],
            // Старые ключи сохранены: по ним читают отчёт /dumpcheck и ранние скрипты.
            'verification' => [
                'edges' => $cascade['edges'] ?? 0,
                'checked' => $cascade['checked'] ?? 0,
                'skipped' => $cascade['skipped'] ?? 0,
                'orphan_rows' => $cascade['orphan_rows'] ?? 0,
                'verifiers' => $result['stats'],
            ],
            'findings' => $findings,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, Finding> $findings
     */
    private function renderText(SymfonyStyle $io, array $payload, array $findings, ?string $inventoryNote): void
    {
        $io->title('Проверка выгруженных дампов');

        $stats = $payload['verification']['verifiers'];
        $lines = [];
        $cascade = $stats['CascadeClosureVerifier'] ?? null;
        if ($cascade !== null) {
            $lines[] = sprintf(
                'Связи (cascade_from + FK БД): рёбер %d — проверено %d, пропущено %d, строк без родителя %d.',
                $cascade['edges'],
                $cascade['checked'],
                $cascade['skipped'],
                $cascade['orphan_rows']
            );
        }
        $coverage = $stats['ValueCoverageVerifier'] ?? null;
        if ($coverage !== null) {
            $lines[] = sprintf('Покрытие значений: колонок проверено %d, пробелов %d.', $coverage['columns_checked'], $coverage['gaps']);
        }
        $pii = $stats['PiiLeakVerifier'] ?? null;
        if ($pii !== null) {
            $lines[] = sprintf('Персональные данные: колонок без faker проверено %d, утечек %d.', $pii['columns_checked'], $pii['leaks']);
        }
        $rows = $stats['RowCountVerifier'] ?? null;
        if ($rows !== null) {
            $lines[] = sprintf('Строки: файлов %d, строк всего %d, файлов нет %d.', $rows['tables'], $rows['rows_total'], $rows['missing']);
        }
        if ($inventoryNote !== null) {
            $lines[] = 'Без слепка: ' . $inventoryNote . '. Покрытие и сверка со слепком пропущены.';
        }
        $io->text($lines);

        if ($findings === []) {
            $io->success('Дампы проверены: строк без родителя нет, покрытие и ПД в порядке.');

            return;
        }

        $tableRows = [];
        foreach ($findings as $finding) {
            $tableRows[] = [$finding->getCode(), $finding->getSeverity(), $finding->getTarget(), $finding->getMessage()];
        }
        $io->table(['код', 'уровень', 'таблица', 'что не так'], $tableRows);

        if ($payload['summary']['error'] > 0) {
            $io->error(sprintf(
                'Ошибок: %d, предупреждений: %d. Такой дамп отдавать нельзя — исправьте конфиг и повторите экспорт.',
                $payload['summary']['error'],
                $payload['summary']['warning']
            ));

            return;
        }

        $io->warning(sprintf('Предупреждений: %d. Ошибок нет.', $payload['summary']['warning']));
    }

    private function absolutize(string $path): string
    {
        if (preg_match('#^(?:[a-zA-Z]:[\\\\/]|/|\\\\\\\\)#', $path) === 1) {
            return $path;
        }

        return rtrim($this->projectDir, '/\\') . '/' . $path;
    }
}
