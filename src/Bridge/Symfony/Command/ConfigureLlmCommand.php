<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Timbrs\DatabaseDumps\Config\AiConfig;
use Timbrs\DatabaseDumps\Contract\HttpTransportInterface;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;
use Timbrs\DatabaseDumps\Service\Ai\OpenAiClient;
use Timbrs\DatabaseDumps\Util\EnvFileWriter;

/**
 * Интерактивная настройка LLM: спрашивает наличие LLM, URL, модель и token.
 * Несекретное сохраняется в config/database-dumps.php (DbdumpConfigStore),
 * токен — в .env.local/.env (EnvFileWriter, DBDUMP_LLM_TOKEN).
 */
class ConfigureLlmCommand extends Command
{
    /** @var DbdumpConfigStore */
    private $store;

    /** @var HttpTransportInterface */
    private $transport;

    /** @var string */
    private $projectDir;

    /** @var EnvFileWriter */
    private $envWriter;

    public function __construct(
        DbdumpConfigStore $store,
        HttpTransportInterface $transport,
        string $projectDir,
        EnvFileWriter $envWriter
    ) {
        $this->store = $store;
        $this->transport = $transport;
        $this->projectDir = rtrim($projectDir, '/\\');
        $this->envWriter = $envWriter;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:dbdump:configure-llm')
            ->setDescription('Настроить LLM для анализа (URL, модель, token) и сохранить');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Настройка LLM для анализа данных');

        $current = $this->store->resolve($this->projectDir);

        // Команда запуска opencode (не зависит от LLM) — спрашиваем всегда, сохраняем в обеих ветках.
        $opencodeBin = $this->askOpencodeBin($io);

        if (!$io->confirm('Использовать LLM для анализа (PII, профилирование, подсказки)?', $current->isEnabled())) {
            $this->store->save($this->projectDir, AiConfig::fromArray(['url' => '', 'enabled' => false]), null, $opencodeBin);
            $io->writeln('<info>LLM отключён.</info> Анализ будет работать на regex-эвристиках.');
            $io->writeln('Файл настроек: ' . $this->store->path($this->projectDir));
            return Command::SUCCESS;
        }

        $url = $io->ask(
            'Адрес LLM (base URL, например https://gpt.example.com/v1)',
            $current->getUrl() !== '' ? $current->getUrl() : null,
            function ($value) {
                $value = is_string($value) ? trim($value) : '';
                if (!self::isValidUrl($value)) {
                    throw new \RuntimeException('Нужен корректный http(s) URL с хостом.');
                }
                return $value;
            }
        );

        $model = $io->ask('Модель', $current->getModel());

        $hasToken = $current->getToken() !== null;
        // Ввод видимый (не askHidden), чтобы было видно вставляемый токен.
        $tokenInput = $io->ask(
            'Token' . ($hasToken ? ' (Enter — оставить текущий)' : ' (Enter — без токена; ввод виден)')
        );
        // Новый токен — только если пользователь что-то ввёл; иначе оставляем текущий (из env).
        $newToken = ($tokenInput === null || $tokenInput === '') ? null : (string) $tokenInput;
        $token = $newToken !== null ? $newToken : $current->getToken();

        $verifySsl = $io->confirm(
            'Проверять TLS-сертификат сервера LLM? (отключайте только для внутренних эндпоинтов с корпоративным CA)',
            $current->getVerifySsl()
        );

        $config = AiConfig::fromArray([
            'url' => $url,
            'model' => $model,
            'token' => $token,
            'timeout' => $current->getTimeout(),
            'enabled' => true,
            'verify_ssl' => $verifySsl,
        ]);

        if ($io->confirm('Проверить соединение с LLM сейчас?', true)) {
            $io->writeln('Проверяю соединение с LLM…');
            $result = (new OpenAiClient($this->transport, $config))->ping();
            $io->newLine();
            if ($result['ok']) {
                $io->writeln('<info>OK:</info> соединение с LLM успешно установлено.');
                $reply = isset($result['reply']) ? (string) $result['reply'] : '';
                if ($reply !== '') {
                    $io->writeln('Ответ модели на «ping»: ' . self::oneLine($reply));
                }
            } else {
                $io->writeln('<comment>ОШИБКА:</comment> не удалось соединиться с LLM.');
                $io->writeln('Причина: ' . ($result['error'] ?? 'неизвестная ошибка'));
                if ($verifySsl && self::looksLikeSslError((string) ($result['error'] ?? ''))) {
                    $io->writeln('<comment>Похоже на проблему с TLS-сертификатом.</comment> Если это внутренний '
                        . 'эндпоинт с корпоративным CA — перезапустите и ответьте «нет» на вопрос про проверку '
                        . 'TLS-сертификата (или задайте DBDUMP_LLM_VERIFY_SSL=false), либо пропишите корпоративный '
                        . 'CA в php.ini (curl.cainfo).');
                }
                $io->newLine();
                if (!$io->confirm('Сохранить настройки всё равно?', true)) {
                    $io->writeln('Отменено, ничего не сохранено.');
                    return Command::SUCCESS;
                }
            }
            $io->newLine();
        }

        $this->store->save($this->projectDir, $config, null, $opencodeBin);
        $io->writeln('<info>Готово:</info> настройки LLM сохранены.');
        $io->writeln('Файл: ' . $this->store->path($this->projectDir) . ' (без токена)');
        $io->writeln('Команда opencode: ' . $opencodeBin . ' (переопределяется env DBDUMP_OPENCODE_BIN).');

        if ($newToken !== null) {
            $envPath = $this->envWriter->setVar($this->projectDir, AiConfig::ENV_TOKEN, $newToken);
            $io->writeln('Токен записан в ' . $envPath . ' (' . AiConfig::ENV_TOKEN . ').');
        }
        $io->writeln('Переменные окружения DBDUMP_LLM_* (если заданы) перекрывают этот файл.');

        return Command::SUCCESS;
    }

    /**
     * Спросить команду запуска opencode (бинарь в PATH) с текущим значением по умолчанию.
     */
    private function askOpencodeBin(SymfonyStyle $io): string
    {
        $current = $this->store->getOpencodeBin($this->projectDir);
        $answer = $io->ask('Команда запуска opencode (бинарь в PATH, напр. opencode или opencode-cli)', $current);
        $answer = is_string($answer) ? trim($answer) : '';

        return $answer !== '' ? $answer : $current;
    }

    /**
     * Схлопнуть переносы/пробелы и обрезать длинный ответ модели до одной читаемой строки.
     */
    private static function oneLine(string $s): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $s);
        $s = trim($collapsed === null ? $s : $collapsed);
        if (function_exists('mb_strlen') && mb_strlen($s) > 200) {
            return mb_substr($s, 0, 200) . '…';
        }
        return strlen($s) > 200 ? substr($s, 0, 200) . '…' : $s;
    }

    /**
     * Похоже ли сообщение об ошибке на проблему проверки TLS-сертификата
     * (curl 60 / SSL certificate problem) — чтобы подсказать про verify_ssl/CA.
     */
    private static function looksLikeSslError(string $error): bool
    {
        return stripos($error, 'SSL certificate') !== false
            || stripos($error, 'unable to get local issuer') !== false
            || stripos($error, '(60)') !== false;
    }

    private static function isValidUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        return ($scheme === 'http' || $scheme === 'https') && $parts['host'] !== '';
    }
}
