<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Finance\Ar\InMemoryArStore;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Module;
use TriCab\Core\Identity\Contract\UserRepository;

/** The AI-draft route through the real app with the AI service off: a typed 502, never an empty draft. */
final class FinanceArDraftRouteTest extends TestCase
{
    /** @var App<ContainerInterface> */
    private App $app;

    protected function setUp(): void
    {
        $this->app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AR_ENABLED' => 'true', 'FINANCE_AI_ENABLED' => 'false']);
        $store = $this->app->getContainer()->get(InMemoryArStore::class);
        self::assertInstanceOf(InMemoryArStore::class, $store);
        $store->insert(ArFixtures::record('r1'));
        $users = $this->app->getContainer()->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_ACCESS, Module::PERMISSION_AR_RECORDS_WRITE], ['EMPLOYEE']);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function post(string $id, array $body): array
    {
        $response = $this->app->handle(TestAppFactory::request('POST', '/api/v1/finance/ar/records/' . $id . '/email-draft')->withParsedBody($body));

        return [$response->getStatusCode(), TestAppFactory::json($response)];
    }

    public function test_statuses_and_codes(): void
    {
        [$status, $json] = $this->post('r1', ['instruction' => '']);
        self::assertSame(422, $status);
        $error = $json['error'];
        self::assertIsArray($error);
        self::assertSame('Tell the AI what the reply should say', $error['message']);

        [$status] = $this->post('ghost', ['instruction' => 'x']);
        self::assertSame(404, $status);

        [$status, $json] = $this->post('r1', ['instruction' => 'Thank them']);
        self::assertSame(502, $status);
        $error = $json['error'];
        self::assertIsArray($error);
        self::assertSame('FINANCE_AR_UPSTREAM_FAILED', $error['code']);
        self::assertIsString($error['message']);
        self::assertStringStartsWith('AI draft failed: ', $error['message']);
        self::assertSame('ai', $error['reason']);
    }
}
