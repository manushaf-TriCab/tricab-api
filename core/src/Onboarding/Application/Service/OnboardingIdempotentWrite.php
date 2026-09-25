<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use DateTimeZone;
use Throwable;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Onboarding\Application\DTO\OnboardingWriteIdempotencyRecord;
use TriCab\Core\Onboarding\Contract\OnboardingWriteIdempotencyStore;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteWriteIdempotencyState;
use TriCab\Core\Shared\Time\Clock;

/**
 * Wraps a once-only write in the idempotency ledger.
 *
 * Three services need the same five steps — acquire, notice a completed replay,
 * write, record the result, record a failure — and writing them out three times
 * is how the third one ends up missing `markFailed`. The shape follows
 * `SaveQuoteService`, minus the cross-database crash recovery, which exists
 * there because quotes commit to MySQL and Azure SQL in one operation. Nothing
 * here spans two databases, so a failed write is simply a failed write.
 *
 * ## The key is required
 *
 * Not optional, not best-effort. A submission with no key is refused with 422
 * before anything is written. The alternative — write anyway, and let the unique
 * indexes catch what they can — protects attempts and form submissions not at
 * all, because `UX_onboarding_Attempts_IdempotencyKey` and
 * `UX_onboarding_FormSubmissions_IdempotencyKey` are both filtered
 * `WHERE [IdempotencyKey] IS NOT NULL`. A client that sends no key is a client
 * that double-submits on a slow connection, and the second record is evidence of
 * something that happened once.
 *
 * ## What a replay returns
 *
 * `$replay` is handed the id the first call produced and re-reads it. It is not
 * the same as running `$write` again with a guard: re-reading proves the first
 * write is really there, and costs one query on a path that only a retry takes.
 */
final readonly class OnboardingIdempotentWrite
{
    public function __construct(
        private OnboardingWriteIdempotencyStore $store,
        private Clock $clock,
        private UuidV4Generator $operationIds,
    ) {
    }

    /**
     * @template T
     *
     * @param array<string, mixed>       $requestFingerprint what makes this request this request
     * @param callable(): array{0: T, 1: string} $write      returns [result, the public id it produced]
     * @param callable(string): T        $replay             re-reads a result produced earlier
     *
     * @return T
     *
     * @throws InvalidOnboardingValue when the key is missing or unusable
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict
     */
    public function run(
        int $userId,
        string $operation,
        ?string $idempotencyKey,
        array $requestFingerprint,
        callable $write,
        callable $replay,
    ): mixed {
        $key = trim((string) $idempotencyKey);

        if ($key === '') {
            throw InvalidOnboardingValue::for('Idempotency-Key', '(missing)');
        }

        // NVARCHAR(128) in the ledger and in every per-table column. Truncating
        // silently would make two different keys collide, which is the one
        // failure this whole mechanism exists to prevent.
        if (mb_strlen($key) > 128) {
            throw InvalidOnboardingValue::for('Idempotency-Key', 'longer than 128 characters');
        }

        $actorKey = self::actorKey($userId);
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));

        $acquired = $this->store->tryAcquire(new OnboardingWriteIdempotencyRecord(
            actorKey: $actorKey,
            operation: $operation,
            idempotencyKey: $key,
            requestHash: self::fingerprint($requestFingerprint),
            state: QuoteWriteIdempotencyState::IN_PROGRESS,
            platformOperationId: $this->operationIds->generate()->toString(),
            resultPublicId: null,
            createdAtUtc: $now,
            updatedAtUtc: $now,
        ));

        if ($acquired->isCompleted() && $acquired->resultPublicId !== null) {
            return $replay($acquired->resultPublicId);
        }

        try {
            [$result, $resultPublicId] = $write();
        } catch (Throwable $e) {
            // The class name, never the message. A message can carry a field
            // value out of the payload that failed, and this row is read by
            // people debugging other people's inductions.
            $this->store->markFailed($actorKey, $operation, $key, $e::class);

            throw $e;
        }

        $this->store->markCompleted($actorKey, $operation, $key, $resultPublicId);

        return $result;
    }

    /**
     * Scopes a key to one person.
     *
     * `UX_onboarding_OnboardingWriteIdempotency_Actor_Op_Key` is on
     * (actor, operation, key), so two people who happen to generate the same
     * key do not collide — which they will, because a client is free to use a
     * counter.
     */
    public static function actorKey(int $userId): string
    {
        return 'user:' . $userId;
    }

    /**
     * @param array<string, mixed> $requestFingerprint
     */
    public static function fingerprint(array $requestFingerprint): string
    {
        ksort($requestFingerprint);

        return hash('sha256', json_encode($requestFingerprint, JSON_THROW_ON_ERROR));
    }
}
