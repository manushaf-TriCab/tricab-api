<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Infrastructure;

use TriCab\Core\Onboarding\Application\DTO\OnboardingPolicyDetail;
use TriCab\Core\Onboarding\Contract\OnboardingPolicyRepository;
use TriCab\Core\Onboarding\Domain\Exception\ContentVersionSuperseded;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusTransition;

/**
 * The default binding, and what the HTTP tests run against.
 *
 * Composite-keyed on user id and assignment public id, so a cross-person test
 * cannot pass against a repository that has lost its ownership filter.
 *
 * {@see $declarations} keeps the declaration text each acknowledgement was
 * written with, so a test can prove it is the version's wording and not
 * something the request supplied — which is the whole reason the parameter
 * exists.
 */
final class InMemoryOnboardingPolicyRepository implements OnboardingPolicyRepository
{
    /** @var array<string, OnboardingPolicyDetail> */
    private array $details = [];

    /** @var list<string> */
    public array $declarations = [];

    /** @var list<StatusTransition> */
    public array $transitions = [];

    public function findPolicy(int $userId, string $assignmentPublicId): ?OnboardingPolicyDetail
    {
        return $this->details[self::key($userId, $assignmentPublicId)] ?? null;
    }

    public function acknowledge(
        int $userId,
        string $assignmentPublicId,
        string $expectedVersionPublicId,
        string $declarationText,
        ?string $idempotencyKey,
        ?StatusTransition $transition,
    ): OnboardingPolicyDetail {
        unset($idempotencyKey);

        $detail = $this->findPolicy($userId, $assignmentPublicId);

        if ($detail === null) {
            throw OnboardingAssignmentNotFound::forThisPerson();
        }

        if ($detail->versionPublicId !== $expectedVersionPublicId) {
            throw ContentVersionSuperseded::reload();
        }

        // Stands in for UQ_onboarding_PolicyAcknowledgements_UserVersion.
        if ($detail->isAcknowledged()) {
            throw OnboardingWriteConflict::alreadyRecorded('acknowledgement');
        }

        $this->declarations[] = $declarationText;

        if ($transition !== null) {
            $this->transitions[] = $transition;
        }

        $acknowledged = new OnboardingPolicyDetail(
            assignmentPublicId: $detail->assignmentPublicId,
            title: $detail->title,
            versionPublicId: $detail->versionPublicId,
            versionNumber: $detail->versionNumber,
            status: $transition?->to ?? $detail->status,
            contentBlocks: $detail->contentBlocks,
            declarationText: $detail->declarationText,
            effectiveFrom: $detail->effectiveFrom,
            acknowledgedAtUtc: '2026-09-22T00:00:00+00:00',
        );

        $this->details[self::key($userId, $assignmentPublicId)] = $acknowledged;

        return $acknowledged;
    }

    public function withPolicy(int $userId, OnboardingPolicyDetail $detail): void
    {
        $this->details[self::key($userId, $detail->assignmentPublicId)] = $detail;
    }

    public function clear(): void
    {
        $this->details = [];
        $this->declarations = [];
        $this->transitions = [];
    }

    private static function key(int $userId, string $assignmentPublicId): string
    {
        return $userId . ':' . $assignmentPublicId;
    }
}
