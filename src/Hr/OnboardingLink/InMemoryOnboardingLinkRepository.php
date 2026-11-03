<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\OnboardingLink;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;

/** The link in memory, for `USER_REPOSITORY=memory` and tests. Mirrors the SQL rules. */
final class InMemoryOnboardingLinkRepository implements OnboardingLinkRepository
{
    public bool $isAvailable = true;

    /** @var array<string, array{profileId: int, userId: int, displayName: string, startDate: ?string, employeeCode: ?string, linkedAtUtc: ?string}> by person public id */
    private array $profiles = [];
    /** @var array<string, array{code: string, userId: ?int, displayName: ?string, firstName: ?string, lastName: ?string, dateHired: ?string}> by code */
    private array $employees = [];
    /** @var list<array{publicId: string, profileId: int, employeeCode: string, reason: string, status: string, proposedAtUtc: string}> */
    private array $proposals = [];
    /** @var list<OnboardingAdminEvent> */
    public array $events = [];
    private int $sequence = 0;

    public function withPerson(string $personPublicId, int $profileId, int $userId, string $displayName, ?string $startDate, ?string $employeeCode = null): self
    {
        $this->profiles[strtolower($personPublicId)] = [
            'profileId' => $profileId, 'userId' => $userId, 'displayName' => $displayName, 'startDate' => $startDate,
            'employeeCode' => $employeeCode, 'linkedAtUtc' => $employeeCode === null ? null : '2026-11-01T00:00:00.0000000',
        ];

        return $this;
    }

    public function withEmployee(string $code, ?int $userId, ?string $displayName, ?string $firstName, ?string $lastName, ?string $dateHired): self
    {
        $this->employees[$code] = [
            'code' => $code, 'userId' => $userId, 'displayName' => $displayName, 'firstName' => $firstName,
            'lastName' => $lastName, 'dateHired' => $dateHired,
        ];

        return $this;
    }

    public function available(): bool
    {
        return $this->isAvailable;
    }

    public function unlinkedStarters(): array
    {
        $starters = [];
        foreach ($this->profiles as $profile) {
            if ($profile['employeeCode'] === null) {
                $starters[] = [
                    'profileId' => $profile['profileId'], 'userId' => $profile['userId'],
                    'displayName' => $profile['displayName'], 'startDate' => $profile['startDate'],
                ];
            }
        }

        return $starters;
    }

    public function linkableEmployees(): array
    {
        return array_values(array_filter(
            $this->employees,
            fn (array $employee): bool => !$this->isLinked($employee['code']),
        ));
    }

    public function proposedPairs(): array
    {
        $pairs = [];
        foreach ($this->proposals as $proposal) {
            $pairs[$proposal['profileId'] . '|' . $proposal['employeeCode']] = true;
        }

        return $pairs;
    }

    public function addProposals(array $proposals): int
    {
        $stored = 0;
        foreach ($proposals as $proposal) {
            if (isset($this->proposedPairs()[$proposal['profileId'] . '|' . $proposal['employeeCode']])) {
                continue;
            }
            ++$this->sequence;
            $this->proposals[] = [
                'publicId' => sprintf('00000000-0000-4000-8000-%012d', $this->sequence),
                'profileId' => $proposal['profileId'], 'employeeCode' => $proposal['employeeCode'],
                'reason' => $proposal['reason'], 'status' => 'pending', 'proposedAtUtc' => '2026-11-03T00:00:00.0000000',
            ];
            ++$stored;
        }

        return $stored;
    }

    public function profile(string $personPublicId): ?array
    {
        $profile = $this->profiles[strtolower($personPublicId)] ?? null;

        return $profile === null ? null : [
            'profileId' => $profile['profileId'], 'userId' => $profile['userId'],
            'employeeCode' => $profile['employeeCode'], 'linkedAtUtc' => $profile['linkedAtUtc'],
        ];
    }

    public function personPublicIdForEmployee(string $employeeCode): ?string
    {
        foreach ($this->profiles as $publicId => $profile) {
            if ($profile['employeeCode'] === $employeeCode) {
                return $publicId;
            }
        }

        return null;
    }

