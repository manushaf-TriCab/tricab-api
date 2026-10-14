<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Assistant\Infrastructure\Foundry\CurlFoundryStreamClient;

final class CurlFoundryStreamClientTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            self::markTestSkipped('No local port is available.');
        }
        $name = (string) stream_socket_get_name($socket, false);
        self::$port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($socket);

        $fixtures = dirname(__DIR__) . '/Fixtures/Foundry';
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, $fixtures . '/sse-server.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            self::markTestSkipped('The local test server could not start.');
        }
        self::$server = $process;
        for ($i = 0; $i < 50; $i++) {
            $probe = @fsockopen('127.0.0.1', self::$port);
            if ($probe !== false) {
                fclose($probe);

                return;
            }
            usleep(50_000);
        }
        self::markTestSkipped('The local test server did not come up.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
    }

    public function test_hands_over_each_event_however_the_bytes_are_split(): void
    {
        $seen = [];
        $result = (new CurlFoundryStreamClient(10.0))->stream(
            'http://127.0.0.1:' . self::$port . '/anthropic/v1/messages',
            ['x-api-key' => 'test'],
            ['stream' => true],
            static function (string $event, array $data) use (&$seen): void {
                $delta = is_array($data['delta'] ?? null) ? $data['delta'] : [];
                $seen[] = [$event, $data['type'] ?? null, $delta['text'] ?? null];
            },
        );

        self::assertSame(['status' => 200, 'body' => ''], $result);
        self::assertSame([
            ['message_start', 'message_start', null],
            ['content_block_delta', 'content_block_delta', 'stream-true'],
            ['ping', 'ping', null],
            ['message_stop', 'message_stop', null],
        ], $seen);
    }

    public function test_returns_a_rejection_instead_of_events(): void
    {
        $result = (new CurlFoundryStreamClient(10.0))->stream(
            'http://127.0.0.1:' . self::$port . '/anthropic/v1/messages',
            ['X-Scenario' => 'reject'],
            ['stream' => true],
            static function (): void {
                self::fail('A rejected request has no events.');
            },
        );

        self::assertSame(529, $result['status']);
        self::assertStringContainsString('overloaded_error', $result['body']);
    }

    public function test_an_exception_from_the_handler_stops_the_stream_and_surfaces(): void
    {
        $this->expectException(\LogicException::class);
        (new CurlFoundryStreamClient(10.0))->stream(
            'http://127.0.0.1:' . self::$port . '/anthropic/v1/messages',
            [],
            ['stream' => true],
            static function (): void {
                throw new \LogicException('stop');
            },
        );
    }
}
