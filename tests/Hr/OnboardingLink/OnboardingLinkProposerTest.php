<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Hr\OnboardingLink;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Hr\OnboardingLink\InMemoryOnboardingLinkRepository;
use TriCab\Api\Hr\OnboardingLink\OnboardingLinkProposer;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Domain\ValueObject\AdminEventType;

final class OnboardingLinkProposerTest extends TestCase
{
    private InMemoryOnboardingLinkRepository $links;

    protected function setUp(): void
    {
        $this->links = (new InMemoryOnboardingLinkRepository())
            ->withPerson('aaaaaaaa-0000-4000-8000-000000000001', 1, 101, 'Ann Lee', '2026-11-02')
            ->withPerson('aaaaaaaa-0000-4000-8000-000000000002', 2, 102, 'Bob Ray', null)
            ->withPerson('aaaaaaaa-0000-4000-8000-000000000003', 3, 103, 'Already Linked', '2026-11-02', 'E9')
            ->withEmployee('E1', null, 'Ann Lee', 'Ann', 'Lee', '2026-11-03')
            ->withEmployee('E2', 102, 'Robert Ray', 'Robert', 'Ray', null)
            ->withEmployee('E9', null, 'Already Linked', 'Already', 'Linked', '2026-11-02');
    }

    public function test_it_proposes_by_login_and_by_name_with_start_date_and_skips_linked_people(): void
    {
        $result = (new OnboardingLinkProposer($this->links))->propose();

        self::assertSame(['starters' => 2, 'proposed' => 2], $result);
        $byProfile = [];
        foreach ($this->links->proposals() as $proposal) {
            $byProfile[$proposal['profileId']] = [$proposal['employeeCode'], $proposal['reason'], $proposal['status']];
        }
        self::assertSame(['E1', 'name_start_date', 'pending'], $byProfile[1]);
        self::assertSame(['E2', 'login', 'pending'], $byProfile[2]);
        self::assertArrayNotHasKey(3, $byProfile);
        self::assertSame('aaaaaaaa-0000-4000-8000-000000000003', $this->links->personPublicIdForEmployee('E9'), 'proposing never changes a link');
    }

    public function test_a_second_run_proposes_nothing_new_and_a_dismissed_pair_stays_dismissed(): void
    {
        $proposer = new OnboardingLinkProposer($this->links);
        $proposer->propose();
        $first = $this->links->proposals()[0];
        $this->links->dismiss(1, $first['publicId'], new ActorContext(1, 'p', 'hr@tricab.com'), OnboardingAdminEvent::succeeded(
            AdminEventType::PERSON_EMPLOYEE_LINK_DISMISSED, 101, null, 'x', [], 'ONBOARDING.PEOPLE.MANAGE',
        ));

        $again = $proposer->propose();

        self::assertSame(['starters' => 2, 'proposed' => 0], $again);
        self::assertSame('dismissed', $this->links->proposals()[0]['status']);
    }

    public function test_it_does_nothing_until_the_schema_exists(): void
    {
        $this->links->isAvailable = false;

        $result = (new OnboardingLinkProposer($this->links))->propose();

        self::assertArrayHasKey('skipped', $result);
        self::assertSame([], $this->links->proposals());
    }
}