    public function pendingProposals(int $profileId): array
    {
        $rows = [];
        foreach ($this->proposals as $proposal) {
            if ($proposal['profileId'] === $profileId && $proposal['status'] === 'pending' && !$this->isLinked($proposal['employeeCode'])) {
                $rows[] = [
                    'publicId' => $proposal['publicId'], 'employeeCode' => $proposal['employeeCode'],
                    'reason' => $proposal['reason'], 'proposedAtUtc' => $proposal['proposedAtUtc'],
                ];
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$a['reason'] === 'login' ? 0 : 1] <=> [$b['reason'] === 'login' ? 0 : 1]);

        return $rows;
    }

    public function proposal(int $profileId, string $proposalPublicId): ?array
    {
        foreach ($this->proposals as $proposal) {
            if ($proposal['profileId'] === $profileId && $proposal['publicId'] === $proposalPublicId) {
                return ['publicId' => $proposal['publicId'], 'employeeCode' => $proposal['employeeCode'], 'status' => $proposal['status']];
            }
        }

        return null;
    }

    public function confirm(int $profileId, string $proposalPublicId, string $employeeCode, ActorContext $actor, OnboardingAdminEvent $event): void
    {
        $index = $this->pendingIndex($profileId, $proposalPublicId);
        if ($this->isLinked($employeeCode)) {
            throw OnboardingLinkRefused::employeeAlreadyLinked();
        }
        foreach ($this->profiles as $publicId => $profile) {
            if ($profile['profileId'] !== $profileId) {
                continue;
            }
            if ($profile['employeeCode'] !== null) {
                throw OnboardingLinkRefused::personAlreadyLinked();
            }
            $this->profiles[$publicId]['employeeCode'] = $employeeCode;
            $this->profiles[$publicId]['linkedAtUtc'] = '2026-11-03T00:00:00.0000000';
        }
        foreach ($this->proposals as $i => $proposal) {
            if ($proposal['status'] === 'pending' && ($proposal['profileId'] === $profileId || $proposal['employeeCode'] === $employeeCode)) {
                $this->proposals[$i]['status'] = $i === $index ? 'confirmed' : 'superseded';
            }
        }
        $this->events[] = $event;
    }

    public function dismiss(int $profileId, string $proposalPublicId, ActorContext $actor, OnboardingAdminEvent $event): void
    {
        $this->proposals[$this->pendingIndex($profileId, $proposalPublicId)]['status'] = 'dismissed';
        $this->events[] = $event;
    }

    public function unlink(int $profileId, ActorContext $actor, OnboardingAdminEvent $event): void
    {
        foreach ($this->profiles as $publicId => $profile) {
            if ($profile['profileId'] !== $profileId || $profile['employeeCode'] === null) {
                continue;
            }
            foreach ($this->proposals as $i => $proposal) {
                if ($proposal['profileId'] === $profileId && $proposal['employeeCode'] === $profile['employeeCode'] && $proposal['status'] === 'confirmed') {
                    $this->proposals[$i]['status'] = 'dismissed';
                }
            }
            $this->profiles[$publicId]['employeeCode'] = null;
            $this->profiles[$publicId]['linkedAtUtc'] = null;
            $this->events[] = $event;

            return;
        }

        throw OnboardingLinkRefused::notLinked();
    }

    /** @return list<array{publicId: string, profileId: int, employeeCode: string, reason: string, status: string, proposedAtUtc: string}> */
    public function proposals(): array
    {
        return $this->proposals;
    }

    private function isLinked(string $employeeCode): bool
    {
        return $this->personPublicIdForEmployee($employeeCode) !== null;
    }

    private function pendingIndex(int $profileId, string $proposalPublicId): int
    {
        foreach ($this->proposals as $i => $proposal) {
            if ($proposal['profileId'] === $profileId && $proposal['publicId'] === $proposalPublicId && $proposal['status'] === 'pending') {
                return $i;
            }
        }

        throw OnboardingLinkRefused::proposalDecided();
    }
}
