<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Hr\OnboardingLink;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Hr\OnboardingLink\AzureSqlOnboardingLinkRepository;
use TriCab\Api\Hr\OnboardingLink\OnboardingLinkRefused;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Domain\ValueObject\AdminEventType;

/** The SQL the link repository sends, against a fake connection. */
final class AzureSqlOnboardingLinkRepositoryTest extends TestCase
{
    private function event(): OnboardingAdminEvent
    {
        return OnboardingAdminEvent::succeeded(AdminEventType::PERSON_EMPLOYEE_LINKED, 5, null, 'Linked.', ['employeeCode' => 'E1'], 'ONBOARDING.PEOPLE.MANAGE');
    }

    public function test_no_query_touches_the_restricted_table(): void
    {
        $connection = new FakeSqlConnection([]);
        $links = new AzureSqlOnboardingLinkRepository($connection);

        $links->available();
        $links->unlinkedStarters();
        $links->linkableEmployees();
        $links->proposedPairs();
        $links->profile('aaaaaaaa-0000-4000-8000-000000000001');
        $links->personPublicIdForEmployee('E1');
        $links->pendingProposals(1);
        $links->proposal(1, 'aaaaaaaa-0000-4000-8000-000000000001');

        self::assertNotEmpty($connection->calls);
        foreach ($connection->calls as $call) {
            self::assertStringNotContainsString('EmployeeRestrictedDetails', $call['sql']);
            self::assertStringNotContainsString('onboarding_hr', $call['sql']);
        }
    }

    public function test_only_current_unlinked_employees_are_offered(): void
    {
        $connection = new FakeSqlConnection([]);

        (new AzureSqlOnboardingLinkRepository($connection))->linkableEmployees();

        self::assertStringContainsString('e.[IsCurrent] = 1', $connection->calls[0]['sql']);
        self::assertStringContainsString('NOT EXISTS', $connection->calls[0]['sql']);
    }

    public function test_confirm_writes_in_one_transaction_and_audits(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['Affected' => 1]]); // the proposal claim
        $connection->queueFetch([]);                  // nobody holds the employee
        $connection->queueFetch([['Affected' => 1]]); // the link

        (new AzureSqlOnboardingLinkRepository($connection))->confirm(3, 'bbbbbbbb-0000-4000-8000-000000000001', 'E1', new ActorContext(5, 'p', 'hr@tricab.com'), $this->event());

        self::assertTrue($connection->beganTransaction);
        self::assertTrue($connection->committed);
        $sql = implode("\n", array_column($connection->calls, 'sql'));
        self::assertStringContainsString("[EmployeeCode] IS NULL", $sql);
        self::assertStringContainsString("N'superseded'", $sql);
        self::assertStringContainsString('[audit].[OnboardingAdminEvents]', $sql);
    }

    public function test_confirm_is_refused_and_rolled_back_when_the_employee_was_taken(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['Affected' => 1]]);
        $connection->queueFetch([['Taken' => 1]]);

        try {
            (new AzureSqlOnboardingLinkRepository($connection))->confirm(3, 'bbbbbbbb-0000-4000-8000-000000000001', 'E1', new ActorContext(5, 'p', 'hr@tricab.com'), $this->event());
            self::fail('expected a refusal');
        } catch (OnboardingLinkRefused $refusal) {
            self::assertSame(OnboardingLinkRefused::EMPLOYEE_ALREADY_LINKED, $refusal->errorCode);
        }
        self::assertTrue($connection->rolledBack);
        self::assertStringNotContainsString('[audit]', implode("\n", array_column($connection->calls, 'sql')));
    }

    public function test_confirm_of_an_already_decided_proposal_writes_nothing_else(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['Affected' => 0]]);

        $this->expectException(OnboardingLinkRefused::class);
        try {
            (new AzureSqlOnboardingLinkRepository($connection))->confirm(3, 'bbbbbbbb-0000-4000-8000-000000000001', 'E1', new ActorContext(5, 'p', 'hr@tricab.com'), $this->event());
        } finally {
            self::assertCount(1, $connection->calls);
        }
    }

    public function test_unlink_keeps_the_pair_on_record_as_dismissed(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['EmployeeCode' => 'E1']]);

        (new AzureSqlOnboardingLinkRepository($connection))->unlink(3, new ActorContext(5, 'p', 'hr@tricab.com'), $this->event());

        $sql = implode("\n", array_column($connection->calls, 'sql'));
        self::assertStringContainsString("[Status] = N'dismissed'", $sql);
        self::assertStringContainsString("[Status] = N'confirmed'", $sql);
        self::assertTrue($connection->committed);
    }

    public function test_addProposals_skips_a_pair_that_raced_in(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['Affected' => 1]]);
        $connection->queueFetch([['Affected' => 0]]);

        $stored = (new AzureSqlOnboardingLinkRepository($connection))->addProposals([
            ['profileId' => 1, 'employeeCode' => 'E1', 'reason' => 'login'],
            ['profileId' => 2, 'employeeCode' => 'E2', 'reason' => 'name_start_date'],
        ]);

        self::assertSame(1, $stored);
        self::assertStringContainsString('WHERE NOT EXISTS', $connection->calls[0]['sql']);
    }
}
