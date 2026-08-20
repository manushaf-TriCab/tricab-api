<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Exception;

use RuntimeException;

final class IdentityConflict extends RuntimeException
{
    public static function subjectLinkedToAnotherUser(): self
    {
        return new self('The provider identity is already linked to a different TriCab user.');
    }

    public static function providerSubjectMismatch(): self
    {
        return new self('This TriCab user is already linked to a different provider identity.');
    }
}
