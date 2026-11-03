<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Groups;

use RuntimeException;

/** A group request the platform refuses. `code()` is the API error code, `status` the HTTP status. */
final class GroupException extends RuntimeException
{
    private function __construct(private readonly string $errorCode, string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public static function notFound(): self
    {
        return new self('GROUP_NOT_FOUND', 'Group not found.', 404);
    }

    public static function entraGroupNotFound(): self
    {
        return new self('ENTRA_GROUP_NOT_FOUND', 'Entra has no such group.', 404);
    }

    public static function userNotFound(): self
    {
        return new self('GROUP_USER_NOT_FOUND', 'No such platform user.', 404);
    }

    public static function notAMember(): self
    {
        return new self('GROUP_MEMBER_NOT_FOUND', 'That user is not a member of this group.', 404);
    }

    public static function nameTaken(): self
    {
        return new self('GROUP_NAME_TAKEN', 'A platform group with that name already exists.', 409);
    }

    public static function alreadyLinked(): self
    {
        return new self('GROUP_ALREADY_LINKED', 'That Entra group is already linked.', 409);
    }

    public static function membersAreReadOnly(): self
    {
        return new self('GROUP_MEMBERS_READ_ONLY', 'Members of an Entra-linked group follow Entra and cannot be edited here.', 409);
    }

    public static function notPlatformGroup(): self
    {
        return new self('GROUP_NOT_PLATFORM', 'Only platform groups can be edited or deleted. Unlink an Entra group instead.', 409);
    }

    public static function notEntraGroup(): self
    {
        return new self('GROUP_NOT_ENTRA', 'This is a platform group; it is not synced from Entra.', 409);
    }

    public static function unlinked(): self
    {
        return new self('GROUP_UNLINKED', 'This Entra group is unlinked; link it again to sync.', 409);
    }

    public static function invalid(string $message): self
    {
        return new self('VALIDATION_FAILED', $message, 422);
    }
}
