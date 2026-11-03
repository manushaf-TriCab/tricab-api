<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Groups;

/** In-memory groups for development and tests. */
final class InMemoryGroupStore implements GroupStore
{
    /** @var array<int, GroupRecord> */
    private array $groups = [];

    /** @var array<int, array<int, array{source: string, addedAt: string}>> group id => user id => row */
    private array $members = [];

    private int $nextId = 1;

    public function __construct(private readonly InMemoryGroupUserDirectory $users)
    {
    }

    public function transaction(callable $work): mixed
    {
        return $work();
    }

    public function search(?string $query, ?string $source, bool $includeUnlinked, int $page, int $pageSize): array
    {
        $rows = array_values(array_filter(
            $this->groups,
            fn (GroupRecord $g): bool => ($includeUnlinked || !$g->isUnlinked)
                && ($source === null || $g->source === $source)
                && ($query === null || $query === '' || stripos($g->name, $query) !== false),
        ));
        usort($rows, static fn (GroupRecord $a, GroupRecord $b): int => strcasecmp($a->name, $b->name) ?: $a->id <=> $b->id);

        return ['items' => array_slice($rows, ($page - 1) * $pageSize, $pageSize), 'total' => count($rows)];
    }

    public function lookup(string $query, int $limit): array
    {
        return array_slice($this->search($query, null, false, 1, $limit)['items'], 0, $limit);
    }

    public function findByPublicId(string $publicId): ?GroupRecord
    {
        foreach ($this->groups as $group) {
            if ($group->publicId === strtolower($publicId)) {
                return $group;
            }
        }

        return null;
    }

    public function findByEntraGroupId(string $entraGroupId): ?GroupRecord
    {
        foreach ($this->groups as $group) {
            if ($group->entraGroupId !== null && strcasecmp($group->entraGroupId, $entraGroupId) === 0) {
                return $group;
            }
        }

        return null;
    }

    public function platformNameExists(string $name, ?int $exceptGroupId = null): bool
    {
        foreach ($this->groups as $group) {
            if ($group->source === GroupRecord::SOURCE_PLATFORM && $group->id !== $exceptGroupId && strcasecmp($group->name, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    public function createPlatform(string $name, ?string $description, ?int $actorUserId): GroupRecord
    {
        return $this->put($name, $description, GroupRecord::SOURCE_PLATFORM, null, null);
    }

    public function updatePlatform(int $groupId, string $name, ?string $description): void
    {
        $g = $this->groups[$groupId];
        $this->groups[$groupId] = $g->describedAs($name, $description, $g->entraMail);
    }

    public function delete(int $groupId): void
    {
        unset($this->groups[$groupId], $this->members[$groupId]);
    }

    public function createEntra(EntraGroup $group, ?int $actorUserId): GroupRecord
    {
        return $this->put($group->displayName, $group->description, GroupRecord::SOURCE_ENTRA, $group->id, $group->mail);
    }

    public function relinkEntra(int $groupId, EntraGroup $group): void
    {
        $this->groups[$groupId] = $this->groups[$groupId]
            ->describedAs($group->displayName, $group->description, $group->mail)
            ->withLinkState(false, null);
    }

    public function refreshEntra(int $groupId, EntraGroup $group): void
    {
        $this->groups[$groupId] = $this->groups[$groupId]->describedAs($group->displayName, $group->description, $group->mail);
    }

    public function markUnlinked(int $groupId): void
    {
        $g = $this->groups[$groupId];
        $this->groups[$groupId] = $g->withLinkState(true, $g->unlinkedAt ?? gmdate('c'));
    }

    public function linkedEntraGroups(): array
    {
        return array_values(array_filter(
            $this->groups,
            static fn (GroupRecord $g): bool => $g->isEntra() && !$g->isUnlinked,
        ));
    }

    public function members(int $groupId): array
    {
        $rows = [];
        foreach ($this->members[$groupId] ?? [] as $userId => $row) {
            $rows[] = ['userId' => $userId, 'source' => $row['source'], 'addedAt' => $row['addedAt']];
        }

        return $rows;
    }

    public function addMember(int $groupId, int $userId, string $source, ?int $actorUserId): bool
    {
        if (isset($this->members[$groupId][$userId])) {
            return false;
        }
        $this->members[$groupId][$userId] = ['source' => $source, 'addedAt' => gmdate('c')];

        return true;
    }

    public function removeMember(int $groupId, int $userId): bool
    {
        $had = isset($this->members[$groupId][$userId]);
        unset($this->members[$groupId][$userId]);

        return $had;
    }

    public function removeEntraMembers(int $groupId, array $userIds): int
    {
        $removed = 0;
        foreach ($userIds as $userId) {
            if (($this->members[$groupId][$userId]['source'] ?? null) === GroupRecord::SOURCE_ENTRA) {
                unset($this->members[$groupId][$userId]);
                ++$removed;
            }
        }

        return $removed;
    }

    public function lock(int $groupId): void
    {
    }

    public function recordSync(int $groupId, string $outcome, ?string $message): void
    {
        $g = $this->groups[$groupId];
        $this->groups[$groupId] = $g->withSync($outcome === 'ok' ? gmdate('c') : $g->lastSyncedAt, $outcome, $message);
    }

    public function recordOutcomeForLinked(string $outcome, string $message): void
    {
        foreach ($this->linkedEntraGroups() as $group) {
            $this->groups[$group->id] = $group->withSync($group->lastSyncedAt, $outcome, $message);
        }
    }

    public function counts(array $groupIds): array
    {
        $counts = [];
        foreach ($groupIds as $id) {
            $userIds = array_keys($this->members[$id] ?? []);
            $described = $this->users->describe($userIds);
            $counts[$id] = [
                'members' => count($userIds),
                'pending' => count(array_filter($described, static fn (array $u): bool => $u['status'] === 'pending')),
            ];
        }

        return $counts;
    }

    private function put(string $name, ?string $description, string $source, ?string $entraId, ?string $mail): GroupRecord
    {
        $id = $this->nextId++;
        $record = new GroupRecord(
            $id,
            sprintf('00000000-0000-4000-9000-%012d', $id),
            $name,
            $description,
            $source,
            $entraId,
            $mail,
            false,
            null,
            null,
            null,
            null,
            gmdate('c'),
        );
        $this->groups[$id] = $record;

        return $record;
    }
}
