<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Ai;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\AiConfig;
use Timbrs\DatabaseDumps\Contract\HttpTransportInterface;
use Timbrs\DatabaseDumps\Service\Ai\OpenAiClient;

class OpenAiClientTest extends TestCase
{
    /**
     * Подкласс без реальной задержки backoff (чтобы тесты ретраев не спали).
     */
    private function client(HttpTransportInterface $transport, ?AiConfig $config = null): OpenAiClient
    {
        $config = $config ?? new AiConfig('https://gpt.example.com/v1', 'm', 'tok', 120);
        return new class ($transport, $config) extends OpenAiClient {
            protected function doSleep(int $seconds): void
            {
                // no-op в тестах
            }
        };
    }

    private function chatResponseBody(string $content): string
    {
        return (string) json_encode(['choices' => [['message' => ['content' => $content]]]]);
    }

    public function testIsAvailableReflectsConfig(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $this->assertTrue($this->client($transport)->isAvailable());

        $disabled = new AiConfig('', 'm', null, 120, false);
        $this->assertFalse($this->client($transport, $disabled)->isAvailable());
    }

    public function testChatSuccess(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $transport
            ->expects($this->once())
            ->method('post')
            ->with(
                $this->stringContains('https://gpt.example.com/v1/chat/completions'),
                $this->callback(function ($headers) {
                    return in_array('Content-Type: application/json', $headers, true)
                        && in_array('Authorization: Bearer tok', $headers, true);
                }),
                $this->stringContains('"model":"m"'),
                120
            )
            ->willReturn(['status' => 200, 'body' => $this->chatResponseBody('hello world')]);

        $result = $this->client($transport)->chat([['role' => 'user', 'content' => 'hi']]);
        $this->assertSame('hello world', $result);
    }

    public function testChatRetriesThenSucceeds(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $body = $this->chatResponseBody('recovered');
        $calls = 0;
        $transport
            ->expects($this->exactly(2))
            ->method('post')
            ->willReturnCallback(function () use (&$calls, $body) {
                $calls++;
                if ($calls === 1) {
                    throw new \RuntimeException('connection refused');
                }
                return ['status' => 200, 'body' => $body];
            });

        $result = $this->client($transport)->chat([['role' => 'user', 'content' => 'hi']]);
        $this->assertSame('recovered', $result);
    }

    public function testChatRetriesOnNon2xxStatus(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $okBody = $this->chatResponseBody('ok');
        $calls = 0;
        $transport
            ->expects($this->exactly(2))
            ->method('post')
            ->willReturnCallback(function () use (&$calls, $okBody) {
                $calls++;
                if ($calls === 1) {
                    return ['status' => 503, 'body' => 'model loading'];
                }
                return ['status' => 200, 'body' => $okBody];
            });

        $result = $this->client($transport)->chat([['role' => 'user', 'content' => 'hi']]);
        $this->assertSame('ok', $result);
    }

