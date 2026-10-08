<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar\Migration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Finance\Ar\Migration\GraphArMessageIdTranslator;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;
use TriCab\Core\Finance\Ar\Migration\ArMessageIdTranslationFailed;

/**
 * The Graph id translator on the fake HTTP client (T5.15, Q1). Nothing here reaches Graph; every id is invented.
 */
final class GraphArMessageIdTranslatorTest extends TestCase
{
    private const SECRET = 'invented-secret-value';

    private FakeJsonHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeJsonHttpClient();
    }

    public function test_it_asks_graph_for_immutable_ids_and_maps_each_one(): void
    {
        $this->http->queue = [self::token(), self::answer(['old-a' => 'new-a', 'old-b' => 'new-b'])];

        $out = $this->translator()->translate('AR@sample.local', ['old-a', 'old-b', 'old-a']);

        self::assertSame(['old-a' => 'new-a', 'old-b' => 'new-b'], $out);
        self::assertCount(2, $this->http->calls);
        $call = $this->http->calls[1];
        self::assertSame('https://graph.microsoft.com/v1.0/users/AR%40sample.local/translateExchangeIds', $call['url']);
        self::assertSame('Bearer invented-token', $call['headers']['Authorization']);
        self::assertSame(['inputIds' => ['old-a', 'old-b'], 'sourceIdType' => 'restId', 'targetIdType' => 'restImmutableEntryId'], $call['payload']);
    }

    public function test_an_id_graph_leaves_out_of_its_answer_has_no_translation(): void
    {
        $this->http->queue = [self::token(), self::answer(['old-a' => 'new-a', 'old-c' => ''])];

        $out = $this->translator()->translate('ar@sample.local', ['old-a', 'old-b', 'old-c']);

        self::assertSame(['old-a' => 'new-a', 'old-b' => null, 'old-c' => null], $out);
    }

    public function test_a_refused_batch_is_halved_until_the_id_that_is_gone_stands_alone(): void
    {
        $this->http->queue = [
            self::token(),
            self::refusal(400),                                   // a, b, c, d
            self::answer(['old-a' => 'new-a', 'old-b' => 'new-b']), // a, b
            self::refusal(404),                                   // c, d
            self::refusal(404),                                   // c
            self::answer(['old-d' => 'new-d']),                  // d
        ];

        $out = $this->translator()->translate('ar@sample.local', ['old-a', 'old-b', 'old-c', 'old-d']);

        self::assertSame(['old-a' => 'new-a', 'old-b' => 'new-b', 'old-c' => null, 'old-d' => 'new-d'], $out);
        self::assertSame(['old-c'], ($this->http->calls[4]['payload'] ?? [])['inputIds'] ?? null);
    }

    public function test_graph_refusing_every_id_is_a_failure_and_not_a_register_of_unreachable_messages(): void
    {
        $this->http->queue = [self::token(), self::refusal(400), self::refusal(400), self::refusal(400)];

        $this->expectException(ArMessageIdTranslationFailed::class);
        $this->translator()->translate('ar@sample.local', ['old-a', 'old-b']);
    }

    public function test_many_ids_go_in_batches(): void
    {
        $ids = array_map(static fn (int $n): string => 'old-' . $n, range(1, 450));
        $this->http->queue = [
            self::token(),
            self::answer(array_combine(array_slice($ids, 0, 200), array_slice($ids, 0, 200))),
            self::answer(array_combine(array_slice($ids, 200, 200), array_slice($ids, 200, 200))),
            self::answer(array_combine(array_slice($ids, 400), array_slice($ids, 400))),
        ];

        $out = $this->translator()->translate('ar@sample.local', $ids);

        self::assertCount(450, array_filter($out));
        self::assertCount(4, $this->http->calls);
    }

    public function test_a_throttle_or_a_server_error_is_retried_and_then_fails(): void
    {
        $this->http->queue = [self::token(), self::refusal(429), self::refusal(503), self::answer(['old-a' => 'new-a'])];
        $translator = $this->translator();
        self::assertSame(['old-a' => 'new-a'], $translator->translate('ar@sample.local', ['old-a']));

        // The token is kept, so the next three calls are the translation's own.
        $this->http->queue = [self::refusal(503), self::refusal(503), self::refusal(503)];
        $this->expectException(ArMessageIdTranslationFailed::class);
        $translator->translate('ar@sample.local', ['old-a']);
    }

    /**
     * @param list<array{status: int, body: string}|RuntimeException> $queue
     */
    #[DataProvider('serviceFailures')]
    public function test_a_failure_of_the_service_throws_without_the_secret_or_an_id(array $queue): void
    {
        $this->http->queue = $queue;

        try {
            $this->translator()->translate('ar@sample.local', ['old-secret-id']);
            self::fail('The translator did not fail.');
        } catch (ArMessageIdTranslationFailed $failed) {
            self::assertStringNotContainsString(self::SECRET, $failed->getMessage());
            self::assertStringNotContainsString('old-secret-id', $failed->getMessage());
            self::assertStringNotContainsString('invented-token', $failed->getMessage());
        }
    }

    /** @return array<string, array{list<array{status: int, body: string}|RuntimeException>}> */
    public static function serviceFailures(): array
    {
        return [
            'no token' => [[self::refusal(401)]],
            'a token answer that is not JSON' => [[['status' => 200, 'body' => 'nope']]],
            'sign-in unreachable' => [[new RuntimeException('down ' . self::SECRET)]],
            'access denied' => [[self::token(), self::refusal(403)]],
            'unauthorised' => [[self::token(), self::refusal(401)]],
            'another client error' => [[self::token(), self::refusal(405)]],
            'graph unreachable' => [[self::token(), new RuntimeException('x'), new RuntimeException('x'), new RuntimeException('x')]],
            'not JSON' => [[self::token(), ['status' => 200, 'body' => '<html>']]],
            'no value list' => [[self::token(), ['status' => 200, 'body' => '{"other":1}']]],
        ];
    }

    private function translator(): GraphArMessageIdTranslator
    {
        return new GraphArMessageIdTranslator($this->http, 'tenant-invented', 'client-invented', self::SECRET, false);
    }

    /** @return array{status: int, body: string} */
    private static function token(): array
    {
        return ['status' => 200, 'body' => '{"access_token":"invented-token","expires_in":3600}'];
    }

    /** @return array{status: int, body: string} */
    private static function refusal(int $status): array
    {
        return ['status' => $status, 'body' => '{"error":{"code":"ErrorInvalidIdMalformed"}}'];
    }

    /**
     * @param array<string, string> $pairs old id => immutable id
     * @return array{status: int, body: string}
     */
    private static function answer(array $pairs): array
    {
        $value = [];
        foreach ($pairs as $source => $target) {
            $value[] = ['sourceId' => (string) $source, 'targetId' => $target];
        }

        return ['status' => 200, 'body' => json_encode(['value' => $value], JSON_THROW_ON_ERROR)];
    }
}
