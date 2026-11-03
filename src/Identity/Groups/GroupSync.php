<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Groups;

use InvalidArgumentException;
use Throwable;

/**
 * Keeps Entra-linked groups in step with Entra (Tri-Task company plan, Phase 1).
 *
 * - Only groups an Administrator linked are read. Platform groups are never touched.
 * - Members are matched to platform users by Entra object id, then email. A member with no platform
 *   account is created as a `pending` user and added (decided 16 Oct). Sync never activates anyone.
 * - A disabled Entra account is not created as a user. One that already has a platform account is
 *   still a member.
 * - Only `entra`-sourced member rows are added or removed.
 * - Short-read guard (like HR's SHORT_REFRESH_RATIO): if a group has `entra` members and Entra returns
 *   fewer than {@see self::SHORT_READ_RATIO} of them (zero included), no member is removed from that group
 *   in that pass. Additions still happen. The group is recorded as `guarded` with a warning, and an
 *   Administrator can accept a real shrink with "Sync now" and the override.
 * - A group that has gone from Entra is marked unlinked, not deleted; its members stay.
 * - If Graph cannot be read (permission not granted, not configured) nothing changes except a note on
 *   the linked groups, and the run reports a typed outcome rather than failing.
 */
final class GroupSync
{
    public const OUTCOME_OK = 'ok';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_UNLINKED = 'unlinked';
    public const OUTCOME_GUARDED = 'guarded';

    /** A read returning under this share of the current Entra members is held back (no removals). */
    public const SHORT_READ_RATIO = 0.5;

    public function __construct(
        private readonly GroupStore $store,
        private readonly EntraGroupDirectory $entra,
        private readonly GroupUserDirectory $users,
    ) {
    }

    /**
     * One pass over every linked Entra group.
     *
     * @return array{outcome: string, message: ?string, groups: int, synced: int, unlinked: int, failed: int, guarded: int, added: int, removed: int, createdPending: int}
     */
    public function syncAll(): array
    {
        $result = [
            'outcome' => self::OUTCOME_OK, 'message' => null, 'groups' => 0, 'synced' => 0, 'unlinked' => 0,
            'failed' => 0, 'guarded' => 0, 'added' => 0, 'removed' => 0, 'createdPending' => 0,
        ];

        foreach ($this->store->linkedEntraGroups() as $group) {
            ++$result['groups'];
            try {
                $stats = $this->syncGroup($group);
            } catch (EntraGroupDirectoryUnavailable $unavailable) {
                // Needs a person (consent, configuration). Stop at once; nothing was changed.
                $this->store->recordOutcomeForLinked($unavailable->outcome(), $unavailable->getMessage());

                return [...$result, 'outcome' => $unavailable->outcome(), 'message' => $unavailable->getMessage()];
            } catch (Throwable $failure) {
                $message = mb_substr(get_class($failure) . ': ' . $failure->getMessage(), 0, 300);
                $this->store->recordSync($group->id, self::OUTCOME_FAILED, $message);
                ++$result['failed'];
                $result['outcome'] = self::OUTCOME_FAILED;
                $result['message'] = $message;
                continue;
            }

            if ($stats['status'] === self::OUTCOME_UNLINKED) {
                ++$result['unlinked'];
                continue;
            }
            ++$result['synced'];
            if ($stats['status'] === self::OUTCOME_GUARDED) {
                ++$result['guarded'];
                if ($result['outcome'] === self::OUTCOME_OK) {
                    $result['outcome'] = self::OUTCOME_GUARDED;
                    $result['message'] = $stats['warning'];
                }
            }
            $result['added'] += $stats['added'];
            $result['removed'] += $stats['removed'];
            $result['createdPending'] += $stats['createdPending'];
        }

        return $result;
    }

