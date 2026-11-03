<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Groups;

use InvalidArgumentException;

/** In-memory users for development and tests. */
final class InMemoryGroupUserDirectory implements GroupUserDirectory
{
    /** @var array<int, array{publicId: string, displayName: string, emailAddress: string, status: string, objectId: ?string}> */
    private array $users = [];

    private int $nextId = 1;

    public function add(string $email, string $displayName, string $status = 'active', ?string $objectId = null): int
    {
        $id = $this->nextId++;
        $this->users[$id] = [
            'publicId' => sprintf('00000000-0000-4000-8000-%012d', $id),
            'displayName' => $displayName,
            'emailAddress' => strtolower($email),
            'status' => $status,
            'objectId' => $objectId,
        ];

        return $id;
    }

    public function count(): int
    {
        return count($this->users);
    }

    public function findIdByPublicId(string $publicId): ?int
    {
        foreach ($this->users as $id => $user) {
            if ($user['publicId'] === strtolower($publicId)) {
                return $id;
            }
        }

        return null;
    }

    public function findIdByEntraObjectId(string $objectId): ?int
    {
        foreach ($this->users as $id => $user) {
            if ($user['objectId'] !== null && strcasecmp($user['objectId'], $objectId) === 0) {
                return $id;
            }
        }

        return null;
    }

    public function findIdByEmail(string $email): ?int
    {
        foreach ($this->users as $id => $user) {
            if ($user['emailAddress'] === strtolower($email)) {
                return $id;
            }
        }

        return null;
    }

    public function createPending(string $email, string $displayName): int
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Not a valid email address.');
        }

        return $this->add($email, $displayName, 'pending');
    }

    public function describe(array $userIds): array
    {
        $found = [];
        foreach ($userIds as $id) {
            if (isset($this->users[$id])) {
                $user = $this->users[$id];
                $found[$id] = [
                    'publicId' => $user['publicId'],
                    'displayName' => $user['displayName'],
                    'emailAddress' => $user['emailAddress'],
                    'status' => $user['status'],
                ];
            }
        }

        return $found;
    }
}
