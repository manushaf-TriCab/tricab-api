<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Contract;

use TriCab\Core\Npr\Domain\NprRequesterContact;

/**
 * The requester's office and phone from the staff directory, so a new draft
 * starts with Section 1's branch and contact number filled in. Only a
 * convenience: it never throws, and null leaves the fields for the person.
 */
interface NprRequesterDirectory
{
    public function contactFor(string $email): ?NprRequesterContact;
}
