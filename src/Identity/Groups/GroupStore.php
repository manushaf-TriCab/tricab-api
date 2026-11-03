<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Groups;

/** Persistence of `identity.Groups` and `identity.GroupMembers`. Users are always referred to by internal id. */
interface GroupStore
{
    /**
     * Runs the work in one transaction (joins one already open).
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transaction(callable $work): mixed;

    /**
     * @return array{items: list<GroupRecord>, total: int}
     */
    public function search(?string $query, ?string $source, bool $includeUnlinked, int $page, int $pageSize): array;

    /**
     * Picker lookup: platform groups and linked (not unlinked) Entra groups by name.
     *
     * @return list<GroupRecord>
     */
    public function lookup(string $query, int $limit): array;

    public function findByPublicId(string $publicId): ?GroupRecord;

    public function findByEntraGroupId(string $entraGroupId): ?GroupRecord;

    public function platformNameExists(string $name, ?int $exceptGroupId = null): bool;

    public function createPlatform(string $name, ?string $description, ?int $actorUserId): GroupRecord;

    public function updatePlatform(int $groupId, string $name, ?string $description): void;

    /** Removes the group and its member rows. */
    public function delete(int $groupId): void;

    public function createEntra(EntraGroup $group, ?int $actorUserId): GroupRecord;

    /** Re-links a group that was unlinked: clears the flag and takes Entra's current name. */
    public function relinkEntra(int $groupId, EntraGroup $group): void;

    /** Takes Entra's current name, description and mail. */
    public function refreshEntra(int $groupId, EntraGroup $group): void;

    /** Marks unlinked (kept, with its members, so grants made through it are not silently lost). */
    public function markUnlinked(int $groupId): void;

    /** @return list<GroupRecord> Entra groups that are linked, i.e. not unlinked. */
    public function linkedEntraGroups(): array;

    /** @return list<array{userId: int, source: string, addedAt: string}> */
    public function members(int $groupId): array;

    /** @return bool true when a row was added, false when the user was already a member */
    public function addMember(int $groupId, int $userId, string $source, ?int $actorUserId): bool;

    /** @return bool true when a row was removed */
    public function removeMember(int $groupId, int $userId): bool;

    /** @param list<int> $userIds Entra-sourced member rows to remove (never touches `platform` rows). */
    public function removeEntraMembers(int $groupId, array $userIds): int;

    /** Serialises concurrent syncs of one group (held to the end of the transaction). */
    public function lock(int $groupId): void;

    public function recordSync(int $groupId, string $outcome, ?string $message): void;

    /** Notes why no sync happened (permission, configuration) on every linked Entra group. */
    public function recordOutcomeForLinked(string $outcome, string $message): void;

    /**
     * @param list<int> $groupIds
     * @return array<int, array{members: int, pending: int}>
     */
    public function counts(array $groupIds): array;
}
