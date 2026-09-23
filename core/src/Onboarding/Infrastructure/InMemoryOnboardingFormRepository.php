<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Infrastructure;

use TriCab\Core\Onboarding\Application\DTO\OnboardingFormDetail;
use TriCab\Core\Onboarding\Application\DTO\SplitFormPayload;
use TriCab\Core\Onboarding\Contract\OnboardingFormRepository;
use TriCab\Core\Onboarding\Domain\Exception\ContentVersionSuperseded;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusTransition;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * The default binding, and what the HTTP tests run against.
 *
 * {@see $restrictedWrites} is the important property of this double: the
 * payroll and restricted halves are kept in a **separate** map, exactly as they
 * go to a separate schema, so a test can assert that a bank account never
 * reached the ordinary submission. A double that stored the whole payload in one
 * place would let a broken splitter pass.
 *
 * Tokens are a counter rendered as eight bytes. They only need to be comparable
 * and to change on every write, and Phase 2.5 established that a real
 * `ROWVERSION` is database-wide rather than a per-record counter — so a double
 * that incremented per row would be modelling something the database does not
 * do. This one increments per store.
 */
final class InMemoryOnboardingFormRepository implements OnboardingFormRepository
{
    /** @var array<string, OnboardingFormDetail> */
    private array $details = [];

    /** @var array<string, array<string, int>> */
    private array $classifications = [];

    /** @var list<array{ordinary: array<string, mixed>, payroll: array<string, mixed>, restricted: array<string, mixed>}> */
    public array $submissions = [];

    /** @var list<array<string, mixed>> */
    public array $restrictedWrites = [];

    /** @var list<StatusTransition> */
    public array $transitions = [];

    private int $tokenCounter = 0;

    public function findForm(int $userId, string $assignmentPublicId): ?OnboardingFormDetail
    {
        return $this->details[self::key($userId, $assignmentPublicId)] ?? null;
    }

    public function fieldClassifications(string $formVersionPublicId): array
    {
        return $this->classifications[$formVersionPublicId] ?? [];
    }

    public function saveDraft(
        int $userId,
        string $assignmentPublicId,
        string $expectedVersionPublicId,
        array $draftData,
        ?RowVersionToken $expectedRowVersion,
        ?StatusTransition $transition,
    ): OnboardingFormDetail {
        $detail = $this->require($userId, $assignmentPublicId, $expectedVersionPublicId);

        if ($detail->draftRowVersion !== null
            && $expectedRowVersion?->toBase64() !== $detail->draftRowVersion
        ) {
            throw OnboardingWriteConflict::staleToken();
        }

        return $this->store($userId, $detail, $transition, [
            'draftData' => $draftData,
            'draftRowVersion' => $this->nextToken(),
            'draftUpdatedAtUtc' => '2026-09-22T00:00:00+00:00',
        ]);
    }

    public function submitForm(
        int $userId,
        string $assignmentPublicId,
        string $expectedVersionPublicId,
        SplitFormPayload $payload,
        ?string $idempotencyKey,
        ?StatusTransition $transition,
    ): OnboardingFormDetail {
        unset($idempotencyKey);

        $detail = $this->require($userId, $assignmentPublicId, $expectedVersionPublicId);

        $this->submissions[] = [
            'ordinary' => $payload->ordinary,
            'payroll' => $payload->payroll,
            'restricted' => $payload->restricted,
        ];

        if ($payload->hasRestrictedData()) {
            $this->restrictedWrites[] = array_merge($payload->payroll, $payload->restricted);
        }

        // Handing the form in throws the draft away, as the schema does: a draft
        // is scratch and keeping it beside an immutable submission is how the two
        // end up disagreeing.
        return $this->store($userId, $detail, $transition, [
            'draftData' => [],
            'draftRowVersion' => null,
            'draftUpdatedAtUtc' => null,
            'submittedRevisionCount' => $detail->submittedRevisionCount + 1,
            'lastSubmittedAtUtc' => '2026-09-22T00:00:00+00:00',
        ]);
    }

    public function withForm(int $userId, OnboardingFormDetail $detail): void
    {
        $this->details[self::key($userId, $detail->assignmentPublicId)] = $detail;
    }

    /**
     * @param array<string, int> $classifications
     */
    public function withClassifications(string $formVersionPublicId, array $classifications): void
    {
        $this->classifications[$formVersionPublicId] = $classifications;
    }

    public function clear(): void
    {
        $this->details = [];
        $this->classifications = [];
        $this->submissions = [];
        $this->restrictedWrites = [];
        $this->transitions = [];
        $this->tokenCounter = 0;
    }

    public function nextToken(): string
    {
        return RowVersionToken::fromBinary(pack('J', ++$this->tokenCounter))->toBase64();
    }

    private function require(
        int $userId,
        string $assignmentPublicId,
        string $expectedVersionPublicId,
    ): OnboardingFormDetail {
        $detail = $this->findForm($userId, $assignmentPublicId);

        if ($detail === null) {
            throw OnboardingAssignmentNotFound::forThisPerson();
        }

        if ($detail->versionPublicId !== $expectedVersionPublicId) {
            throw ContentVersionSuperseded::reload();
        }

        return $detail;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function store(
        int $userId,
        OnboardingFormDetail $detail,
        ?StatusTransition $transition,
        array $changes,
    ): OnboardingFormDetail {
        if ($transition !== null) {
            $this->transitions[] = $transition;
        }

        /** @var array<string, mixed> $draftData */
        $draftData = $changes['draftData'] ?? $detail->draftData;
        /** @var string|null $draftRowVersion */
        $draftRowVersion = $changes['draftRowVersion'] ?? $detail->draftRowVersion;
        /** @var string|null $draftUpdatedAtUtc */
        $draftUpdatedAtUtc = $changes['draftUpdatedAtUtc'] ?? $detail->draftUpdatedAtUtc;
        /** @var int $revisions */
        $revisions = $changes['submittedRevisionCount'] ?? $detail->submittedRevisionCount;
        /** @var string|null $lastSubmittedAtUtc */
        $lastSubmittedAtUtc = $changes['lastSubmittedAtUtc'] ?? $detail->lastSubmittedAtUtc;

        $updated = new OnboardingFormDetail(
            assignmentPublicId: $detail->assignmentPublicId,
            title: $detail->title,
            versionPublicId: $detail->versionPublicId,
            versionNumber: $detail->versionNumber,
            status: $transition?->to ?? $detail->status,
            schema: $detail->schema,
            draftData: $draftData,
            draftRowVersion: $draftRowVersion,
            draftUpdatedAtUtc: $draftUpdatedAtUtc,
            submittedRevisionCount: $revisions,
            lastSubmittedAtUtc: $lastSubmittedAtUtc,
            // Addressed to the person only while the form is sent back; any
            // move they make takes it out of that state.
            reviewerNote: $transition === null ? $detail->reviewerNote : null,
            restrictedFieldsCleared: $transition === null && $detail->restrictedFieldsCleared,
        );

        $this->details[self::key($userId, $detail->assignmentPublicId)] = $updated;

        return $updated;
    }

    private static function key(int $userId, string $assignmentPublicId): string
    {
        return $userId . ':' . $assignmentPublicId;
    }
}
