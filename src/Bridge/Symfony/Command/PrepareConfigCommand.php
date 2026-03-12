<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony\Command;

use Timbrs\DatabaseDumps\Service\ConfigGenerator\ConfigGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class PrepareConfigCommand extends Command
{
    /** @var ConfigGenerator */
    private $generator;

    /** @var string */
    private $projectDir;

    public function __construct(ConfigGenerator $generator, string $projectDir)
    {
        $this->generator = $generator;
        $this->projectDir = $projectDir;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:dbdump:prepare-config')
            ->setDescription('Автоматическая генерация dump_config.yaml на основе структуры БД')
            ->addArgument('mode', InputArgument::OPTIONAL, 'Режим: all, schema=<name>, table=<schema.table>, new')
            ->addOption('threshold', 't', InputOption::VALUE_REQUIRED, 'Порог строк для partial_export', '500')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Перезаписать без подтверждения')
            ->addOption('no-cascade', null, InputOption::VALUE_NONE, 'Пропустить обнаружение FK и генерацию cascade_from')
            ->addOption('no-faker', null, InputOption::VALUE_NONE, 'Пропустить обнаружение персональных данных')
            ->addOption('no-split', null, InputOption::VALUE_NONE, 'Генерировать единый YAML без разделения по схемам')
            ->setHelp(<<<'HELP'
Анализирует структуру БД и генерирует dump_config.yaml.
Таблицы с количеством строк <= threshold попадают в full_export,
остальные — в partial_export с лимитом.

Режимы:
  all           Полная регенерация конфигурации (перезаписывает файл)
  schema=<name> Перегенерация одной схемы, мёрж в существующий конфиг
  table=<s.t>   Перегенерация одной таблицы (schema.table), мёрж в существующий конфиг
  new           Обнаружение и дописывание новых таблиц (не затрагивает существующие)

Примеры:
  php bin/console app:dbdump:prepare-config all                        Полная регенерация
  php bin/console app:dbdump:prepare-config all --threshold=1000       Порог 1000 строк
  php bin/console app:dbdump:prepare-config schema=billing             Перегенерировать схему billing
  php bin/console app:dbdump:prepare-config table=public.users         Перегенерировать таблицу public.users
  php bin/console app:dbdump:prepare-config new                        Добавить новые таблицы
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Генерация dump_config.yaml');

        /** @var string|null $modeArg */
        $modeArg = $input->getArgument('mode');

        if ($modeArg === null || $modeArg === '') {
            $io->error('Не указан режим работы.');
            $this->printModeUsage($io);
            return Command::FAILURE;
        }

        $parsed = $this->parseMode($modeArg);

        if ($parsed === null) {
            $io->error("Неизвестный режим: {$modeArg}");
            $this->printModeUsage($io);
            return Command::FAILURE;
        }

        $outputPath = $this->projectDir . '/config/dump_config.yaml';
        /** @var string $thresholdValue */
        $thresholdValue = $input->getOption('threshold');
        $threshold = (int) $thresholdValue;

        if ($threshold <= 0) {
            $io->error('Порог должен быть положительным числом');
            return Command::FAILURE;
        }

        // Подтверждение перезаписи только для режима all
        if ($parsed['mode'] === ConfigGenerator::MODE_ALL) {
            $force = $input->getOption('force');
            if (!$force && file_exists($outputPath)) {
                /** @var bool $confirmed */
                $confirmed = $io->confirm("Файл {$outputPath} уже существует. Перезаписать?", false);
                if (!$confirmed) {
                    $io->warning('Отменено');
                    return Command::SUCCESS;
                }
            }
        }

        try {
            $io->text("Режим: {$modeArg}");
            $io->text("Порог строк: {$threshold}");
            $io->text("Путь: {$outputPath}");
            $io->newLine();

            if ($input->getOption('no-cascade')) {
                $this->generator->setCascadeEnabled(false);
            }
            if ($input->getOption('no-faker')) {
                $this->generator->setFakerEnabled(false);
            }
            if ($input->getOption('no-split')) {
                $this->generator->setSplitBySchema(false);
            }

            $this->generator->setMode($parsed['mode'], $parsed['scope']);
            $stats = $this->generator->generate($outputPath, $threshold);

            $io->success(sprintf(
                "Конфигурация сгенерирована: full=%d, partial=%d, пропущено=%d, пустых=%d",
                $stats['full'],
                $stats['partial'],
                $stats['skipped'],
                $stats['empty']
            ));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Ошибка генерации: ' . $e->getMessage());

            if ($io->isVerbose()) {
                $io->note('Трейс: ' . $e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    /**
     * @return array{mode: string, scope: string|null}|null
     */
    private function parseMode(string $arg): ?array
    {
        if ($arg === 'all') {
            return ['mode' => ConfigGenerator::MODE_ALL, 'scope' => null];
        }

        if ($arg === 'new') {
            return ['mode' => ConfigGenerator::MODE_NEW, 'scope' => null];
        }

        if (strpos($arg, 'schema=') === 0) {
            $scope = substr($arg, 7);
            if ($scope === '' || $scope === false) {
                return null;
            }
            return ['mode' => ConfigGenerator::MODE_SCHEMA, 'scope' => $scope];
        }

        if (strpos($arg, 'table=') === 0) {
            $scope = substr($arg, 6);
            if ($scope === '' || $scope === false || strpos($scope, '.') === false) {
                return null;
            }
            return ['mode' => ConfigGenerator::MODE_TABLE, 'scope' => $scope];
        }

        return null;
    }

    private function printModeUsage(SymfonyStyle $io): void
    {
        $io->text([
            'Доступные режимы:',
            '  <info>all</info>              Полная регенерация конфигурации',
            '  <info>schema=</info><comment>name</comment>     Перегенерация одной схемы, мёрж в существующий конфиг',
            '  <info>table=</info><comment>schema.table</comment>  Перегенерация одной таблицы, мёрж в существующий конфиг',
            '  <info>new</info>              Обнаружение и дописывание новых таблиц',
            '',
            'Примеры:',
            '  php bin/console app:dbdump:prepare-config <info>all</info>',
            '  php bin/console app:dbdump:prepare-config <info>schema=billing</info>',
            '  php bin/console app:dbdump:prepare-config <info>table=public.users</info>',
            '  php bin/console app:dbdump:prepare-config <info>new</info>',
        ]);
    }
}
