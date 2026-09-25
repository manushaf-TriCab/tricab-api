<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Migration\Plan;

/**
 * The preview (Phase 7.3): per person, what happens to them and to each of
 * their records, every finding, and the counts an operator approves.
 *
 * ## The fingerprint
 *
 * SHA-256 over the snapshot hash, the platform content the plan points at, the
 * platform state it read, and the plan itself. Approval quotes it, and
 * execution recomputes the preview and refuses unless it still matches — so
 * what runs is exactly what was approved, against the platform as it was when
 * it was approved (the staff migration's precedent).
 */
final readonly class MigrationPlan
{
    public const PERSON_LINK = 'link';
    public const PERSON_CREATE_ACCOUNT = 'create_account';
    public const PERSON_SKIPPED_ADMIN = 'skip_admin';
    public const PERSON_BLOCKED = 'blocked';

    public const PROFILE_CREATE = 'create';
    public const PROFILE_EXISTING = 'existing';

    public const ITEM_CREATE = 'create';
    public const ITEM_SKIP = 'skip';

    /**
     * @param list<array<string, mixed>> $people
     * @param list<array{severity: string, code: string, subject: string, message: string}> $findings
     * @param array<string, int> $sourceCounts
     */
    public function __construct(
        public string $snapshotHash,
        public bool $synthetic,
        public array $people,
        public array $findings,
        public array $sourceCounts,
        private string $catalogueFingerprint,
        private string $stateFingerprint,
    ) {
    }

    public function isApprovable(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding['severity'] === Findings::BLOCKING) {
                return false;
            }
        }

        return true;
    }

    public function fingerprint(): string
    {
        return hash('sha256', implode("\n--\n", [
            $this->snapshotHash,
            $this->catalogueFingerprint,
            $this->stateFingerprint,
            (string) json_encode($this->people, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]));
    }

    /**
     * What the plan will create, and what it skips by reason — the numbers
     * verification later reconciles against.
     *
     * @return array{people: array<string, int>, create: array<string, int>, skip: array<string, int>}
     */
    public function counts(): array
    {
        $people = array_fill_keys([self::PERSON_LINK, self::PERSON_CREATE_ACCOUNT, self::PERSON_SKIPPED_ADMIN, self::PERSON_BLOCKED], 0);
        $create = array_fill_keys(['profiles', 'accounts', 'training', 'progress', 'attempts', 'answers', 'policies', 'acknowledgements', 'forms', 'submissions', 'drafts', 'contributions', 'documents', 'photos', 'statusEvents'], 0);
        $skip = [];

        foreach ($this->people as $person) {
            $action = is_string($person['action'] ?? null) ? $person['action'] : self::PERSON_BLOCKED;
            $people[$action] = ($people[$action] ?? 0) + 1;
            $migrates = in_array($action, [self::PERSON_LINK, self::PERSON_CREATE_ACCOUNT], true);
            if ($migrates && ($person['profileAction'] ?? null) === self::PROFILE_CREATE) {
                ++$create['profiles'];
            }
            if ($action === self::PERSON_CREATE_ACCOUNT) {
                ++$create['accounts'];
            }
            foreach (is_array($person['items'] ?? null) ? $person['items'] : [] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (($item['action'] ?? null) !== self::ITEM_CREATE || !$migrates) {
                    $reason = is_string($item['reason'] ?? null) ? $item['reason'] : 'PERSON_NOT_MIGRATED';
                    $skip[$reason] = ($skip[$reason] ?? 0) + 1;
                    continue;
                }
                $create['statusEvents'] += self::statusEvents($item);
                match ($item['kind'] ?? null) {
                    MigrationPlanner::TRAINING => self::countTraining($item, $create),
                    MigrationPlanner::POLICY => self::countPolicy($item, $create),
                    default => self::countForm($item, $create),
                };
            }
        }
        ksort($skip);

        return ['people' => $people, 'create' => $create, 'skip' => $skip];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'snapshotHash' => $this->snapshotHash,
            'synthetic' => $this->synthetic,
            'fingerprint' => $this->fingerprint(),
            'approvable' => $this->isApprovable(),
            'sourceCounts' => $this->sourceCounts,
            'counts' => $this->counts(),
            'findings' => $this->findings,
            'people' => $this->people,
        ];
    }

    /**
     * How many status events execution writes for an item: one for training
     * and policies; for a form, one per hand-in, one per review that decided
     * something, and one more if it then moved on to where it stands.
     *
     * @param array<array-key, mixed> $item
     */
    public static function statusEvents(array $item): int
    {
        if (($item['kind'] ?? null) !== MigrationPlanner::FORM) {
            return 1;
        }
        $events = 0;
        $current = null;
        foreach (is_array($item['submissions'] ?? null) ? $item['submissions'] : [] as $submission) {
            if (!is_array($submission)) {
                continue;
            }
            ++$events;
            $current = 'submitted';
            $outcome = $submission['reviewOutcome'] ?? null;
            if (($submission['reviewedAtUtc'] ?? null) !== null && in_array($outcome, ['fix_and_resubmit', 'completed'], true)) {
                ++$events;
                $current = $outcome;
            }
        }

        return $events + ($current !== ($item['status'] ?? null) ? 1 : 0);
    }

    /**
     * @param array<array-key, mixed> $item
     * @param array<string, int> $create
     */
    private static function countTraining(array $item, array &$create): void
    {
        ++$create['training'];
        if (is_array($item['progress'] ?? null)) {
            ++$create['progress'];
        }
        foreach (is_array($item['attempts'] ?? null) ? $item['attempts'] : [] as $attempt) {
            ++$create['attempts'];
            $create['answers'] += is_array($attempt) && is_array($attempt['answers'] ?? null) ? count($attempt['answers']) : 0;
            $create['documents'] += is_array($attempt) && is_array($attempt['documents'] ?? null) ? count($attempt['documents']) : 0;
        }
    }

    /**
     * @param array<array-key, mixed> $item
     * @param array<string, int> $create
     */
    private static function countPolicy(array $item, array &$create): void
    {
        ++$create['policies'];
        if (is_array($item['acknowledgement'] ?? null)) {
            ++$create['acknowledgements'];
        }
    }

    /**
     * @param array<array-key, mixed> $item
     * @param array<string, int> $create
     */
    private static function countForm(array $item, array &$create): void
    {
        ++$create['forms'];
        foreach (is_array($item['submissions'] ?? null) ? $item['submissions'] : [] as $submission) {
            ++$create['submissions'];
            $create['documents'] += is_array($submission) && is_array($submission['documents'] ?? null) ? count($submission['documents']) : 0;
        }
        if (is_array($item['draft'] ?? null)) {
            ++$create['drafts'];
        }
        $create['contributions'] += is_array($item['contributions'] ?? null) ? count($item['contributions']) : 0;
        $create['photos'] += is_array($item['photos'] ?? null) ? count($item['photos']) : 0;
    }
}
