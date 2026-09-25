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
 * ## Two user types
 *
 *  - **Admin** — holds {@see ROLE_ADMIN}. Creates, reads, updates and deletes
 *    trainees, allocates their induction, reviews what they hand in, authors
 *    content, reads reports. Does **not** have an induction of their own: the
 *    people doing this job at TriCab were inducted long ago (owner, 21 Sep
 *    2026), so the role is "runs onboarding for other people" and nothing else.
 *  - **Trainee** — anyone holding `EMPLOYEE` or `GUEST`, which carry the
 *    self-service three. Reaches their own onboarding and nothing else.
 *
 * There is no reviewer user type. Reviewing is something an admin does.
 *
 * The eight permissions below are finer-grained than those two types, and stay
 * that way on purpose: the API gates each route on exactly one of them, which
 * is what makes an endpoint's authority readable at the route table, and lets a
 * narrower role be introduced later without touching code. Both facts about the
 * current deployment — one admin role holding the five administrative
 * permissions, and trainees holding three — live in the seed scripts, not here.
 *
 * `ONBOARDING.REVIEW` alone does not mean "not the person who did the work",
 * and {@see \TriCab\Core\Onboarding\Domain\Service\SelfReviewPolicy} is what
 * enforces that it cannot be. Today's admins have no induction to review, so
 * nothing exercises it — which is the argument for keeping it, not for removing
 * it. The first HR hire who is themselves a new starter holds both hats on one
 * record, and that is a hiring decision, not a code change.
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
     * Phase 6: read the completion feed (`GET /api/v1/integrations/onboarding/completions`).
     * For a service identity — Skill Matrix — and nobody else: not an HR
     * permission, held by no role yet, and not in {@see HR_PERMISSIONS}.
     */
    public const PERMISSION_COMPLETIONS_READ = 'ONBOARDING.COMPLETIONS.READ';

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
     * The five administrative permissions. Each gates exactly one group of
     * endpoints, and none of them implies another — `ONBOARDING.ADMIN` in
     * particular is module administration, not a superset. The admin role holds
     * all five; nothing else does.
     */
    public const HR_PERMISSIONS = [
        self::PERMISSION_REVIEW,
        self::PERMISSION_PEOPLE_MANAGE,
        self::PERMISSION_CONTENT_MANAGE,
        self::PERMISSION_REPORTS_READ,
        self::PERMISSION_ADMIN,
    ];

    /** Permissions for other systems, not people. None is granted to a person's role. */
    public const INTEGRATION_PERMISSIONS = [
        self::PERMISSION_COMPLETIONS_READ,
    ];

    /**
     * The Onboarding admin role, created by `seed-onboarding-roles.sql`.
     *
     * `UPPERCASE_UNDERSCORE` by platform convention — dots are for permission
     * codes.
     *
     * Holds {@see HR_PERMISSIONS} — the five administrative ones — and not the
     * self-service three. Owner decision, 21 Sep 2026: TriCab's Onboarding
     * admins were inducted years ago and will not be doing one, so a role
     * meaning "runs onboarding for other people" should say only that.
     *
     * Note what this does NOT do, because it would be easy to read it as a
     * restriction: every one of these people also holds `EMPLOYEE`, which
     * carries the self-service three. An admin who did somehow need an induction
     * would get one, from the role that gives everybody else theirs. Narrowing
     * this role removes no capability from anybody — it removes a claim the role
     * was making about itself that is no longer true.
     */
    public const ROLE_ADMIN = 'ONBOARDING_ADMIN';

    /**
     * What `ONBOARDING_ADMIN` actually grants, named so the seed script and the
     * application agree on one list rather than two.
     */
    public const ADMIN_ROLE_PERMISSIONS = self::HR_PERMISSIONS;

    /**
     * The trainee side has no role of its own by design: every employee and
     * every pre-start guest already carries the self-service three. A role that
     * must be assigned per person is one somebody will forget to assign,
     * locking a new starter out of their own onboarding on their first morning.
     * Reaching `/onboarding` grants nothing by itself — allocation is explicit.
     */
    public const TRAINEE_ROLES = ['EMPLOYEE', 'GUEST'];

    /**
     * The role an account created by Onboarding holds (4.1, owner decision
     * 23 Sep 2026): sign-in and one's own onboarding, and nothing else. Not
     * `EMPLOYEE`, which also reads customers, orders and quotes — a candidate
     * who has not started does not need them, and may never start.
     */
    public const ROLE_GUEST = 'GUEST';
}
