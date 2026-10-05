<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Rfq\Module;

/**
 * The header lookups where nothing is behind them (the in-memory test app):
 * an empty search is an empty answer, and anything else says the source is
 * unavailable rather than inventing customers or pay terms.
 */
final class RfqLookupRoutesTest extends TestCase
{
    /** @return array{int, array<string, mixed>} */
    private function get(string $path): array
    {
        /** @var App<ContainerInterface> $app */
        $app = TestAppFactory::create();
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_DRAFT_EDIT], ['EMPLOYEE']);

        $response = $app->handle(TestAppFactory::request('GET', $path));
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return [$response->getStatusCode(), $body];
    }

    public function test_an_empty_customer_search_is_an_empty_list(): void
    {
        [$status, $body] = $this->get('/api/v1/rfqs/lookups/customers?q=%20');

        self::assertSame(200, $status);
        self::assertSame(['items' => []], $body['data']);
    }

    public function test_contacts_for_no_customer_id_are_an_empty_list(): void
    {
        foreach (['', 'A%26HCON', '0', '-5', '12.5'] as $id) {
            [$status, $body] = $this->get('/api/v1/rfqs/lookups/contacts?customerId=' . $id);

            self::assertSame(200, $status, $id);
            self::assertSame(['items' => []], $body['data'], $id);
        }
    }

    public function test_lookups_with_no_source_behind_them_are_unavailable(): void
    {
        foreach (['/api/v1/rfqs/lookups/customers?q=A%26H', '/api/v1/rfqs/lookups/contacts?customerId=37193', '/api/v1/rfqs/lookups/pay-terms'] as $path) {
            [$status, $body] = $this->get($path);

            self::assertSame(503, $status, $path);
            self::assertIsArray($body['error']);
            self::assertSame('RFQ_LOOKUP_UNAVAILABLE', $body['error']['code'], $path);
        }
    }
}
