<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\ValueObject;

/**
 * One address a user has proven control of, and what it is for.
 *
 * A platform user may hold several. This is what makes a candidate's personal
 * address and the TriCab mailbox they are later issued the same person, rather
 * than forcing a choice between them.
 */
final class VerifiedEmailAddress
{
    public function __construct(
        private readonly EmailAddress $emailAddress,
        private readonly EmailAddressPurpose $purpose,
    ) {
    }

    public function emailAddress(): EmailAddress
    {
        return $this->emailAddress;
    }

    public function purpose(): EmailAddressPurpose
    {
        return $this->purpose;
    }
}
