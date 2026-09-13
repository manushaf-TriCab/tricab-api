<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Tests\TestAppFactory;

final class LegacyMigrationEndpointsTest extends TestCase
{
    public function test_overview_requires_permission_and_returns_framework_cards(): void
    {
        $app = TestAppFactory::create([]);
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/legacy-migration/overview'));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = TestAppFactory::json($response)['data'];
        self::assertArrayHasKey('sources', $data);
        self::assertArrayHasKey('modules', $data);
        self::assertFalse($data['modules'][0]['writesProductionTables']);
        self::assertGreaterThan(5, count($data['modules']));
    }

    public function test_batch_pipeline_never_writes_production_and_requires_approval(): void
    {
        $app = TestAppFactory::create([]);

        $create = $app->handle($this->json('POST', '/api/v1/admin/legacy-migration/batches', [
            'name' => 'Users harness',
            'moduleCode' => 'users',
        ]));
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
        $id = TestAppFactory::json($create)['data']['publicId'];
        self::assertSame('created', TestAppFactory::json($create)['data']['statusCode']);

        $executeEarly = $app->handle($this->json('POST', '/api/v1/admin/legacy-migration/batches/' . $id . '/execute', []));
        self::assertSame(409, $executeEarly->getStatusCode());

        foreach (['extract', 'normalize', 'validate'] as $step) {
            $response = $app->handle($this->json('POST', '/api/v1/admin/legacy-migration/batches/' . $id . '/' . $step, []));
            self::assertSame(200, $response->getStatusCode(), $step . ' ' . (string) $response->getBody());
        }

        $preview = $app->handle($this->json('POST', '/api/v1/admin/legacy-migration/batches/' . $id . '/preview', []));
        self::assertSame(200, $preview->getStatusCode(), (string) $preview->getBody());
        $previewData = TestAppFactory::json($preview)['data'];
        self::assertFalse($previewData['wouldWriteProductionTables']);
        self::assertTrue($previewData['requiresApproval']);

        $approveBlocked = $app->handle($this->json('POST', '/api/v1/admin/legacy-migration/batches/' . $id . '/approve', []));
        self::assertSame(409, $approveBlocked->getStatusCode(), (string) $approveBlocked->getBody());

        $conflicts = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/legacy-migration/batches/' . $id . '/conflicts'));
        self::assertSame(200, $conflicts->getStatusCode());
        $items = TestAppFactory::json($conflicts)['data']['items'];
        self::assertNotSame([], $items);
        $conflictId = $items[0]['publicId'];

        $resolved = $app->handle($this->json('POST', '/api/v1/admin/legacy-migration/conflicts/' . $conflictId . '/resolve', [
            'decisionCode' => 'ignore',
        ]));
        self::assertSame(200, $resolved->getStatusCode(), (string) $resolved->getBody());

        $approve = $app->handle($this->json('POST', '/api/v1/admin/legacy-migration/batches/' . $id . '/approve', []));
        self::assertSame(200, $approve->getStatusCode(), (string) $approve->getBody());

        $execute = $app->handle($this->json('POST', '/api/v1/admin/legacy-migration/batches/' . $id . '/execute', []));
        self::assertSame(200, $execute->getStatusCode(), (string) $execute->getBody());
        $executed = TestAppFactory::json($execute)['data'];
        self::assertSame('completed', $executed['statusCode']);
        self::assertGreaterThan(0, $executed['importedRecords']);

        $rollback = $app->handle($this->json('POST', '/api/v1/admin/legacy-migration/batches/' . $id . '/rollback', []));
        self::assertSame(200, $rollback->getStatusCode(), (string) $rollback->getBody());
        self::assertSame('rolled-back', TestAppFactory::json($rollback)['data']['statusCode']);
    }

    public function test_live_source_health_does_not_open_a_connection(): void
    {
        $app = TestAppFactory::create([]);
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/legacy-migration/sources/wordpress/health'));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = TestAppFactory::json($response)['data'];
        self::assertFalse($data['liveConnectionAttempted']);
        self::assertSame('blocked', $data['health']);
    }

    public function test_user_workspace_is_available_without_live_extraction(): void
    {
        $app = TestAppFactory::create([]);
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/legacy-migration/user-workspace'));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = TestAppFactory::json($response)['data'];
        self::assertFalse($data['productionUsersModified']);
        self::assertFalse($data['legacyDatabaseModified']);
        self::assertArrayHasKey('stats', $data);
        self::assertSame(0, $data['stats']['totalExtracted']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(string $method, string $path, array $body): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));
        $request->getBody()->rewind();

        return $request;
    }
}
