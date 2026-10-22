<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Npr\Module;

/**
 * Every `/api/v1/npr*` route opens to exactly one permission, the one this table
 * names, and to no other (same proof as RfqRouteTableTest):
 *
 *  1. every route is in the app's route table and every NPR route in the table
 *     is listed here, so a new route cannot arrive ungated;
 *  2. with only its permission the route answers something other than 403;
 *  3. with every other NPR permission and the platform ones, it answers 403.
 */
final class NprRouteTableTest extends TestCase
{
    private const SAMPLE_ID = '11111111-1111-4111-8111-111111111111';

    /** @var array<string, string> "METHOD pattern" => permission */
    private const ROUTES = [
        'GET /api/v1/npr/me/requests' => Module::PERMISSION_SELF_READ,
        'POST /api/v1/npr/me/requests' => Module::PERMISSION_SELF_CREATE,
        'GET /api/v1/npr/me/requests/{publicId}' => Module::PERMISSION_SELF_READ,
        'PUT /api/v1/npr/me/requests/{publicId}' => Module::PERMISSION_SELF_CREATE,
        'DELETE /api/v1/npr/me/requests/{publicId}' => Module::PERMISSION_SELF_CREATE,
        'GET /api/v1/npr/tsd/requests' => Module::PERMISSION_TSD_READ,
        'GET /api/v1/npr/tsd/requests/{publicId}' => Module::PERMISSION_TSD_READ,
        'POST /api/v1/npr/me/requests/{publicId}/submit' => Module::PERMISSION_SELF_SUBMIT,
        'GET /api/v1/npr/team/requests' => Module::PERMISSION_TEAM_ENDORSE,
        'GET /api/v1/npr/team/requests/{publicId}' => Module::PERMISSION_TEAM_ENDORSE,
        'POST /api/v1/npr/team/requests/{publicId}/endorse' => Module::PERMISSION_TEAM_ENDORSE,
        'POST /api/v1/npr/team/requests/{publicId}/return' => Module::PERMISSION_TEAM_ENDORSE,
        'POST /api/v1/npr/team/requests/{publicId}/reject' => Module::PERMISSION_TEAM_ENDORSE,
        'POST /api/v1/npr/tsd/requests/{publicId}/acknowledge' => Module::PERMISSION_TSD_WORK,
        'POST /api/v1/npr/tsd/requests/{publicId}/assign' => Module::PERMISSION_TSD_WORK,
        'POST /api/v1/npr/tsd/requests/{publicId}/return' => Module::PERMISSION_TSD_WORK,
        'POST /api/v1/npr/tsd/requests/{publicId}/request-screening' => Module::PERMISSION_TSD_WORK,
        'GET /api/v1/npr/tsd/screening-signatories' => Module::PERMISSION_TSD_WORK,
        'PUT /api/v1/npr/tsd/requests/{publicId}/assessment' => Module::PERMISSION_TSD_WORK,
        'POST /api/v1/npr/tsd/requests/{publicId}/screening-decision' => Module::PERMISSION_SCREENING_DECIDE,
        'GET /api/v1/npr/admin/manager-routes' => Module::PERMISSION_ADMIN,
        'POST /api/v1/npr/admin/manager-routes' => Module::PERMISSION_ADMIN,
        'PUT /api/v1/npr/admin/manager-routes/{id}' => Module::PERMISSION_ADMIN,
        'GET /api/v1/npr/field-dictionary' => Module::PERMISSION_SELF_READ,
        'GET /api/v1/npr/tsd/field-dictionary' => Module::PERMISSION_TSD_READ,
        'GET /api/v1/npr/lookups/customers' => Module::PERMISSION_SELF_CREATE,
        'GET /api/v1/npr/lookups/contacts' => Module::PERMISSION_SELF_CREATE,
        'GET /api/v1/npr/me/requests/{publicId}/attachments' => Module::PERMISSION_SELF_READ,
        'POST /api/v1/npr/me/requests/{publicId}/attachments' => Module::PERMISSION_SELF_CREATE,
        'DELETE /api/v1/npr/me/requests/{publicId}/attachments/{attachmentPublicId}' => Module::PERMISSION_SELF_CREATE,
        'GET /api/v1/npr/me/requests/{publicId}/attachments/{attachmentPublicId}/download' => Module::PERMISSION_SELF_READ,
        'GET /api/v1/npr/team/requests/{publicId}/attachments' => Module::PERMISSION_TEAM_ENDORSE,
        'GET /api/v1/npr/team/requests/{publicId}/attachments/{attachmentPublicId}/download' => Module::PERMISSION_TEAM_ENDORSE,
        'GET /api/v1/npr/tsd/requests/{publicId}/attachments' => Module::PERMISSION_TSD_READ,
        'POST /api/v1/npr/tsd/requests/{publicId}/attachments' => Module::PERMISSION_TSD_WORK,
        'GET /api/v1/npr/tsd/requests/{publicId}/attachments/{attachmentPublicId}/download' => Module::PERMISSION_TSD_READ,
        'GET /api/v1/npr/admin/notifications' => Module::PERMISSION_ADMIN,
        'POST /api/v1/npr/admin/notifications/{id}/retry' => Module::PERMISSION_ADMIN,
    ];

    private const UNRELATED = ['ACCOUNT.LOGIN', 'QUOTE.READ', 'CUSTOMER.READ', 'RFQ.DRAFT.READ', 'TSD.ACCESS'];

    public function test_every_npr_route_is_in_the_table(): void
    {
        $registered = [];
        foreach (TestAppFactory::create()->getRouteCollector()->getRoutes() as $route) {
            if (!str_starts_with($route->getPattern(), '/api/v1/npr')) {
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
            $path = str_replace(['{publicId}', '{attachmentPublicId}', '{id}'], [self::SAMPLE_ID, self::SAMPLE_ID, '1'], $pattern);

            self::assertNotSame(403, $this->statusFor($method, $path, ['account.login', $permission]), $route . ' refused its own permission.');

            $others = array_values(array_diff([...Module::PERMISSIONS, ...self::UNRELATED], [$permission]));
            self::assertSame(403, $this->statusFor($method, $path, $others), $route . ' opened without ' . $permission . '.');
        }
    }

    /** @param list<string> $permissions */
    private function statusFor(string $method, string $path, array $permissions): int
    {
        /** @var App<ContainerInterface> $app */
        $app = TestAppFactory::create();
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, $permissions, ['EMPLOYEE']);

        $request = TestAppFactory::request($method, $path);
        if ($method !== 'GET') {
            $request = $request->withParsedBody(['version' => '0x01', 'answers' => [], 'variants' => []]);
        }

        return $app->handle($request)->getStatusCode();
    }
}
