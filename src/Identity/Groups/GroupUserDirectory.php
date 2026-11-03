<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Groups;

/** The slice of platform users the groups need. */
interface GroupUserDirectory
{
    public function findIdByPublicId(string $publicId): ?int;

    /** By Entra object id (an Entra external identity on the user). */
    public function findIdByEntraObjectId(string $objectId): ?int;

    /** By any address the user is known by. `$email` is already lower case. */
    public function findIdByEmail(string $email): ?int;

    /**
     * Creates a `pending` user. Pending users cannot sign in; only an Administrator activates them.
     *
     * @throws \InvalidArgumentException when the address is not a valid email
     */
    public function createPending(string $email, string $displayName): int;

    /**
     * @param list<int> $userIds
     * @return array<int, array{publicId: string, displayName: string, emailAddress: string, status: string}>
     */
    public function describe(array $userIds): array;
}
