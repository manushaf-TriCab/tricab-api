<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Hr\OnboardingLink;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Hr\OnboardingLink\InMemoryOnboardingLinkRepository;
use TriCab\Api\Hr\OnboardingLink\OnboardingLinkProposer;
use TriCab\Api\Hr\OnboardingLink\OnboardingLinkRefused;
use TriCab\Api\Hr\OnboardingLink\OnboardingLinkService;
use TriCab\Api\Tests\Hr\Employees\Directory\EmployeeDirectoryFixtures;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Domain\ValueObject\AdminEventType;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingPeopleRepository;

final class OnboardingLinkServiceTest extends TestCase
{
    private const ANN = 'aaaaaaaa-0000-4000-8000-000000000001';
    private const ANNA = 'aaaaaaaa-0000-4000-8000-000000000002';

    private InMemoryOnboardingLinkRepository $links;
    private OnboardingLinkService $service;
    private ActorContext $actor;

    protected function setUp(): void
    {
        $this->actor = new ActorContext(7, 'hr-public', 'hr@tricab.com');
        // Two starters could both be "Ann Lee" (E002 in the fixtures, hired 2015... so use a fresh code instead).
        $this->links = (new InMemoryOnboardingLinkRepository())
            ->withPerson(self::ANN, 1, 101, 'Chris Owen', '2010-01-05')
            ->withPerson(self::ANNA, 2, 102, 'Chris Owen', '2010-01-06')
            ->withEmployee('E001', null, 'Chris Owen', 'Chris', 'Owen', '2010-01-04');
        (new OnboardingLinkProposer($this->links))->propose();
        $this->service = new OnboardingLinkService($this->links, EmployeeDirectoryFixtures::directory(), new InMemoryOnboardingPeopleRepository());
    }

    public function test_a_person_sees_their_pending_matches_with_base_employee_fields_only(): void
    {
        $view = $this->service->forPerson(strtoupper(self::ANN));

        self::assertNull($view['linked']);
        self::assertCount(1, $view['proposals']);
        $proposal = $view['proposals'][0];
        self::assertSame('name_start_date', $proposal['reason']);
        self::assertSame(['code', 'displayName', 'department', 'factory', 'dateHired', 'isCurrent', 'lastSeenInPayroll', 'inPayrollSince'], array_keys($proposal['employee']));
        self::assertSame('2010-01-04', $proposal['employee']['inPayrollSince']);
    }

    public function test_confirming_links_audits_and_supersedes_the_other_persons_proposal(): void
    {
        $proposalId = $this->proposalId(self::ANN);

        $view = $this->service->confirm($this->actor, self::ANN, $proposalId);

        self::assertNotNull($view['linked']);
        self::assertNotNull($view['linked']['employee']);
        self::assertSame('E001', $view['linked']['employee']['code']);
        self::assertSame([], $view['proposals']);
        self::assertSame(self::ANN, $this->links->personPublicIdForEmployee('E001'));
        self::assertSame(['confirmed', 'superseded'], array_column($this->links->proposals(), 'status'));
        self::assertSame([AdminEventType::PERSON_EMPLOYEE_LINKED], array_column(array_map(static fn ($e): array => ['t' => $e->eventType], $this->links->events), 't'));
        self::assertSame(['employeeCode' => 'E001'], $this->links->events[0]->details);
        self::assertSame([], $this->service->forPerson(self::ANNA)['proposals'], 'the other person no longer sees a taken employee');
    }

    public function test_a_decided_proposal_and_a_linked_person_are_refused(): void
    {
        $proposalId = $this->proposalId(self::ANN);
        $this->service->confirm($this->actor, self::ANN, $proposalId);

        $this->expectRefusal(OnboardingLinkRefused::PERSON_ALREADY_LINKED, fn () => $this->service->confirm($this->actor, self::ANN, $proposalId));
        $this->expectRefusal(OnboardingLinkRefused::PROPOSAL_DECIDED, fn () => $this->service->dismiss($this->actor, self::ANNA, $this->proposalIdIncludingDecided(self::ANNA)));
        $this->expectRefusal(OnboardingLinkRefused::PROPOSAL_NOT_FOUND, fn () => $this->service->dismiss($this->actor, self::ANNA, $proposalId));
        $this->expectRefusal(OnboardingLinkRefused::PERSON_NOT_FOUND, fn () => $this->service->forPerson('00000000-0000-4000-8000-00000000ffff'));
        $this->expectRefusal(OnboardingLinkRefused::NOT_LINKED, fn () => $this->service->unlink($this->actor, self::ANNA));
    }

    public function test_dismiss_is_audited_and_never_reproposed(): void
    {
        $this->service->dismiss($this->actor, self::ANN, $this->proposalId(self::ANN));

        self::assertSame([], $this->service->forPerson(self::ANN)['proposals']);
        self::assertSame(AdminEventType::PERSON_EMPLOYEE_LINK_DISMISSED, $this->links->events[0]->eventType);
        self::assertSame(0, (new OnboardingLinkProposer($this->links))->propose()['proposed'] ?? -1);
    }

    public function test_unlink_clears_the_link_keeps_the_pair_dismissed_and_audits(): void
    {
        $this->service->confirm($this->actor, self::ANN, $this->proposalId(self::ANN));

        $view = $this->service->unlink($this->actor, self::ANN);

        self::assertNull($view['linked']);
        self::assertNull($this->links->personPublicIdForEmployee('E001'));
        self::assertSame('dismissed', $this->links->proposals()[0]['status']);
        self::assertSame(AdminEventType::PERSON_EMPLOYEE_UNLINKED, $this->links->events[1]->eventType);
        (new OnboardingLinkProposer($this->links))->propose();
        self::assertCount(2, $this->links->proposals(), 'the unlinked pair is not offered again');
    }

    public function test_an_employee_with_no_linked_onboarding_record_reports_null(): void
    {
        self::assertNull($this->service->forEmployee('E001'));
    }

    private function proposalId(string $person): string
    {
        $view = $this->service->forPerson($person);
        self::assertNotEmpty($view['proposals']);

        return $view['proposals'][0]['publicId'];
    }

    private function proposalIdIncludingDecided(string $person): string
    {
        foreach ($this->links->proposals() as $proposal) {
            if ($proposal['profileId'] === ($this->links->profile($person)['profileId'] ?? 0)) {
                return $proposal['publicId'];
            }
        }
        self::fail('no proposal');
    }

    private function expectRefusal(string $code, callable $work): void
    {
        try {
            $work();
        } catch (OnboardingLinkRefused $refusal) {
            self::assertSame($code, $refusal->errorCode);

            return;
        }
        self::fail('Expected ' . $code);
    }
}
