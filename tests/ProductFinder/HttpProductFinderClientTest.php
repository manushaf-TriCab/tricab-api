<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\ProductFinder;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\ProductFinder\HttpProductFinderClient;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;
use TriCab\Core\ProductFinder\Application\DTO\ProductFinderQuery;

final class HttpProductFinderClientTest extends TestCase
{
    public function test_it_passes_the_finder_payload_through_unchanged(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = json_encode([
            'success' => true,
            'data' => [
                'status' => 'resolved',
                'productCode' => 'BHA-PATN/4C4RE',
                'confidence' => 0.92,
                'sizing' => ['crossSection' => 4.5],
            ],
        ], JSON_THROW_ON_ERROR);

        $client = new HttpProductFinderClient($http, 'http://127.0.0.1:8001');
        $result = $client->find(new ProductFinderQuery(text: '4 core 4mm2 marine'));

        self::assertTrue($result->available);
        self::assertSame('BHA-PATN/4C4RE', $result->productCode());
        self::assertSame('resolved', $result->status());
        self::assertSame(['crossSection' => 4.5], $result->toArray()['sizing']);

        self::assertSame('http://127.0.0.1:8001/api/find', $http->calls[0]['url']);
        self::assertSame(['text' => '4 core 4mm2 marine', 'allowAi' => true], $http->calls[0]['payload']);
    }

    public function test_unstated_fields_are_omitted_rather_than_sent_as_null(): void
    {
        // The finder distinguishes "not stated" from a stated zero, and JSON
        // null arrives as the latter. A null cableType sent through would read
        // as cable type 0, "Switchboard".
        $http = new FakeJsonHttpClient();
        $http->body = json_encode(['success' => true, 'data' => ['status' => 'resolved']], JSON_THROW_ON_ERROR);

        (new HttpProductFinderClient($http, 'http://127.0.0.1:8001'))
            ->find(new ProductFinderQuery(text: 'anything', coreCount: 4));

        $payload = $http->calls[0]['payload'] ?? [];
        self::assertArrayNotHasKey('cableType', $payload);
        self::assertArrayNotHasKey('crossSection', $payload);
        self::assertSame(4, $payload['cores']);
    }

    /**
     * The finder is the authority on its own request shape, and three keys do not
     * match the property carrying them. This asserts the names
     * `FinderRequest.from_payload` actually reads — see
     * `core/python/product_finder/selection.py`.
     *
     * It is a regression test. `coreCount`, `loadAmps` and `lengthM` were sent
     * instead, and `from_payload` does not look for those, so every request
     * through `/api/v1/product-finder` reached the finder with no load and no run
     * length and came back with `sizing: null`. Nothing caught it because the
     * assertion above pinned the key this side sent rather than the key the
     * finder reads.
     */
    public function test_the_payload_uses_the_key_names_the_finder_reads(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = json_encode(['success' => true, 'data' => ['status' => 'matched']], JSON_THROW_ON_ERROR);

        (new HttpProductFinderClient($http, 'http://127.0.0.1:8001'))
            ->find(new ProductFinderQuery(
                text: '4 core 16mm2 flexible',
                cableType: 1,
                coreCount: 4,
                crossSectionMm2: 16.0,
                loadAmps: 200.0,
                lengthM: 10.0,
                voltage: 400,
                phase: 3,
                install: 1,
                ambient: 40,
                strategy: 'min-runs',
            ));

        $payload = $http->calls[0]['payload'] ?? [];

        // The three that differ from the property name. Sizing cannot run without
        // load and length, so these are the load-bearing ones.
        self::assertSame(4, $payload['cores']);
        self::assertSame(200.0, $payload['load']);
        self::assertSame(10.0, $payload['length']);
        self::assertArrayNotHasKey('coreCount', $payload);
        self::assertArrayNotHasKey('loadAmps', $payload);
        self::assertArrayNotHasKey('lengthM', $payload);

        // The rest already matched and must stay matching.
        self::assertSame('4 core 16mm2 flexible', $payload['text']);
        self::assertSame(1, $payload['cableType']);
        self::assertSame(16.0, $payload['crossSection']);
        self::assertSame(400, $payload['voltage']);
        self::assertSame(3, $payload['phase']);
        self::assertSame(1, $payload['install']);
        self::assertSame(40, $payload['ambient']);
        self::assertSame('min-runs', $payload['strategy']);
    }

    public function test_an_unreachable_finder_degrades_instead_of_throwing(): void
    {
        $http = new FakeJsonHttpClient();
        $http->transportError = new RuntimeException('Connection refused');

        $result = (new HttpProductFinderClient($http, 'http://127.0.0.1:8001'))
            ->find(new ProductFinderQuery(text: '4 core 4mm2'));

        self::assertFalse($result->available);
        self::assertSame('unavailable', $result->status());
        self::assertNull($result->productCode());

        $sizing = $result->toArray()['sizing'];
        self::assertIsArray($sizing);
        self::assertFalse($sizing['available']);
        self::assertNotSame([], $result->toArray()['warnings']);
    }

    public function test_a_validation_error_from_the_finder_is_surfaced_not_masked(): void
    {
        // A 422 means the finder read the request and rejected it. Reporting
        // that as "unavailable" would send the customer looking for an outage.
        $http = new FakeJsonHttpClient();
        $http->status = 422;
        $http->body = json_encode([
            'success' => false,
            'error' => ['code' => 'VALIDATION_FAILED', 'message' => 'Give either a free-text requirement or a cable type.'],
        ], JSON_THROW_ON_ERROR);

        $result = (new HttpProductFinderClient($http, 'http://127.0.0.1:8001'))
            ->find(new ProductFinderQuery(text: 'x'));

        self::assertFalse($result->available);
        self::assertSame(
            'Give either a free-text requirement or a cable type.',
            $result->toArray()['detail'],
        );
    }

    public function test_a_malformed_body_is_not_mistaken_for_an_answer(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = 'not json at all';

        $result = (new HttpProductFinderClient($http, 'http://127.0.0.1:8001'))
            ->find(new ProductFinderQuery(text: 'x'));

        self::assertFalse($result->available);
        self::assertNull($result->productCode());
    }

    public function test_availability_is_learned_from_a_find_without_a_second_round_trip(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = json_encode(['success' => true, 'data' => ['status' => 'resolved']], JSON_THROW_ON_ERROR);

        $client = new HttpProductFinderClient($http, 'http://127.0.0.1:8001');
        $client->find(new ProductFinderQuery(text: 'x'));

        self::assertTrue($client->isAvailable());
        self::assertCount(1, $http->calls, 'isAvailable() must not re-probe after a successful find');
    }
}
