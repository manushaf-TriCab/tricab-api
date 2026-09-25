<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Application;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonRecord;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonSummary;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingProfileFields;
use TriCab\Core\Onboarding\Application\Service\CreateOnboardingPersonService;
use TriCab\Core\Onboarding\Application\Service\DeleteOnboardingPersonService;
use TriCab\Core\Onboarding\Application\Service\InviteOnboardingPersonService;
use TriCab\Core\Onboarding\Application\Service\UpdateOnboardingPersonProfileService;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\Exception\InvitationNotDelivered;
use TriCab\Core\Onboarding\Domain\Exception\InvitationNotPermitted;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingPersonAlreadyExists;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\Exception\PersonDeletionNotPermitted;
use TriCab\Core\Onboarding\Domain\Service\LeaverPolicy;
use TriCab\Core\Onboarding\Domain\ValueObject\AdminEventType;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingAccountDirectory;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingInvitationEmailSender;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingPeopleRepository;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;

/**
 * Phase 4.1 — adding a person, editing their profile, inviting them.
 *
 * Held here: an existing account is linked and never changed; a new one is the
 * least-privileged account there is; invariant 6 (the invitation carries no
 * code and HR never handles one); invariant 13 (every change and refusal
 * audited, with field names and never values); and a departure recorded as a
 * date rather than a deletion.
 */
final class PersonLifecycleTest extends TestCase
{
    private const HR = 40;
    private const BASE_URL = 'https://tricab.ai';

    private InMemoryOnboardingAccountDirectory $accounts;
    private InMemoryOnboardingPeopleRepository $people;
    private InMemoryOnboardingAdminAuditLog $audit;
    private InMemoryOnboardingInvitationEmailSender $sender;
    private CreateOnboardingPersonService $create;

    protected function setUp(): void
    {
        $this->accounts = new InMemoryOnboardingAccountDirectory();
        $this->people = new InMemoryOnboardingPeopleRepository();
        $this->audit = new InMemoryOnboardingAdminAuditLog();
        $this->sender = new InMemoryOnboardingInvitationEmailSender();
        $this->create = new CreateOnboardingPersonService(
            $this->accounts,
            $this->people,
            $this->audit,
            new class () implements ApplicationTransaction {
                public function run(callable $operation): mixed
                {
                    return $operation();
                }
            },
            new UuidV4Generator(),
        );
    }

    // ----------------------------------------------------------------- create

    public function test_a_new_address_gets_a_guest_account_and_an_induction_record(): void
    {
        $result = $this->create->create($this->actor(), '  Sam.New@Example.test ', 'Sam New', OnboardingProfileFields::of('Storeperson', null, 'Dandenong', '2026-10-06'));

        self::assertTrue($result['accountCreated']);
        self::assertCount(1, $this->accounts->created);
        self::assertSame(['userId' => 5000, 'email' => 'sam.new@example.test', 'displayName' => 'Sam New', 'actorUserId' => self::HR], $this->accounts->created[0]);
        self::assertSame('Storeperson', $result['detail']->person->position);
        self::assertTrue($result['detail']->canSignIn);

        $event = $this->people->writtenEvents[0];
        self::assertSame(AdminEventType::PERSON_CREATED, $event->eventType);
        self::assertSame(['accountCreated' => true], $event->details);
        self::assertStringNotContainsStringIgnoringCase('sam', $event->summary . $event->detailsJson(), 'no name or address in the audit row');
    }

    public function test_an_existing_account_is_linked_and_left_exactly_as_it_was(): void
    {
        // A pending legacy import: it cannot sign in, and adding it to
        // onboarding must not change that — activation is User Management's.
        $this->accounts->withAccount(77, 'ari@tricab.com', false);

        $result = $this->create->create($this->actor(), 'ari@tricab.com', '', OnboardingProfileFields::of(null, null, null, null));

        self::assertFalse($result['accountCreated']);
        self::assertSame([], $this->accounts->created);
        self::assertFalse($result['detail']->canSignIn);
        self::assertSame(['accountCreated' => false], $this->people->writtenEvents[0]->details);
    }

