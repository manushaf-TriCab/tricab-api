<?php

declare(strict_types=1);

namespace TriCab\Core\Mail;

/**
 * Mail review: the inbox where people check what the RFQ classifier decided about
 * mail on the shared mailboxes (melbourne@ first), mark its label right or wrong,
 * and turn uncertain mail into RFQ drafts. Inbox integration plan I2.
 *
 * ## Permissions
 *
 * One permission per route. Everyone who holds `MAIL.ACCESS` sees every thread, as
 * everyone sees every RFQ (owner decision, 18 Oct 2026): there is no scoping by
 * branch or by person. `MAIL.REVIEW` adds the actions that change what the platform
 * believes: marking a label, creating a draft, ignoring.
 *
 * Marking a thread read or unread needs only `MAIL.ACCESS`. It is the one action that
 * shares the review route (`POST /threads/{id}/review`), so that route carries
 * `MAIL.ACCESS` and the service refuses the other actions to anyone without
 * `MAIL.REVIEW`.
 *
 * **Stored here:** metadata, Graph's `bodyPreview` and the classifier's result.
 * **Not stored:** bodies and attachment bytes, which come from Graph when someone
 * opens a message (decision 0.1).
 */
final class Module
{
    public const NAME = 'Mail';

    /** Seed `ModuleCode` for every permission below. */
    public const MODULE_CODE = 'mail';

    /** Open the inbox; read every thread, message and attachment; mark read or unread. */
    public const PERMISSION_ACCESS = 'MAIL.ACCESS';

    /** Mark a label right or wrong, create an RFQ draft, ignore a thread. */
    public const PERMISSION_REVIEW = 'MAIL.REVIEW';

    /** @var list<string> */
    public const PERMISSIONS = [
        self::PERMISSION_ACCESS,
        self::PERMISSION_REVIEW,
    ];

    /**
     * What `seed-mail-roles.sql` grants (decision 0.4). RFQ_ADMIN and RFQ_DEVELOPER hold
     * RFQ_SALES' rights and more, so they get the same two.
     *
     * @var array<string, list<string>>
     */
    public const ROLE_PERMISSIONS = [
        'RFQ_SALES' => self::PERMISSIONS,
        'RFQ_ADMIN' => self::PERMISSIONS,
        'RFQ_DEVELOPER' => self::PERMISSIONS,
    ];
}
