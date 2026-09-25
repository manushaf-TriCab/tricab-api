<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO;

use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddressPurpose;

/**
 * A user reached through one of their verified addresses, and what that
 * address is for. The purpose travels with the match because the resolution
 * path has to know it: a personal address and a corporate one admit different
 * providers.
 */
final class VerifiedEmailOwner
{
    /**
     * @param EmailAddressPurpose|null $purpose null means nobody has classified
     *        this address yet — a user who predates `identity.UserEmailAddresses`
     *        or its backfill. Unclassified addresses resolve exactly as they did
     *        before the table existed. Guessing a purpose from the domain was
     *        tried and rejected: it would refuse a first-time Microsoft sign-in
     *        for anyone whose address is not on a TriCab domain, which is every
     *        contractor and every `onmicrosoft.com` account. A control that locks
     *        real people out on a guess is worse than the gap it closes.
     */
    public function __construct(
        public readonly User $user,
        public readonly ?EmailAddressPurpose $purpose,
    ) {
    }
}