    public function test_a_second_record_for_the_same_person_is_refused_and_audited(): void
    {
        $this->accounts->withAccount(77, 'ari@tricab.com', true);
        $this->create->create($this->actor(), 'ari@tricab.com', '', OnboardingProfileFields::of(null, null, null, null));

        try {
            $this->create->create($this->actor(), 'ARI@tricab.com', '', OnboardingProfileFields::of(null, null, null, null));
            self::fail('Two induction records for one person.');
        } catch (OnboardingPersonAlreadyExists) {
        }

        self::assertSame('refused', $this->audit->entries[0]['event']->outcome);
        self::assertSame('already_exists', $this->audit->entries[0]['event']->details['reason']);
    }

    public function test_a_new_starter_needs_a_name_and_a_real_address(): void
    {
        foreach ([['new@example.test', '  '], ['not an address', 'Sam']] as [$email, $name]) {
            try {
                $this->create->create($this->actor(), $email, $name, OnboardingProfileFields::of(null, null, null, null));
                self::fail($email . ' was accepted.');
            } catch (InvalidOnboardingValue) {
            }
        }

        self::assertSame([], $this->accounts->created);
        self::assertSame([], $this->people->writtenEvents);
    }

    public function test_profile_fields_are_trimmed_bounded_and_dated(): void
    {
        $fields = OnboardingProfileFields::of('  Driver ', '', null, '2026-10-06');
        self::assertSame('Driver', $fields->position);
        self::assertNull($fields->department);

        foreach ([[str_repeat('x', 201), null], [null, '06/10/2026'], [null, '2026-02-30']] as [$position, $start]) {
            try {
                OnboardingProfileFields::of($position, null, null, $start);
                self::fail('Accepted ' . var_export([$position, $start], true));
            } catch (InvalidOnboardingValue) {
            }
        }
    }

    // ---------------------------------------------------------------- profile

    public function test_an_edit_records_which_fields_changed_and_never_what_they_say(): void
    {
        [$publicId, $token] = $this->person();
        $update = new UpdateOnboardingPersonProfileService($this->people, $this->accounts);

        $update->update($this->actor(), $publicId, $token, OnboardingProfileFields::of('Senior storeperson', null, 'Dandenong', null), null);

        $event = $this->people->writtenEvents[0];
        self::assertSame(AdminEventType::PERSON_PROFILE_UPDATED, $event->eventType);
        self::assertSame(['changedFields' => ['position']], $event->details);
        self::assertStringNotContainsString('Senior', $event->summary . $event->detailsJson());
    }

    public function test_an_edit_that_changes_nothing_writes_nothing(): void
    {
        [$publicId, $token] = $this->person();
        $update = new UpdateOnboardingPersonProfileService($this->people, $this->accounts);

        $update->update($this->actor(), $publicId, $token, OnboardingProfileFields::of('Storeperson', null, 'Dandenong', null), null);

        self::assertSame([], $this->people->writtenEvents);
    }

    public function test_a_last_day_becomes_the_departure_and_can_be_cleared(): void
    {
        [$publicId, $token] = $this->person();
        $update = new UpdateOnboardingPersonProfileService($this->people, $this->accounts);
        $fields = OnboardingProfileFields::of('Storeperson', null, 'Dandenong', null);

        $detail = $update->update($this->actor(), $publicId, $token, $fields, '2026-10-31');
        self::assertSame('2026-10-31', $detail->person->lastDay());
        self::assertEquals(
            new DateTimeImmutable('2026-10-31T13:00:00', new DateTimeZone('UTC')),
            $this->people->findPerson($publicId)?->departedAtUtc,
            'midnight at the end of the last day, Melbourne summer time',
        );
        self::assertSame(['changedFields' => ['lastDay'], 'fromLastDay' => null, 'toLastDay' => '2026-10-31'], $this->people->writtenEvents[0]->details);

        $newToken = $this->people->findPerson($publicId)?->rowVersion;
        self::assertNotNull($newToken);
        $cleared = $update->update($this->actor(), $publicId, $newToken, $fields, null);
        self::assertNull($cleared->person->lastDay());
        self::assertNull($this->people->findPerson($publicId)?->departedAtUtc);
    }

    public function test_a_stale_profile_save_is_a_conflict(): void
    {
        [$publicId] = $this->person();
        $update = new UpdateOnboardingPersonProfileService($this->people, $this->accounts);

        $this->expectException(OnboardingWriteConflict::class);
        $update->update($this->actor(), $publicId, RowVersionToken::fromBinary("\0\0\0\0\0\0\0\x09"), OnboardingProfileFields::of('Changed', null, null, null), null);
    }

