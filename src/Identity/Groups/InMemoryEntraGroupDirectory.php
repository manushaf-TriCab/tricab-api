<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Groups;

/**
 * A fake Entra for development and tests. `denyPermission()` makes every read fail the way Graph does
 * before admin consent is granted.
 */
final class InMemoryEntraGroupDirectory implements EntraGroupDirectory
{
    /** @var array<string, EntraGroup> */
    private array $groups = [];

    /** @var array<string, list<EntraMember>> */
    private array $members = [];

    private ?EntraGroupDirectoryUnavailable $failure = null;

    public int $reads = 0;

    /** @param list<EntraMember> $members */
    public function withGroup(EntraGroup $group, array $members = []): self
    {
        $this->groups[$group->id] = $group;
        $this->members[$group->id] = $members;

        return $this;
    }

    /** @param list<EntraMember> $members */
    public function setMembers(string $groupId, array $members): void
    {
        $this->members[$groupId] = $members;
    }

    public function removeGroup(string $groupId): void
    {
        unset($this->groups[$groupId], $this->members[$groupId]);
    }

    public function denyPermission(): void
    {
        $this->failure = GraphPermissionNotGranted::make();
    }

    public function notConfigured(): void
    {
        $this->failure = GraphNotConfigured::make();
    }

    public function searchMailGroups(string $query, int $limit = 25): array
    {
        $this->guard();

        return array_slice(array_values(array_filter(
            $this->groups,
            static fn (EntraGroup $g): bool => $query === '' || stripos($g->displayName, $query) !== false,
        )), 0, $limit);
    }

    public function findGroup(string $entraGroupId): ?EntraGroup
    {
        $this->guard();

        return $this->groups[$entraGroupId] ?? null;
    }

    public function transitiveMembers(string $entraGroupId): array
    {
        $this->guard();
        if (!isset($this->groups[$entraGroupId])) {
            throw EntraGroupNotFound::forId($entraGroupId);
        }

        return $this->members[$entraGroupId] ?? [];
    }

    private function guard(): void
    {
        ++$this->reads;
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
