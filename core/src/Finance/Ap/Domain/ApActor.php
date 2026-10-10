<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

/**
 * The signed-in person, as the Tri-AP use cases need them: the platform user id (decision D7),
 * the name and address a row keeps (the Bin shows who deleted an invoice), the platform
 * permission codes they hold, and the mailboxes they may see.
 *
 * Reusable plumbing for every Tri-AP service:
 *
 * - Build one per request with `ApActorResolver` (the HTTP layer); it reads the permissions from
 *   the token and the scope from `finance.access_scopes`.
 * - Permissions: the route's own permission is checked by the `RequirePermission` middleware, one
 *   per route (question Q1). A use case that needs a second one asks `can()`.
 * - Mailbox scope: `$actor->mailboxScope` (see {@see ApMailboxScope}); an out-of-scope record is
 *   an {@see ApNotFound}.
 * - `$isAdmin` is the person holding `FINANCE.AP.SETTINGS.MANAGE`: the source's `role = admin`.
 *   An administrator is never mailbox-restricted (question Q11); the resolver builds the scope
 *   accordingly.
 */
final readonly class ApActor
{
    public ApMailboxScope $mailboxScope;

    /**
     * @param list<string> $permissions platform codes (`FINANCE.AP.INVOICES.READ`)
     */
    public function __construct(
        public int $userId,
        public string $name,
        public string $email,
        public array $permissions,
        ?ApMailboxScope $mailboxScope = null,
        public bool $isAdmin = false,
    ) {
        $this->mailboxScope = $mailboxScope ?? ApMailboxScope::unrestricted();
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /** What a row keeps as "who": the address, as the source's `deleted_by` did, else the name. */
    public function who(): string
    {
        return $this->email !== '' ? strtolower($this->email) : $this->name;
    }
}