    public function testChatFailsAfterMaxAttempts(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $transport
            ->expects($this->exactly(3))
            ->method('post')
            ->willThrowException(new \RuntimeException('timeout'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/после ретраев/u');
        $this->client($transport)->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function testChatJsonParsesPlainObject(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $transport->method('post')->willReturn([
            'status' => 200,
            'body' => $this->chatResponseBody('{"columns": [{"column_name": "email", "pii_type": "email"}]}'),
        ]);

        $result = $this->client($transport)->chatJson([['role' => 'user', 'content' => 'classify']]);
        $this->assertArrayHasKey('columns', $result);
        $this->assertSame('email', $result['columns'][0]['pii_type']);
    }

    public function testChatJsonStripsMarkdownFence(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $wrapped = "Вот результат:\n```json\n{\"ok\": true}\n```\nготово";
        $transport->method('post')->willReturn([
            'status' => 200,
            'body' => $this->chatResponseBody($wrapped),
        ]);

        $result = $this->client($transport)->chatJson([['role' => 'user', 'content' => 'x']]);
        $this->assertSame(['ok' => true], $result);
    }

    public function testExtractJsonVariants(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $client = $this->client($transport);

        $this->assertSame('{"a":1}', $client->extractJson('{"a":1}'));
        $this->assertSame('{"a":1}', $client->extractJson("```json\n{\"a\":1}\n```"));
        $this->assertSame('{"a":1}', $client->extractJson("```\n{\"a\":1}\n```"));
        $this->assertSame('[1,2,3]', $client->extractJson('[1,2,3]'));
        $this->assertNull($client->extractJson('no json here'));
    }

    public function testThrowsOnInvalidBaseUrl(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $this->expectException(\InvalidArgumentException::class);
        new OpenAiClient($transport, new AiConfig('file:///etc/passwd', 'm', null, 120, true));
    }

    public function testChatThrowsWhenDisabled(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $client = $this->client($transport, new AiConfig('', 'm', null, 120, false));
        $this->expectException(\RuntimeException::class);
        $client->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function testErrorAfterRetriesDoesNotLeakToken(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        // Сервер «отражает» тело, но токен живёт только в заголовках — в тело/ошибку он попасть не должен.
        $transport->method('post')->willReturn(['status' => 401, 'body' => 'unauthorized']);

        $config = new AiConfig('https://gpt.example.com/v1', 'm', 'super-secret-token', 120);
        try {
            $this->client($transport, $config)->chat([['role' => 'user', 'content' => 'hi']]);
            $this->fail('ожидалось исключение');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('super-secret-token', $e->getMessage());
            $this->assertStringContainsString('после ретраев', $e->getMessage());
        }
    }

    public function testNon2xxBodyTruncatedInError(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $longBody = str_repeat('A', 2000);
        $transport->method('post')->willReturn(['status' => 500, 'body' => $longBody]);

        try {
            $this->client($transport)->chat([['role' => 'user', 'content' => 'hi']]);
            $this->fail('ожидалось исключение');
        } catch (\RuntimeException $e) {
            // Тело в lastError обрезается до 500 символов — сообщение не должно содержать все 2000 'A'.
            $this->assertLessThan(2000, substr_count($e->getMessage(), 'A'));
        }
    }

    public function testNoAuthorizationHeaderWhenNoToken(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $transport
            ->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function ($headers) {
                    foreach ($headers as $h) {
                        if (stripos($h, 'Authorization:') === 0) {
                            return false;
                        }
                    }
                    return true;
                }),
                $this->anything(),
                $this->anything()
            )
            ->willReturn(['status' => 200, 'body' => $this->chatResponseBody('ok')]);

        $config = new AiConfig('https://gpt.example.com/v1', 'm', null, 120);
        $this->assertSame('ok', $this->client($transport, $config)->chat([['role' => 'user', 'content' => 'hi']]));
    }

    public function testChatJsonRejectsScalarJson(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $transport->method('post')->willReturn([
            'status' => 200,
            'body' => $this->chatResponseBody('42'),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->client($transport)->chatJson([['role' => 'user', 'content' => 'x']]);
    }

    public function testChatJsonThrowsWhenNoJsonExtractable(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $transport->method('post')->willReturn([
            'status' => 200,
            'body' => $this->chatResponseBody('тут нет json вообще'),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/JSON/u');
        $this->client($transport)->chatJson([['role' => 'user', 'content' => 'x']]);
    }

    public function testExtractJsonClampsOversizedInput(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $client = $this->client($transport);

        // Открывающая ``` есть, закрывающей нет, и хвост гигантский: проверяем, что
        // кламп до regex отрабатывает быстро и возвращает null (без катастрофического бэктрекинга).
        $huge = "```json\n" . str_repeat('x', 3 * 1024 * 1024);
        $start = microtime(true);
        $this->assertNull($client->extractJson($huge));
        $this->assertLessThan(1.0, microtime(true) - $start, 'extractJson должен отрабатывать быстро (ReDoS-кламп)');
    }

    public function testExtractJsonRejectsScalarAndEmpty(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $client = $this->client($transport);

        $this->assertNull($client->extractJson(''));
        $this->assertNull($client->extractJson('   '));
        $this->assertNull($client->extractJson('"just a string"'));
        $this->assertNull($client->extractJson('42'));
    }

    public function testThrowsOnNonHttpScheme(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $this->expectException(\InvalidArgumentException::class);
        new OpenAiClient($transport, new AiConfig('ftp://host/v1', 'm', null, 120, true));
    }

    public function testThrowsOnMissingHost(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $this->expectException(\InvalidArgumentException::class);
        // gopher-подобный мусор без host
        new OpenAiClient($transport, new AiConfig('http:///nohost', 'm', null, 120, true));
    }

    public function testDisabledConfigSkipsUrlValidation(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        // URL невалиден, но фича выключена → конструктор не должен бросать.
        $client = new OpenAiClient($transport, new AiConfig('file:///etc/passwd', 'm', null, 120, false));
        $this->assertFalse($client->isAvailable());
    }

    public function testPingSuccessSingleRequestNoRetry(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $transport
            ->expects($this->once()) // ровно один запрос, без ретраев
            ->method('post')
            ->willReturn(['status' => 200, 'body' => $this->chatResponseBody('pong')]);

        $result = $this->client($transport)->ping();
        $this->assertTrue($result['ok']);
        $this->assertNull($result['error']);
    }

    public function testPingReportsErrorOnFailureWithoutRetry(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $transport
            ->expects($this->once())
            ->method('post')
            ->willThrowException(new \RuntimeException('connection refused'));

        $result = $this->client($transport)->ping();
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('connection refused', (string) $result['error']);
    }

    public function testPingReportsNon2xx(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $transport->method('post')->willReturn(['status' => 401, 'body' => 'unauthorized']);

        $result = $this->client($transport)->ping();
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('401', (string) $result['error']);
    }

    public function testPingFalseWhenDisabled(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $client = $this->client($transport, new AiConfig('', 'm', null, 120, false));
        $result = $client->ping();
        $this->assertFalse($result['ok']);
    }

    public function testChatPassesVerifySslFlagToTransport(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $transport
            ->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                false // verify_ssl отключён в конфиге → прокидывается 5-м аргументом в транспорт
            )
            ->willReturn(['status' => 200, 'body' => $this->chatResponseBody('ok')]);

        $insecure = new AiConfig('https://gpt.example.com/v1', 'm', 'tok', 120, true, false);
        $this->client($transport, $insecure)->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function testPingPassesVerifySslFlagToTransport(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $transport
            ->expects($this->once())
            ->method('post')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), false)
            ->willReturn(['status' => 200, 'body' => $this->chatResponseBody('pong')]);

        $insecure = new AiConfig('https://gpt.example.com/v1', 'm', 'tok', 120, true, false);
        $this->assertTrue($this->client($transport, $insecure)->ping()['ok']);
    }
}
