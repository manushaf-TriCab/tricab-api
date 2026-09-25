<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Migration\Target;

/**
 * What already exists on the platform for the people in a snapshot (Phase 7.3):
 * their accounts, induction records, live assignments and acknowledgements, and
 * which source records an earlier batch already migrated.
 *
 * Read once per preview, read-only, and folded into the fingerprint — an
 * account created or an assignment made after a preview makes that preview
 * stale, so execution cannot act on a picture of the platform that is no longer
 * true.
 *
 * @phpstan-type TargetAccount array{userId: int, canSignIn: bool}
 * @phpstan-type TargetProfile array{publicId: string, departed: bool}
 */
final readonly class TargetState
{
    /**
     * @param array<string, TargetAccount> $accounts lower-case email => account
     * @param array<int, TargetProfile> $profiles userId => induction record
     * @param array<int, list<string>> $liveAssignments userId => "type:slug" not withdrawn
     * @param array<int, list<string>> $acknowledgedPolicies userId => policy slugs acknowledged at their live version
     * @param list<string> $migrated "source_table:source_id" recorded by a batch not rolled back
     */
    public function __construct(
        public array $accounts,
        public array $profiles,
        public array $liveAssignments,
        public array $acknowledgedPolicies,
        public array $migrated,
    ) {
    }

    /** @return TargetAccount|null */
    public function account(string $email): ?array
    {
        return $this->accounts[strtolower(trim($email))] ?? null;
    }

    /** @return TargetProfile|null */
    public function profile(int $userId): ?array
    {
        return $this->profiles[$userId] ?? null;
    }

    public function hasLiveAssignment(int $userId, string $itemType, string $slug): bool
    {
        return in_array($itemType . ':' . $slug, $this->liveAssignments[$userId] ?? [], true);
    }

    public function hasAcknowledged(int $userId, string $policySlug): bool
    {
        return in_array($policySlug, $this->acknowledgedPolicies[$userId] ?? [], true);
    }

    public function isMigrated(string $sourceTable, int $sourceId): bool
    {
        return in_array($sourceTable . ':' . $sourceId, $this->migrated, true);
    }

    public function fingerprintPart(): string
    {
        $lines = [];
        $accounts = $this->accounts;
        ksort($accounts);
        foreach ($accounts as $email => $account) {
            $lines[] = 'a:' . $email . ':' . $account['userId'] . ':' . ($account['canSignIn'] ? 1 : 0);
        }
        $profiles = $this->profiles;
        ksort($profiles);
        foreach ($profiles as $userId => $profile) {
            $lines[] = 'p:' . $userId . ':' . $profile['publicId'] . ':' . ($profile['departed'] ? 1 : 0);
        }
        foreach (['l' => $this->liveAssignments, 'k' => $this->acknowledgedPolicies] as $kind => $map) {
            ksort($map);
            foreach ($map as $userId => $keys) {
                sort($keys);
                $lines[] = $kind . ':' . $userId . ':' . implode(',', $keys);
            }
        }
        $migrated = $this->migrated;
        sort($migrated);
        $lines[] = 'm:' . implode(',', $migrated);

        return implode("\n", $lines);
    }
}
