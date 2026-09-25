<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Service\PermissionAssurancePolicy;
use TriCab\Core\Identity\Domain\ValueObject\AuthenticationAssurance;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;

/**
 * Phase P — authorization depends on how the session proved itself, not only
 * on what it may do.
 *
 * The situation this guards: an employee who is never issued a TriCab mailbox
 * signs in with a six-digit code to a personal address. They must reach their
 * own onboarding, and they must not reach administration, whatever roles they
 * are given later.
 */
final class AuthenticationAssuranceTest extends TestCase
{
    public function test_assurance_ranks_providers_weakest_first(): void
    {
        $otp = AuthenticationAssurance::forProvider(ExternalProvider::emailOtp());
        $passkey = AuthenticationAssurance::forProvider(ExternalProvider::passkey());
        $entra = AuthenticationAssurance::forProvider(ExternalProvider::microsoftEntra());

        self::assertFalse($otp->satisfies($passkey));
        self::assertTrue($passkey->satisfies($otp));
        self::assertTrue($entra->satisfies($passkey));
        self::assertTrue($entra->satisfies($entra));
    }

    public function test_google_and_apple_are_low_assurance(): void
    {
        $low = AuthenticationAssurance::low();

        self::assertTrue(AuthenticationAssurance::forProvider(ExternalProvider::google())->equals($low));
        self::assertTrue(AuthenticationAssurance::forProvider(ExternalProvider::apple())->equals($low));
    }

    /**
     * A provider nobody has ranked yet loses reach rather than gaining it.
     */
    public function test_an_unknown_provider_is_treated_as_low(): void
    {
        self::assertTrue(
            AuthenticationAssurance::forProvider('some_future_provider')
                ->equals(AuthenticationAssurance::low()),
        );
    }

    public function test_self_service_onboarding_is_reachable_at_the_lowest_assurance(): void
    {
        self::assertTrue(PermissionAssurancePolicy::isSatisfiedBy(
            PermissionCode::fromString('ONBOARDING.SELF.READ'),
            AuthenticationAssurance::low(),
        ));
        self::assertTrue(PermissionAssurancePolicy::isSatisfiedBy(
            PermissionCode::fromString('ONBOARDING.SELF.SUBMIT'),
            AuthenticationAssurance::low(),
        ));
    }

    public function test_onboarding_administration_requires_more_than_an_emailed_code(): void
    {
        $administrative = [
            'ONBOARDING.PEOPLE.MANAGE',
            'ONBOARDING.CONTENT.MANAGE',
            'ONBOARDING.REVIEW',
            'ONBOARDING.REPORTS.READ',
            'ONBOARDING.ADMIN',
        ];

        foreach ($administrative as $code) {
            $permission = PermissionCode::fromString($code);

            self::assertFalse(
                PermissionAssurancePolicy::isSatisfiedBy($permission, AuthenticationAssurance::low()),
                $code . ' must not be reachable at low assurance',
            );
            self::assertTrue(
                PermissionAssurancePolicy::isSatisfiedBy($permission, AuthenticationAssurance::substantial()),
                $code . ' must be reachable with a passkey',
            );
            self::assertTrue(
                PermissionAssurancePolicy::isSatisfiedBy($permission, AuthenticationAssurance::high()),
                $code . ' must be reachable with Entra',
            );
        }
    }

    public function test_identity_administration_requires_more_than_an_emailed_code(): void
    {
        foreach (['USER.ROLE.ASSIGN', 'ROLE.PERMISSION.ASSIGN', 'MIGRATION.ADMIN'] as $code) {
            self::assertFalse(PermissionAssurancePolicy::isSatisfiedBy(
                PermissionCode::fromString($code),
                AuthenticationAssurance::low(),
            ), $code);
        }
    }

    /**
     * Anything not listed in the policy keeps its previous behaviour. This is
     * what makes the floor safe to introduce: it cannot lock anyone out of
     * something that worked before it existed.
     */
    public function test_an_unlisted_permission_is_unaffected(): void
    {
        self::assertTrue(PermissionAssurancePolicy::isSatisfiedBy(
            PermissionCode::fromString('PRODUCT.READ'),
            AuthenticationAssurance::low(),
        ));
    }

    /**
     * End to end through the middleware. Development authentication reports
     * the Microsoft Entra provider, so an administrative route stays reachable
     * — the floor must not break the existing development path.
     */
    public function test_an_administrative_route_remains_reachable_over_entra(): void
    {
        $app = TestAppFactory::create();
        $this->replacePermissions($app, ['account.login', 'ONBOARDING.PEOPLE.MANAGE']);

        $response = $app->handle(
            TestAppFactory::request('GET', '/api/v1/admin/onboarding/overview'),
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string> $permissionCodes
     */
    private function replacePermissions(App $app, array $permissionCodes): void
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $repository = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $repository);
        $repository->replacePermissions(1, $permissionCodes, ['EMPLOYEE']);
    }
}