    public function test_the_last_day_is_a_melbourne_date_in_winter_and_summer(): void
    {
        self::assertSame('2026-06-30T14:00:00+00:00', LeaverPolicy::departureForLastDay('2026-06-30')->format(DATE_ATOM));
        self::assertSame('2026-12-31T13:00:00+00:00', LeaverPolicy::departureForLastDay('2026-12-31')->format(DATE_ATOM));
        foreach (['2026-06-30', '2026-12-31', '2026-04-05', '2026-10-04'] as $day) {
            self::assertSame($day, LeaverPolicy::lastDayFor(LeaverPolicy::departureForLastDay($day)), $day);
        }
    }

    // ------------------------------------------------------------- invitation

    public function test_an_invitation_links_to_onboarding_carries_no_code_and_is_audited(): void
    {
        [$publicId] = $this->person();

        $detail = $this->inviter()->invite($this->actor(), $publicId);

        self::assertSame([['to' => 'sam@example.test', 'displayName' => 'Sam Example', 'onboardingUrl' => 'https://tricab.ai/onboarding']], $this->sender->sent);
        self::assertNotNull($detail->person->invitedAtUtc);
        // Written with the stamp, inside the repository's transaction.
        self::assertSame([AdminEventType::PERSON_INVITED], array_map(static fn ($e) => $e->eventType, $this->people->writtenEvents));
        self::assertSame([], $this->audit->entries);
    }

    public function test_a_double_click_sends_one_invitation(): void
    {
        [$publicId] = $this->person();
        $inviter = $this->inviter();
        $inviter->invite($this->actor(), $publicId);

        try {
            $inviter->invite($this->actor(), $publicId);
            self::fail('Sent twice.');
        } catch (InvitationNotPermitted $refusal) {
            self::assertSame(InvitationNotPermitted::REASON_TOO_SOON, $refusal->reason);
        }

        self::assertCount(1, $this->sender->sent);
    }

    public function test_nobody_is_invited_who_cannot_sign_in_or_has_left(): void
    {
        [$pending] = $this->person(canSignIn: false);
        [$leaver] = $this->person(userId: 8, email: 'leaver@example.test', departed: new DateTimeImmutable('2026-09-01T00:00:00Z'));

        foreach ([[$pending, InvitationNotPermitted::REASON_CANNOT_SIGN_IN], [$leaver, InvitationNotPermitted::REASON_DEPARTED]] as [$publicId, $reason]) {
            try {
                $this->inviter()->invite($this->actor(), $publicId);
                self::fail('Invited somebody who ' . $reason);
            } catch (InvitationNotPermitted $refusal) {
                self::assertSame($reason, $refusal->reason);
            }
        }

        self::assertSame([], $this->sender->sent);
        self::assertSame(['cannot_sign_in', 'departed'], array_map(static fn (array $e) => $e['event']->details['reason'] ?? null, $this->audit->entries));
    }

    public function test_a_refused_send_leaves_no_invitation_on_record(): void
    {
        [$publicId] = $this->person();
        $this->sender->failNext = true;

        try {
            $this->inviter()->invite($this->actor(), $publicId);
            self::fail('A failed send reported success.');
        } catch (InvitationNotDelivered) {
        }

        self::assertNull($this->people->personDetail($publicId)?->person->invitedAtUtc, 'InvitedAtUtc always means an email went');
        // The trail keeps both rows, each written with its change: the claim,
        // then the refusal that put the stamp back.
        self::assertSame(
            [[AdminEventType::PERSON_INVITED, null], [AdminEventType::PERSON_INVITATION_REFUSED, 'delivery_failed']],
            array_map(static fn ($e) => [$e->eventType, $e->details['reason'] ?? null], $this->people->writtenEvents),
        );
        self::assertSame([], $this->audit->entries);

        // And the stamp being put back means a retry is not blocked as "too soon".
        $this->inviter()->invite($this->actor(), $publicId);
        self::assertCount(1, $this->sender->sent);
    }

    // --------------------------------------------------------------- helpers

    // ----------------------------------------------------------------- delete

