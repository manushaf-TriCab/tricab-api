<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Groups;

/**
 * Read-only view of Entra's mail groups (Microsoft Graph, application permission
 * `GroupMember.Read.All`). Nothing here writes to Entra.
 */
interface EntraGroupDirectory
{
    /**
     * Mail-enabled groups whose name matches, for the "Link Entra group" search.
     *
     * @return list<EntraGroup>
     *
     * @throws EntraGroupDirectoryUnavailable
     */
    public function searchMailGroups(string $query, int $limit = 25): array;

    /**
     * @return EntraGroup|null null when Entra has no such group
     *
     * @throws EntraGroupDirectoryUnavailable
     */
    public function findGroup(string $entraGroupId): ?EntraGroup;

    /**
     * Every user in the group, nested groups included (transitive).
     *
     * @return list<EntraMember>
     *
     * @throws EntraGroupNotFound
     * @throws EntraGroupDirectoryUnavailable
     */
    public function transitiveMembers(string $entraGroupId): array;
}
