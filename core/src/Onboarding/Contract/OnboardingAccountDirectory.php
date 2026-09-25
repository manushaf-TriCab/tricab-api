<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

/**
 * What Onboarding needs to know about, and ask of, platform identity when HR
 * adds a person (4.1).
 *
 * Onboarding stores a `UserId` and nothing else about identity. This contract
 * is the one place it reaches across, and it is deliberately small: find an
 * account, create the least-privileged one there is, and say whether it can
 * sign in. It never changes an account that already exists — its status, its
 * roles, its name — because that is a platform access decision, and those are
 * made in User Management where they are recorded against whoever makes them.
 */
interface OnboardingAccountDirectory
{
    /** Matches the primary address and every active address a person holds. */
    public function findUserIdByEmail(EmailAddress $emailAddress): ?int;

    /**
     * Creates an active account holding only `GUEST` — sign-in and one's own
     * onboarding, nothing else — through the platform's audited user creation,
     * and records the address as **personal**, so it can resolve email codes,
     * passkeys, Google and Apple but never a Microsoft sign-in
     * (`ProviderBindingPolicy`, invariant 16).
     *
     * Owner decision, 23 Sep 2026.
     *
     * @return int the new user's id
     */
    public function createGuestAccount(ActorContext $actor, EmailAddress $emailAddress, string $displayName): int;

    /**
     * Whether the account is active and holds `account.login` — the same
     * question the email-code sign-in asks before it sends a code.
     */
    public function canSignIn(int $userId): bool;

    /**
     * Whether this account exists only for onboarding: onboarding created it
     * (the `person.created` audit row says so) and it still holds nothing but
     * `GUEST`. An employee's account, a customer's, or a guest somebody has
     * since given another role is none of onboarding's business to disable.
     */
    public function isOnboardingOnlyAccount(int $userId): bool;

    /**
     * Disables the account through User Management's own audited status
     * change. It cannot be deleted: `audit.UserManagementEvents` and
     * `audit.OnboardingAdminEvents` name it, and both are append-only.
     */
    public function disableAccount(ActorContext $actor, int $userId): void;
}
