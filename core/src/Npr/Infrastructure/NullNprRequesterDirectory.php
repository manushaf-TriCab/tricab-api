<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Infrastructure;

use TriCab\Core\Npr\Contract\NprRequesterDirectory;
use TriCab\Core\Npr\Domain\NprRequesterContact;

/** No directory: drafts start with branch and contact number empty. */
final class NullNprRequesterDirectory implements NprRequesterDirectory
{
    public function contactFor(string $email): ?NprRequesterContact
    {
        return null;
    }
}
