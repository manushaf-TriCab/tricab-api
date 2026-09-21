<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;

/**
 * Phase 1 authorization skeleton for the Onboarding module.
 *
 * The point of these tests is deny-by-default: every gate refuses before the
 * permission exists, the self gate opens nothing administrative, and the HR
 * gate opens nothing merely because someone is signed in. They are written
 * against permissions that are not yet seeded into `identity.Permissions` on
 * purpose — the in-memory repository is the authority here, and the real seed
 * lands with the schema in Phase 2.
 */
final class OnboardingEndpointsTest extends TestCase
{
    private const SELF_SUMMARY = '/api/v1/onboarding/me';
    private const ADMIN_OVERVIEW = '/api/v1/admin/onboarding/overview';

    public function test_self_summary_is_denied_without_the_self_read_permission(): void
    {
        $app = $this->createApp();
        $this->replacePermissions($app, ['account.login']);

        $response = $app->handle(TestAppFactory::request('GET', self::SELF_SUMMARY));

        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
    }

    public function test_self_summary_is_allowed_with_the_self_read_permission(): void
    {
        $app = $this->createApp();
        $this->replacePermissions($app, ['account.login', 'ONBOARDING.SELF.READ']);

        $response = $app->handle(TestAppFactory::request('GET', self::SELF_SUMMARY));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);
        self::assertSame([], $data['assignments']);
        self::assertSame(
            ['not_started', 'in_progress', 'need_help', 'submitted', 'fix_and_resubmit', 'completed'],
            $data['statuses'],
        );
    }

    /**
     * The warehouse-operator case. Somebody who authenticates by email OTP
     * against a personal address, and holds no corporate mailbox, still reaches
     * their own onboarding — access follows the permission, never the login
     * method (work order §4.15).
     */
    public function test_self_summary_does_not_depend_on_holding_an_employee_role(): void
    {
        $app = $this->createApp();
        $this->replacePermissions($app, ['account.login', 'ONBOARDING.SELF.READ'], ['GUEST']);

        $response = $app->handle(TestAppFactory::request('GET', self::SELF_SUMMARY));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    public function test_admin_overview_is_denied_to_a_signed_in_person(): void
    {
        $app = $this->createApp();
        $this->replacePermissions($app, ['account.login']);

        $response = $app->handle(TestAppFactory::request('GET', self::ADMIN_OVERVIEW));

        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * Self-service is not a foot in the door. Holding every ONBOARDING.SELF.*
     * permission must still reach nothing in the HR group.
     */
    public function test_self_permissions_do_not_reach_hr_administration(): void
    {
        $app = $this->createApp();
        $this->replacePermissions(
            $app,
            ['account.login', 'ONBOARDING.SELF.READ', 'ONBOARDING.SELF.SUBMIT', 'ONBOARDING.ACCESS'],
        );

        $response = $app->handle(TestAppFactory::request('GET', self::ADMIN_OVERVIEW));

        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
    }

    public function test_admin_overview_is_allowed_with_people_manage(): void
    {
        $app = $this->createApp();
        $this->replacePermissions($app, ['account.login', 'ONBOARDING.PEOPLE.MANAGE']);

        $response = $app->handle(TestAppFactory::request('GET', self::ADMIN_OVERVIEW));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);
        self::assertSame([], $data['queue']);
        self::assertSame([], $data['people']);
    }

    /**
     * The HR gate is not a general onboarding gate either: managing people does
     * not imply reviewing, authoring content or reading reports. Those stay
     * separate permissions so the scopes can diverge (work order §9).
     */
    public function test_hr_gate_is_specific_rather_than_a_module_wide_pass(): void
    {
        $app = $this->createApp();
        $this->replacePermissions($app, ['account.login', 'ONBOARDING.REVIEW']);

        $response = $app->handle(TestAppFactory::request('GET', self::ADMIN_OVERVIEW));

        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * The `data` envelope, narrowed once so the assertions above read as
     * assertions rather than as type juggling.
     *
     * @return array<string, mixed>
     */
    private function data(ResponseInterface $response): array
    {
        $payload = TestAppFactory::json($response);
        self::assertArrayHasKey('data', $payload);
        $data = $payload['data'];
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @return App<ContainerInterface>
     */
    private function createApp(): App
    {
        return TestAppFactory::create();
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string> $permissionCodes
     * @param list<string> $roleCodes
     */
    private function replacePermissions(App $app, array $permissionCodes, array $roleCodes = ['EMPLOYEE']): void
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $repository = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $repository);
        $repository->replacePermissions(1, $permissionCodes, $roleCodes);
    }
}
