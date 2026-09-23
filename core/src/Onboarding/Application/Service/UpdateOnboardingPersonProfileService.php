<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonDetail;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingProfileFields;
use TriCab\Core\Onboarding\Contract\OnboardingAccountDirectory;
use TriCab\Core\Onboarding\Contract\OnboardingPeopleRepository;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingPersonNotFound;
use TriCab\Core\Onboarding\Domain\Service\LeaverPolicy;
use TriCab\Core\Onboarding\Domain\ValueObject\AdminEventType;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * HR edits where a person will work, when they start, and their last day (4.1).
 *
 * ## The last day is how a departure is recorded
 *
 * The source deleted a leaver's row, and every record with it. Here departure
 * is a date (the Phase 4 departures table): HR enters the last day, and
 * {@see LeaverPolicy::departureForLastDay()} turns it into the instant the rules
 * work from. Clearing it — somebody who withdrew their resignation — is allowed
 * and audited like any other change.
 *
 * A save that changes nothing writes nothing, not even an audit row.
 */
final readonly class UpdateOnboardingPersonProfileService
{
    public const PERMISSION = 'ONBOARDING.PEOPLE.MANAGE';

    public function __construct(
        private OnboardingPeopleRepository $people,
        private OnboardingAccountDirectory $accounts,
    ) {
    }

    public function update(
        ActorContext $actor,
        string $personPublicId,
        RowVersionToken $expectedRowVersion,
        OnboardingProfileFields $fields,
        ?string $lastDay,
    ): OnboardingPersonDetail {
        $publicId = strtolower(trim($personPublicId));
        $record = $this->people->findPerson($publicId) ?? throw OnboardingPersonNotFound::forPublicId($personPublicId);
        $current = $this->people->personDetail($publicId) ?? throw OnboardingPersonNotFound::forPublicId($personPublicId);

        $lastDay = $lastDay === null || trim($lastDay) === '' ? null : trim($lastDay);
        $departedAtUtc = $lastDay === null ? null : LeaverPolicy::departureForLastDay($lastDay);
        $fromLastDay = $current->person->lastDay();
        $departureChanged = $lastDay !== $fromLastDay;
        $changed = $fields->changedFrom($current->person);

        if ($changed !== [] || $departureChanged) {
            $events = [];
            if ($changed !== []) {
                $events[] = OnboardingAdminEvent::succeeded(
                    AdminEventType::PERSON_PROFILE_UPDATED,
                    $record->userId,
                    $record->publicId,
                    sprintf('Changed %s.', implode(', ', $changed)),
                    ['changedFields' => $changed],
                    self::PERMISSION,
                );
            }
            if ($departureChanged) {
                $events[] = OnboardingAdminEvent::succeeded(
                    AdminEventType::PERSON_PROFILE_UPDATED,
                    $record->userId,
                    $record->publicId,
                    $lastDay === null ? 'Cleared the last day.' : 'Recorded a last day.',
                    ['changedFields' => ['lastDay'], 'fromLastDay' => $fromLastDay, 'toLastDay' => $lastDay],
                    self::PERMISSION,
                );
            }

            $this->people->updateProfile($record, $expectedRowVersion, $fields, $departedAtUtc, $departureChanged, $events, $actor);
        }

        $detail = $this->people->personDetail($publicId) ?? throw OnboardingPersonNotFound::forPublicId($personPublicId);

        return $detail->withCanSignIn($this->accounts->canSignIn($record->userId));
    }
}
