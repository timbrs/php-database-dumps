<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Service\Dumper\TableConfigResolver;
use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Verification\CascadeClosureVerifier;

/**
 * verify-dump: проверить УЖЕ ВЫГРУЖЕННЫЕ файлы, а не правила.
 *
 * validate сверяет конфиг со слепком схемы, check-criteria проверяет, что критерии
 * исполняются в БД. Ни один из них не смотрит в dumps/. Эта команда закрывает
 * стадию «что реально легло в дамп»: пока в ней одна проверка — замкнутость
 * каскадов, см. CascadeClosureVerifier.
 *
 * К базе не подключается: читает только файлы дампа, и только те колонки,
 * которые участвуют в cascade_from.
 */
class VerifyDumpCommand extends Command
{
    /** @var TableConfigResolver */
    private $resolver;

    /** @var CascadeClosureVerifier */
    private $verifier;

    /** @var string */
    private $projectDir;

    /** @var string */
    private $dataDir;

    public function __construct(
        TableConfigResolver $resolver,
        CascadeClosureVerifier $verifier,
        string $projectDir,
        string $dataDir
    ) {
        $this->resolver = $resolver;
        $this->verifier = $verifier;
        $this->projectDir = $projectDir;
        $this->dataDir = $dataDir;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:dbdump:verify-dump')
            ->setDescription('Проверить выгруженные дампы: замкнутость каскадов (сироты без родителя)')
            ->addOption('schema', 's', InputOption::VALUE_REQUIRED, 'Проверить только эту схему')
            ->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'Имя подключения')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text | json', 'text')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Записать отчёт в файл');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $schemaFilter = $input->getOption('schema');
        $connectionFilter = $input->getOption('connection');
        $format = (string) $input->getOption('format');

        $dumpsRoot = rtrim($this->projectDir, '/\\') . '/' . trim($this->dataDir, '/\\') . '/' . DumpConfig::DUMPS_DIR;

        if (!is_dir($dumpsRoot)) {
            $io->error('Каталог дампов не найден: ' . $dumpsRoot . '. Сначала выполните app:dbdump:export.');

            return Command::FAILURE;
        }

        $tables = $this->resolver->resolveAll(
            $schemaFilter !== null ? (string) $schemaFilter : null,
            $connectionFilter !== null ? (string) $connectionFilter : null
        );

        $result = $this->verifier->verify($tables, $dumpsRoot);
        $payload = $this->buildPayload($result, $dumpsRoot, $schemaFilter);

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

        $this->renderText($io, $payload, $result);

        return $payload['summary']['error'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param array{findings: array<int, Finding>, edges: int, checked: int, skipped: int, orphan_rows: int} $result
     *
     * @return array<string, mixed>
     */
    private function buildPayload(array $result, string $dumpsRoot, ?string $schemaFilter): array
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

        return [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'dumps_root' => $dumpsRoot,
            'schema_filter' => $schemaFilter,
            'summary' => [
                'total' => count($result['findings']),
                'error' => $bySeverity[Finding::SEVERITY_ERROR],
                'warning' => $bySeverity[Finding::SEVERITY_WARNING],
                'note' => $bySeverity[Finding::SEVERITY_NOTE],
                'by_code' => $byCode,
            ],
            'verification' => [
                'edges' => $result['edges'],
                'checked' => $result['checked'],
                'skipped' => $result['skipped'],
                'orphan_rows' => $result['orphan_rows'],
            ],
            'findings' => $findings,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{findings: array<int, Finding>, edges: int, checked: int, skipped: int, orphan_rows: int} $result
     */
    private function renderText(SymfonyStyle $io, array $payload, array $result): void
    {
        $io->title('Проверка выгруженных дампов');
        $io->text(sprintf(
            'Рёбер cascade_from: %d — проверено %d, пропущено %d (родитель в full_export, не выгружен или отсутствует в конфиге).',
            $payload['verification']['edges'],
            $payload['verification']['checked'],
            $payload['verification']['skipped']
        ));

        if (empty($result['findings'])) {
            $io->success(sprintf(
                'Замкнутость каскадов подтверждена на %d рёбрах: строк без родителя нет.',
                $payload['verification']['checked']
            ));

            return;
        }

        $rows = [];
        foreach ($result['findings'] as $finding) {
            $rows[] = [$finding->getCode(), $finding->getSeverity(), $finding->getTarget(), $finding->getMessage()];
        }
        $io->table(['код', 'уровень', 'таблица', 'что не так'], $rows);

        if ($payload['summary']['error'] > 0) {
            $io->error(sprintf(
                'Ошибок: %d, строк без родителя: %d. Такой дамп импортировать нельзя — связи в нём нет.',
                $payload['summary']['error'],
                $payload['verification']['orphan_rows']
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
