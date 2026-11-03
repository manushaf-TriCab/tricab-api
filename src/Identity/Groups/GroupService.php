<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Groups;

/**
 * @phpstan-type Summary array{publicId: string, name: string, description: ?string, source: string, entraGroupId: ?string, mail: ?string, isUnlinked: bool, unlinkedAt: ?string, membersReadOnly: bool, lastSyncedAt: ?string, lastSyncOutcome: ?string, lastSyncMessage: ?string, memberCount: int, pendingMemberCount: int, createdAt: string}
 * @phpstan-type MemberRow array{userPublicId: string, displayName: string, emailAddress: string, status: string, source: string, addedAt: string}
 * @phpstan-type Detail array{publicId: string, name: string, description: ?string, source: string, entraGroupId: ?string, mail: ?string, isUnlinked: bool, unlinkedAt: ?string, membersReadOnly: bool, lastSyncedAt: ?string, lastSyncOutcome: ?string, lastSyncMessage: ?string, memberCount: int, pendingMemberCount: int, createdAt: string, members: list<MemberRow>}
 * @phpstan-type SyncStats array{status: string, members: int, added: int, removed: int, createdPending: int, skippedDisabled: int, skippedInvalid: int, warning: ?string}
 *
 * What Administrators do with groups: list, create/edit/delete platform groups, edit their members by hand,
 * link/unlink Entra groups, sync now, and the picker lookup. Members of Entra-linked groups are never edited here.
 */
final class GroupService
{
    private const NAME_MAX = 200;
    private const DESCRIPTION_MAX = 500;

    public function __construct(
        private readonly GroupStore $store,
        private readonly GroupUserDirectory $users,
        private readonly EntraGroupDirectory $entra,
        private readonly GroupSync $sync,
    ) {
    }

    /** @return array{items: list<Summary>, pagination: array<string, int>} */
    public function list(?string $query, ?string $source, bool $includeUnlinked, int $page, int $pageSize): array
    {
        if ($source !== null && !in_array($source, [GroupRecord::SOURCE_ENTRA, GroupRecord::SOURCE_PLATFORM], true)) {
            throw GroupException::invalid('source must be entra or platform.');
        }
        $found = $this->store->search($query, $source, $includeUnlinked, $page, $pageSize);

        return [
            'items' => $this->summaries($found['items']),
            'pagination' => [
                'page' => $page, 'pageSize' => $pageSize, 'totalItems' => $found['total'],
                'totalPages' => max(1, (int) ceil($found['total'] / max(1, $pageSize))),
            ],
        ];
    }

    /** @return list<Summary> */
    public function lookup(string $query): array
    {
        return $this->summaries($this->store->lookup($query, 20));
    }

