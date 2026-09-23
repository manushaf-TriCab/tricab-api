<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use DateTimeImmutable;
use DateTimeZone;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Onboarding\Application\DTO\OnboardingAssignmentView;
use TriCab\Core\Onboarding\Application\DTO\OnboardingTraineeRecord;
use TriCab\Core\Onboarding\Contract\OnboardingSelfServiceRepository;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingSelfServiceRepository;

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

    /** The id InMemoryApiUserRepository authenticates as in these tests. */
    private const SIGNED_IN_USER_ID = 1;

    private const SOMEBODY_ELSE_USER_ID = 2;

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
        // Counts since 4.5; an empty module answers zeros, and says as of when.
        self::assertSame(['waitingOnReview' => 0, 'needHelp' => 0], $data['queue']);
        self::assertSame(['current' => 0, 'departed' => 0, 'notYetInvited' => 0, 'nothingAllocated' => 0, 'finished' => 0], $data['people']);
        self::assertIsString($data['asOf']);
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

    // --------------------------------------------------- Phase 3.1 / 3.2

    /**
     * The signed-in person's own work, over HTTP, with the permission that
     * admits them and nothing else. `hasRecord` distinguishes "no induction"
     * from "an induction with nothing allocated yet", which the SPA needs in
     * order to say something other than an empty page.
     */
    public function test_self_summary_returns_the_signed_in_person_s_own_work(): void
    {
        $app = $this->createApp();
        $this->replacePermissions($app, ['account.login', 'ONBOARDING.SELF.READ']);
        $repository = $this->onboarding($app);
        $repository->withRecord(self::SIGNED_IN_USER_ID, $this->record());
        $repository->withAssignments(self::SIGNED_IN_USER_ID, [
            $this->assignment('mine-done', AssignmentStatus::COMPLETED),
            $this->assignment('mine-todo', AssignmentStatus::NOT_STARTED, '2026-10-01'),
        ]);

        $response = $app->handle(TestAppFactory::request('GET', self::SELF_SUMMARY));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);
        self::assertTrue($data['hasRecord']);
        self::assertSame(1, $data['actionableCount']);
        self::assertSame(50, $data['progressPercent']);

        $assignments = $data['assignments'];
        self::assertIsArray($assignments);
        self::assertCount(2, $assignments);

        $first = $assignments[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('mine-todo', $first['publicId'] ?? null, 'Actionable work comes first.');
    }

    /**
     * The invariant the whole self-service group exists to hold: the endpoint
     * takes no identifier, so another person's work is not merely filtered out
     * — there is no argument with which to ask for it.
     */
    public function test_self_summary_never_returns_another_person_s_work(): void
    {
        $app = $this->createApp();
        $this->replacePermissions($app, ['account.login', 'ONBOARDING.SELF.READ']);
        $repository = $this->onboarding($app);
        $repository->withRecord(self::SIGNED_IN_USER_ID, $this->record());
        $repository->withAssignments(self::SIGNED_IN_USER_ID, [
            $this->assignment('mine', AssignmentStatus::IN_PROGRESS),
        ]);
        $repository->withRecord(self::SOMEBODY_ELSE_USER_ID, $this->record());
        $repository->withAssignments(self::SOMEBODY_ELSE_USER_ID, [
            $this->assignment('theirs', AssignmentStatus::SUBMITTED),
        ]);

        $response = $app->handle(TestAppFactory::request('GET', self::SELF_SUMMARY));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode(), $body);
        self::assertStringContainsString('mine', $body);
        self::assertStringNotContainsString('theirs', $body);
    }

    /**
     * Not everybody has an induction. That is 200 with an empty summary, never
     * a 404 — an error page for every ordinary employee who followed the link
     * would be a worse answer than the true one.
     */
    public function test_self_summary_answers_two_hundred_for_somebody_with_no_induction(): void
    {
        $app = $this->createApp();
        $this->replacePermissions($app, ['account.login', 'ONBOARDING.SELF.READ']);

        $response = $app->handle(TestAppFactory::request('GET', self::SELF_SUMMARY));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);
        self::assertFalse($data['hasRecord']);
        self::assertSame([], $data['assignments']);
    }

    /**
     * A departed person past the fourteen-day window is refused with a code the
     * SPA can act on, rather than shown an empty induction that would read as
     * "you have nothing to do".
     */
    public function test_self_summary_refuses_a_leaver_whose_window_has_closed(): void
    {
        $app = $this->createApp();
        $this->replacePermissions($app, ['account.login', 'ONBOARDING.SELF.READ']);
        $repository = $this->onboarding($app);
        $repository->withRecord(
            self::SIGNED_IN_USER_ID,
            $this->record(new DateTimeImmutable('2020-01-01 09:00:00', new DateTimeZone('UTC'))),
        );

        $response = $app->handle(TestAppFactory::request('GET', self::SELF_SUMMARY));
        $payload = TestAppFactory::json($response);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        self::assertIsArray($payload['error'] ?? null);
        self::assertSame('ONBOARDING_ACCESS_WINDOW_CLOSED', $payload['error']['code'] ?? null);
    }

    private function record(?DateTimeImmutable $departedAtUtc = null): OnboardingTraineeRecord
    {
        return new OnboardingTraineeRecord('22222222-2222-4222-8222-222222222222', null, $departedAtUtc);
    }

    private function assignment(
        string $publicId,
        string $status,
        ?string $dueDate = null,
    ): OnboardingAssignmentView {
        return OnboardingAssignmentView::fromStorage(
            $publicId,
            'form',
            'bbbbbbbb-0000-4000-8000-000000000001',
            'Synthetic item',
            1,
            $status,
            $dueDate,
            '2026-09-01T00:00:00+00:00',
            null,
            null,
        );
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function onboarding(App $app): InMemoryOnboardingSelfServiceRepository
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $repository = $container->get(OnboardingSelfServiceRepository::class);
        self::assertInstanceOf(InMemoryOnboardingSelfServiceRepository::class, $repository);

        return $repository;
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
