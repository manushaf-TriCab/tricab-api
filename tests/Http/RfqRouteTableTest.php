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
 * Every `/api/v1/rfqs*` route opens to exactly one permission — the one this
 * table names — and to no other.
 *
 * This replaces the source's `test_deploy_bundle` allow-list checks and closes
 * the gap behind source quirk Q7, where six PHP-handled actions never reached
 * the permission map at all. Proved by behaviour, not by reading middleware:
 *
 *  1. every route is found in the app's route table, and every RFQ route in
 *     the table is listed here, so a new route cannot arrive ungated;
 *  2. with only its permission, the route answers something other than 403;
 *  3. with every other RFQ permission, and every platform permission a
 *     salesperson might plausibly hold, it answers 403.
 */
final class RfqRouteTableTest extends TestCase
{
    private const SAMPLE_ID = '11111111-1111-4111-8111-111111111111';

    /** @var array<string, string> "METHOD pattern" => permission */
    private const ROUTES = [
        'GET /api/v1/rfqs/me' => Module::PERMISSION_ACCESS,
        'GET /api/v1/rfqs' => Module::PERMISSION_DRAFT_READ,
        'GET /api/v1/rfqs/{publicId}' => Module::PERMISSION_DRAFT_READ,
        'POST /api/v1/rfqs/parse' => Module::PERMISSION_DRAFT_CREATE,
        'POST /api/v1/rfqs' => Module::PERMISSION_DRAFT_CREATE,
        'PATCH /api/v1/rfqs/{publicId}' => Module::PERMISSION_DRAFT_EDIT,
        'POST /api/v1/rfqs/readiness' => Module::PERMISSION_DRAFT_CREATE,
        'POST /api/v1/rfqs/{publicId}/readiness' => Module::PERMISSION_DRAFT_READ,
        'GET /api/v1/rfqs/lookups/customers' => Module::PERMISSION_DRAFT_EDIT,
        'GET /api/v1/rfqs/lookups/contacts' => Module::PERMISSION_DRAFT_EDIT,
        'GET /api/v1/rfqs/lookups/pay-terms' => Module::PERMISSION_DRAFT_EDIT,
        'GET /api/v1/rfqs/lookups/products' => Module::PERMISSION_DRAFT_EDIT,
        'POST /api/v1/rfqs/upload' => Module::PERMISSION_DRAFT_CREATE,
        'GET /api/v1/rfqs/{publicId}/reads' => Module::PERMISSION_DRAFT_READ,
        'POST /api/v1/rfqs/{publicId}/reads/{readId}' => Module::PERMISSION_DRAFT_EDIT,
        'GET /api/v1/rfqs/{publicId}/documents/{documentId}' => Module::PERMISSION_DRAFT_READ,
    ];

    /** Platform permissions that must never stand in for an RFQ one. */
    private const UNRELATED = ['ACCOUNT.LOGIN', 'QUOTE.READ', 'CUSTOMER.READ', 'PRODUCT.READ', 'PRODUCT.PRICING.VIEW'];

    public function test_every_rfq_route_is_in_the_table(): void
    {
        $registered = [];
        foreach (TestAppFactory::create(['RFQ_MODULE_ENABLED' => 'true'])->getRouteCollector()->getRoutes() as $route) {
            if (!str_starts_with($route->getPattern(), '/api/v1/rfqs')) {
                continue;
            }
            foreach ($route->getMethods() as $method) {
                $registered[] = $method . ' ' . $route->getPattern();
            }
        }
        sort($registered);
        $expected = array_keys(self::ROUTES);
        sort($expected);

        self::assertSame($expected, $registered);
    }

    public function test_each_route_opens_to_its_permission_alone(): void
    {
        foreach (self::ROUTES as $route => $permission) {
            [$method, $pattern] = explode(' ', $route, 2);
            $path = str_replace('{publicId}', self::SAMPLE_ID, $pattern);

            $with = $this->statusFor($method, $path, ['account.login', $permission]);
            self::assertNotSame(403, $with, $route . ' refused its own permission.');

            $others = array_values(array_diff(
                [...Module::PERSON_PERMISSIONS, ...Module::INTEGRATION_PERMISSIONS, ...self::UNRELATED],
                [$permission],
            ));
            self::assertSame(403, $this->statusFor($method, $path, $others), $route . ' opened without ' . $permission . '.');
        }
    }

    /**
     * @param list<string> $permissions
     */
    private function statusFor(string $method, string $path, array $permissions): int
    {
        /** @var App<ContainerInterface> $app */
        $app = TestAppFactory::create(['RFQ_MODULE_ENABLED' => 'true']);
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, $permissions, ['EMPLOYEE']);

        return $app->handle(TestAppFactory::request($method, $path))->getStatusCode();
    }
}