    /** @return Detail */
    public function get(string $publicId): array
    {
        $group = $this->require($publicId);
        $members = $this->store->members($group->id);
        $described = $this->users->describe(array_column($members, 'userId'));

        $rows = [];
        foreach ($members as $member) {
            $user = $described[$member['userId']] ?? null;
            if ($user === null) {
                continue;
            }
            $rows[] = [
                'userPublicId' => $user['publicId'],
                'displayName' => $user['displayName'],
                'emailAddress' => $user['emailAddress'],
                'status' => $user['status'],
                'source' => $member['source'],
                'addedAt' => $member['addedAt'],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcasecmp((string) $a['displayName'], (string) $b['displayName']));

        return [...$this->summaries([$group])[0], 'members' => $rows];
    }

    /** @return Summary */
    public function create(string $name, ?string $description, ?int $actorUserId): array
    {
        [$name, $description] = $this->clean($name, $description);
        if ($this->store->platformNameExists($name)) {
            throw GroupException::nameTaken();
        }

        return $this->summaries([$this->store->createPlatform($name, $description, $actorUserId)])[0];
    }

    /** @return Detail */
    public function update(string $publicId, string $name, ?string $description): array
    {
        $group = $this->requirePlatform($publicId);
        [$name, $description] = $this->clean($name, $description);
        if ($this->store->platformNameExists($name, $group->id)) {
            throw GroupException::nameTaken();
        }
        $this->store->updatePlatform($group->id, $name, $description);

        return $this->get($publicId);
    }

    public function delete(string $publicId): void
    {
        $this->store->delete($this->requirePlatform($publicId)->id);
    }

    /** @return Detail */
    public function addMember(string $publicId, string $userPublicId, ?int $actorUserId): array
    {
        $group = $this->requirePlatform($publicId, membersOnly: true);
        $userId = $this->users->findIdByPublicId($userPublicId) ?? throw GroupException::userNotFound();
        $this->store->addMember($group->id, $userId, GroupRecord::SOURCE_PLATFORM, $actorUserId);

        return $this->get($publicId);
    }

    /** @return Detail */
    public function removeMember(string $publicId, string $userPublicId): array
    {
        $group = $this->requirePlatform($publicId, membersOnly: true);
        $userId = $this->users->findIdByPublicId($userPublicId) ?? throw GroupException::userNotFound();
        if (!$this->store->removeMember($group->id, $userId)) {
            throw GroupException::notAMember();
        }

        return $this->get($publicId);
    }

    /**
     * @return list<array{entraGroupId: string, name: string, mail: ?string, description: ?string, linked: bool}>
     *
     * @throws EntraGroupDirectoryUnavailable
     */
    public function searchEntra(string $query): array
    {
        $rows = [];
        foreach ($this->entra->searchMailGroups($query) as $group) {
            $existing = $this->store->findByEntraGroupId($group->id);
            $rows[] = [
                'entraGroupId' => $group->id,
                'name' => $group->displayName,
                'mail' => $group->mail,
                'description' => $group->description,
                'linked' => $existing !== null && !$existing->isUnlinked,
            ];
        }

        return $rows;
    }

    /**
     * Links an Entra mail group (or re-links an unlinked one) and syncs it once.
     *
     * @return array{group: Detail, sync: SyncStats}
     *
     * @throws EntraGroupDirectoryUnavailable
     */
    public function link(string $entraGroupId, ?int $actorUserId): array
    {
        $entraGroupId = trim($entraGroupId);
        if ($entraGroupId === '') {
            throw GroupException::invalid('entraGroupId is required.');
        }
        $entraGroup = $this->entra->findGroup($entraGroupId) ?? throw GroupException::entraGroupNotFound();

        $existing = $this->store->findByEntraGroupId($entraGroup->id);
        if ($existing !== null && !$existing->isUnlinked) {
            throw GroupException::alreadyLinked();
        }
        if ($existing !== null) {
            $this->store->relinkEntra($existing->id, $entraGroup);
            $group = $this->store->findByPublicId($existing->publicId) ?? throw GroupException::notFound();
        } else {
            $group = $this->store->createEntra($entraGroup, $actorUserId);
        }

        return $this->syncAndDescribe($group);
    }

    /**
     * Stops following Entra. The group and its members stay, so grants made through it are not lost.
     *
     * @return Detail
     */
    public function unlink(string $publicId): array
    {
        $group = $this->requireEntra($publicId);
        if (!$group->isUnlinked) {
            $this->store->markUnlinked($group->id);
        }

        return $this->get($publicId);
    }

    /**
     * @return array{group: Detail, sync: SyncStats}
     *
     * @throws EntraGroupDirectoryUnavailable
     */
    public function syncNow(string $publicId, bool $allowShrink = false): array
    {
        $group = $this->requireEntra($publicId);
        if ($group->isUnlinked) {
            throw GroupException::unlinked();
        }

        try {
            return $this->syncAndDescribe($group, $allowShrink);
        } catch (EntraGroupDirectoryUnavailable $unavailable) {
            $this->store->recordSync($group->id, $unavailable->outcome(), $unavailable->getMessage());

            throw $unavailable;
        }
    }

    /** @return array{group: Detail, sync: SyncStats} */
    private function syncAndDescribe(GroupRecord $group, bool $allowShrink = false): array
    {
        $sync = $this->sync->syncGroup($group, $allowShrink);

        return ['group' => $this->get($group->publicId), 'sync' => $sync];
    }

    private function require(string $publicId): GroupRecord
    {
        return $this->store->findByPublicId(strtolower(trim($publicId))) ?? throw GroupException::notFound();
    }

    private function requirePlatform(string $publicId, bool $membersOnly = false): GroupRecord
    {
        $group = $this->require($publicId);
        if ($group->isEntra()) {
            throw $membersOnly ? GroupException::membersAreReadOnly() : GroupException::notPlatformGroup();
        }

        return $group;
    }

    private function requireEntra(string $publicId): GroupRecord
    {
        $group = $this->require($publicId);
        if (!$group->isEntra()) {
            throw GroupException::notEntraGroup();
        }

        return $group;
    }

    /** @return array{0: string, 1: ?string} */
    private function clean(string $name, ?string $description): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw GroupException::invalid('name is required and at most ' . self::NAME_MAX . ' characters.');
        }
        $description = $description === null ? null : trim($description);
        if ($description !== null && mb_strlen($description) > self::DESCRIPTION_MAX) {
            throw GroupException::invalid('description is at most ' . self::DESCRIPTION_MAX . ' characters.');
        }

        return [$name, $description === '' ? null : $description];
    }

    /**
     * @param list<GroupRecord> $groups
     * @return list<Summary>
     */
    private function summaries(array $groups): array
    {
        $counts = $this->store->counts(array_map(static fn (GroupRecord $g): int => $g->id, $groups));
        $rows = [];
        foreach ($groups as $g) {
            $rows[] = [
                'publicId' => $g->publicId,
                'name' => $g->name,
                'description' => $g->description,
                'source' => $g->source,
                'entraGroupId' => $g->entraGroupId,
                'mail' => $g->entraMail,
                'isUnlinked' => $g->isUnlinked,
                'unlinkedAt' => $g->unlinkedAt,
                'membersReadOnly' => $g->membersAreReadOnly(),
                'lastSyncedAt' => $g->lastSyncedAt,
                'lastSyncOutcome' => $g->lastSyncOutcome,
                'lastSyncMessage' => $g->lastSyncMessage,
                'memberCount' => $counts[$g->id]['members'] ?? 0,
                'pendingMemberCount' => $counts[$g->id]['pending'] ?? 0,
                'createdAt' => $g->createdAt,
            ];
        }

        return $rows;
    }
}
