<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Service;

use TriCab\Core\Identity\Domain\ValueObject\AuthenticationAssurance;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;

/**
 * The minimum authentication assurance each permission demands.
 *
 * Holding a permission is necessary but no longer sufficient: the session must
 * also have proved itself strongly enough for what is being asked. This is what
 * lets an employee without a TriCab mailbox hold real roles and sign in with a
 * six-digit code without that code becoming a route into administration.
 *
 * **The default is LOW, deliberately.** Anything absent from this map behaves
 * exactly as it did before, so introducing the floor cannot lock anyone out of
 * something that worked yesterday. Raising the floor across the rest of the
 * catalogue — the `*.MANAGE` family, customer portal administration, pricing
 * overrides — is a tightening pass that needs an owner to confirm who actually
 * signs in with what, because Google and Apple are live providers and some of
 * those permissions may be held by people using them today. Locking out a real
 * user to close a theoretical gap is not a good trade to make unattended.
 *
 * What is listed here is the set where the answer is not in doubt: managing
 * identity itself, and administering Onboarding, which carries medical,
 * banking and immigration data.
 */
final class PermissionAssurancePolicy
{
    /** @var array<string, string> */
    private const REQUIRED_ASSURANCE = [
        // Identity administration — whoever holds these can grant themselves
        // anything else, so an emailed code must never be enough.
        'USER.CREATE' => AuthenticationAssurance::SUBSTANTIAL,
        'USER.UPDATE' => AuthenticationAssurance::SUBSTANTIAL,
        'USER.STATUS.UPDATE' => AuthenticationAssurance::SUBSTANTIAL,
        'USER.ROLE.ASSIGN' => AuthenticationAssurance::SUBSTANTIAL,
        'USER.ROLE.REMOVE' => AuthenticationAssurance::SUBSTANTIAL,
        'ROLE.CREATE' => AuthenticationAssurance::SUBSTANTIAL,
        'ROLE.UPDATE' => AuthenticationAssurance::SUBSTANTIAL,
        'ROLE.STATUS.UPDATE' => AuthenticationAssurance::SUBSTANTIAL,
        'ROLE.PERMISSION.ASSIGN' => AuthenticationAssurance::SUBSTANTIAL,
        'ROLE.PERMISSION.REMOVE' => AuthenticationAssurance::SUBSTANTIAL,
        'PERMISSION.CREATE' => AuthenticationAssurance::SUBSTANTIAL,
        'PERMISSION.UPDATE' => AuthenticationAssurance::SUBSTANTIAL,
        'MIGRATION.ADMIN' => AuthenticationAssurance::SUBSTANTIAL,

        // Onboarding administration. Self-service is deliberately absent:
        // ONBOARDING.SELF.* stays reachable at LOW, because the people who
        // need it are precisely the ones who cannot authenticate any other way.
        'ONBOARDING.REVIEW' => AuthenticationAssurance::SUBSTANTIAL,
        'ONBOARDING.PEOPLE.MANAGE' => AuthenticationAssurance::SUBSTANTIAL,
        'ONBOARDING.CONTENT.MANAGE' => AuthenticationAssurance::SUBSTANTIAL,
        'ONBOARDING.REPORTS.READ' => AuthenticationAssurance::SUBSTANTIAL,
        'ONBOARDING.ADMIN' => AuthenticationAssurance::SUBSTANTIAL,
        // Phase 6: the completion feed is for a service identity; an emailed
        // code must never be enough to read everybody's completions.
        'ONBOARDING.COMPLETIONS.READ' => AuthenticationAssurance::SUBSTANTIAL,

        // RFQ. Everyday quoting stays at LOW — the people who do it sign in with
        // Microsoft and gain nothing from a floor — but the four that act on a
        // live ERP, delete history, read raw customer text or administer the
        // module do not open to an emailed code.
        'RFQ.QUOTE.RECONCILE' => AuthenticationAssurance::SUBSTANTIAL,
        'RFQ.DRAFT.PURGE' => AuthenticationAssurance::SUBSTANTIAL,
        'RFQ.REVIEWLOG.READ' => AuthenticationAssurance::SUBSTANTIAL,
        'RFQ.ADMIN' => AuthenticationAssurance::SUBSTANTIAL,
        'RFQ.MAIL.INGEST' => AuthenticationAssurance::SUBSTANTIAL,

        // Staff price-as reveals any customer's negotiated prices and puts
        // staff-only cost fields in responses; overriding the margin changes
        // what a customer is offered. Neither opens to an emailed code. They
        // gate no route, so they are enforced by scoping the user that the
        // commerce and pricing services see (ResolveRequestAuthenticatedUser).
        'CUSTOMER.PRICE_AS' => AuthenticationAssurance::SUBSTANTIAL,
        'PRODUCT.PRICING.OVERRIDE' => AuthenticationAssurance::SUBSTANTIAL,
    ];

    public static function requiredFor(PermissionCode $permission): AuthenticationAssurance
    {
        $code = strtoupper($permission->toString());
        $required = self::REQUIRED_ASSURANCE[$code] ?? AuthenticationAssurance::LOW;

        return match ($required) {
            AuthenticationAssurance::HIGH => AuthenticationAssurance::high(),
            AuthenticationAssurance::SUBSTANTIAL => AuthenticationAssurance::substantial(),
            default => AuthenticationAssurance::low(),
        };
    }

    public static function isSatisfiedBy(PermissionCode $permission, AuthenticationAssurance $actual): bool
    {
        return $actual->satisfies(self::requiredFor($permission));
    }
}