    /**
     * @return array{status: string, members: int, added: int, removed: int, createdPending: int, skippedDisabled: int, skippedInvalid: int, warning: ?string}
     *
     * @param bool $allowShrink accept a real shrink: skip the short-read guard
     *
     * @throws GroupException a platform group, or one already unlinked
     * @throws EntraGroupDirectoryUnavailable
     */
    public function syncGroup(GroupRecord $group, bool $allowShrink = false): array
    {
        if (!$group->isEntra() || $group->entraGroupId === null) {
            throw GroupException::notEntraGroup();
        }
        if ($group->isUnlinked) {
            throw GroupException::unlinked();
        }

        // Read Entra first, outside any transaction, so a slow Graph never holds database locks.
        try {
            $entraGroup = $this->entra->findGroup($group->entraGroupId);
            $members = $entraGroup === null ? [] : $this->entra->transitiveMembers($group->entraGroupId);
        } catch (EntraGroupNotFound) {
            $entraGroup = null;
            $members = [];
        }

        if ($entraGroup === null) {
            $this->store->transaction(function () use ($group): void {
                $this->store->lock($group->id);
                $this->store->markUnlinked($group->id);
                $this->store->recordSync($group->id, self::OUTCOME_UNLINKED, 'The group no longer exists in Entra; it was unlinked and its members were kept.');
            });

            return $this->stats(self::OUTCOME_UNLINKED);
        }

        /** @var array{status: string, members: int, added: int, removed: int, createdPending: int, skippedDisabled: int, skippedInvalid: int, warning: ?string} $stats */
        $stats = $this->store->transaction(function () use ($group, $entraGroup, $members, $allowShrink): array {
            $this->store->lock($group->id);
            $this->store->refreshEntra($group->id, $entraGroup);

            $stats = $this->stats(self::OUTCOME_OK);
            $wanted = [];
            $seen = [];
            foreach ($members as $member) {
                $key = strtolower($member->objectId);
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $userId = $this->resolve($member);
                if ($userId === null) {
                    if (!$member->accountEnabled) {
                        ++$stats['skippedDisabled'];
                        continue;
                    }
                    $userId = $this->createPending($member);
                    if ($userId === null) {
                        ++$stats['skippedInvalid'];
                        continue;
                    }
                    ++$stats['createdPending'];
                }
                $wanted[$userId] = true;
            }

            $current = [];
            foreach ($this->store->members($group->id) as $row) {
                $current[$row['userId']] = $row['source'];
            }

            foreach (array_keys($wanted) as $userId) {
                if (!isset($current[$userId]) && $this->store->addMember($group->id, $userId, GroupRecord::SOURCE_ENTRA, null)) {
                    ++$stats['added'];
                }
            }

            $gone = [];
            $entraCount = 0;
            foreach ($current as $userId => $source) {
                if ($source === GroupRecord::SOURCE_ENTRA) {
                    ++$entraCount;
                    if (!isset($wanted[$userId])) {
                        $gone[] = $userId;
                    }
                }
            }
            $stats['members'] = count($wanted);

            $kept = $entraCount - count($gone);
            if (!$allowShrink && $gone !== [] && $kept < $entraCount * self::SHORT_READ_RATIO) {
                $stats['status'] = self::OUTCOME_GUARDED;
                $stats['warning'] = sprintf(
                    'Entra returned %d of this group\'s %d members, so no one was removed. If the shrink is real, use Sync now and accept it.',
                    $kept,
                    $entraCount,
                );
                $this->store->recordSync($group->id, self::OUTCOME_GUARDED, $stats['warning']);

                return $stats;
            }

            $stats['removed'] = $gone === [] ? 0 : $this->store->removeEntraMembers($group->id, $gone);
            $this->store->recordSync($group->id, self::OUTCOME_OK, null);

            return $stats;
        });

        return $stats;
    }

    private function resolve(EntraMember $member): ?int
    {
        $id = $this->users->findIdByEntraObjectId($member->objectId);
        if ($id !== null) {
            return $id;
        }
        foreach ($member->emails() as $email) {
            $id = $this->users->findIdByEmail($email);
            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    private function createPending(EntraMember $member): ?int
    {
        $emails = $member->emails();
        if ($emails === []) {
            return null;
        }
        $name = trim($member->displayName) !== '' ? trim($member->displayName) : $emails[0];
        try {
            return $this->users->createPending($emails[0], mb_substr($name, 0, 200));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @return array{status: string, members: int, added: int, removed: int, createdPending: int, skippedDisabled: int, skippedInvalid: int, warning: ?string} */
    private function stats(string $status): array
    {
        return ['status' => $status, 'members' => 0, 'added' => 0, 'removed' => 0, 'createdPending' => 0, 'skippedDisabled' => 0, 'skippedInvalid' => 0, 'warning' => null];
    }
}