    public function test_a_record_made_by_mistake_is_deleted_and_the_guest_account_made_for_it_disabled(): void
    {
        $created = $this->create->create($this->actor(), 'oops@example.test', 'Oops Twice', OnboardingProfileFields::of(null, null, null, null));
        $publicId = $created['detail']->person->publicId;
        $record = $this->people->findPerson($publicId);
        self::assertNotNull($record);

        $result = $this->deleter()->delete($this->actor(), $publicId, $record->rowVersion);

        self::assertSame(['accountDisabled' => true], $result);
        self::assertNull($this->people->findPerson($publicId));
        self::assertSame([5000], $this->accounts->disabled);
        $event = end($this->people->writtenEvents);
        self::assertNotFalse($event);
        self::assertSame(AdminEventType::PERSON_DELETED, $event->eventType);
        self::assertSame(['accountDisabled' => true], $event->details);
    }

    /**
     * An employee linked to a record — or a GUEST somebody has since given
     * another role — keeps their account exactly as it was.
     */
    public function test_an_account_onboarding_did_not_make_is_left_alone(): void
    {
        [$linked, $linkedToken] = $this->person(77, 'ari@tricab.com');
        self::assertSame(['accountDisabled' => false], $this->deleter()->delete($this->actor(), $linked, $linkedToken));

        $created = $this->create->create($this->actor(), 'promoted@example.test', 'Since Promoted', OnboardingProfileFields::of(null, null, null, null));
        $this->accounts->notOnboardingOnly[] = 5000;
        $record = $this->people->findPerson($created['detail']->person->publicId);
        self::assertNotNull($record);
        self::assertSame(['accountDisabled' => false], $this->deleter()->delete($this->actor(), $record->publicId, $record->rowVersion));

        self::assertSame([], $this->accounts->disabled);
    }

    public function test_a_person_with_recorded_work_is_refused_audited_and_untouched(): void
    {
        [$publicId, $token] = $this->person();
        $this->people->withRecordedWork(7, ['acknowledgements' => 2, 'form_submissions' => 1]);

        try {
            $this->deleter()->delete($this->actor(), $publicId, $token);
            self::fail('A person with acknowledgements was deleted.');
        } catch (PersonDeletionNotPermitted $refused) {
            self::assertStringContainsString('2 acknowledgements, 1 form submissions', $refused->getMessage());
            self::assertStringContainsString('last day', $refused->getMessage());
        }

        self::assertNotNull($this->people->findPerson($publicId));
        self::assertSame([], $this->accounts->disabled);
        $refusal = end($this->audit->entries);
        self::assertNotFalse($refusal);
        self::assertSame(AdminEventType::PERSON_DELETED, $refusal['event']->eventType);
        self::assertSame(['reason' => 'has_recorded_work', 'acknowledgements' => 2, 'form_submissions' => 1], $refusal['event']->details);
    }

    public function test_deleting_with_a_stale_token_is_a_conflict(): void
    {
        [$publicId] = $this->person();

        $this->expectException(OnboardingWriteConflict::class);

        $this->deleter()->delete($this->actor(), $publicId, RowVersionToken::fromBinary(str_repeat("\x09", 8)));
    }

    private function deleter(): DeleteOnboardingPersonService
    {
        return new DeleteOnboardingPersonService(
            $this->people,
            $this->accounts,
            $this->audit,
            new class () implements ApplicationTransaction {
                public function run(callable $operation): mixed
                {
                    return $operation();
                }
            },
        );
    }

    /**
     * @return array{0: string, 1: RowVersionToken}
     */
    private function person(int $userId = 7, string $email = 'sam@example.test', bool $canSignIn = true, ?DateTimeImmutable $departed = null): array
    {
        $publicId = sprintf('aaaaaaaa-0000-4000-8000-%012d', $userId);
        $token = RowVersionToken::fromBinary("\0\0\0\0\0\0\x01" . chr($userId));
        $this->accounts->withAccount($userId, $email, $canSignIn);
        $this->people->withPerson(
            new OnboardingPersonRecord($userId, $publicId, $token, $departed, $email, 'Sam Example'),
            new OnboardingPersonSummary($publicId, 'Sam Example', $email, 'Storeperson', null, 'Dandenong', null, $departed?->format('Y-m-d\TH:i:s'), null, []),
        );

        return [$publicId, $token];
    }

    private function inviter(): InviteOnboardingPersonService
    {
        return new InviteOnboardingPersonService(
            $this->people,
            $this->accounts,
            $this->sender,
            $this->audit,
            new FixedClock(new DateTimeImmutable('2026-09-23T00:00:00', new DateTimeZone('UTC'))),
            self::BASE_URL . '/',
        );
    }

    private function actor(): ActorContext
    {
        return new ActorContext(self::HR, 'hr-public-id', 'hr@example.test');
    }
}
