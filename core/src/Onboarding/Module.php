<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding;

/**
 * Marker for the Onboarding bounded context.
 *
 * Onboarding owns inductions: allocation, training, policy acknowledgement,
 * forms, review and the evidence of all of it. It owns no part of identity —
 * records link to a person by `identity.Users.UserId` and nothing else, never
 * by email address, name or provider subject.
 *
 * Trust boundaries, data classification and the reasoning behind the
 * permission split are documented in `docs/onboarding-architecture.md`.
 */
final class Module
{
    public const NAME = 'Onboarding';

    /** Reach the module at all. */
    public const PERMISSION_ACCESS = 'ONBOARDING.ACCESS';

    /** Read one's own record, assignments and documents. */
    public const PERMISSION_SELF_READ = 'ONBOARDING.SELF.READ';

    /** Submit one's own attempts, acknowledgements and forms. */
    public const PERMISSION_SELF_SUBMIT = 'ONBOARDING.SELF.SUBMIT';

    /** Work the reviewer queue and set the statuses a reviewer may set. */
    public const PERMISSION_REVIEW = 'ONBOARDING.REVIEW';

    /** Invite people and allocate modules, policies and forms. */
    public const PERMISSION_PEOPLE_MANAGE = 'ONBOARDING.PEOPLE.MANAGE';

    /** Author modules, policy versions and form definitions. */
    public const PERMISSION_CONTENT_MANAGE = 'ONBOARDING.CONTENT.MANAGE';

    /** Operational and compliance reporting. */
    public const PERMISSION_REPORTS_READ = 'ONBOARDING.REPORTS.READ';

    /** Module administration. */
    public const PERMISSION_ADMIN = 'ONBOARDING.ADMIN';

    /**
     * The three self-service permissions, held by candidates and by employees
     * who have no TriCab mailbox. None of them grants any HR capability.
     */
    public const SELF_SERVICE_PERMISSIONS = [
        self::PERMISSION_ACCESS,
        self::PERMISSION_SELF_READ,
        self::PERMISSION_SELF_SUBMIT,
    ];

    /**
     * The five HR permissions. Each gates exactly one group of endpoints, and
     * none of them implies another — `ONBOARDING.ADMIN` in particular is
     * module administration, not a superset.
     */
    public const HR_PERMISSIONS = [
        self::PERMISSION_REVIEW,
        self::PERMISSION_PEOPLE_MANAGE,
        self::PERMISSION_CONTENT_MANAGE,
        self::PERMISSION_REPORTS_READ,
        self::PERMISSION_ADMIN,
    ];
}
