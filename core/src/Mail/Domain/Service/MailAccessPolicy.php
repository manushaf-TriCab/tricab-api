<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Domain\Service;

use TriCab\Core\Mail\Domain\MailActor;
use TriCab\Core\Mail\Module;

/**
 * What a person may do in the inbox. Pure.
 *
 * **Seeing is not scoped**: anyone holding `MAIL.ACCESS` sees every thread in every
 * mailbox, as everyone sees every RFQ (owner decision, 18 Oct 2026). Review rights
 * are `MAIL.REVIEW`, and every reviewer may mark labels (the same day's decision),
 * not only the people building the labelled set.
 */
final class MailAccessPolicy
{
    public static function canAccess(MailActor $actor): bool
    {
        return $actor->can(Module::PERMISSION_ACCESS);
    }

    /** Reviewing needs access as well: a reviewer who cannot open the inbox could not see what they review. */
    public static function canReview(MailActor $actor): bool
    {
        return self::canAccess($actor) && $actor->can(Module::PERMISSION_REVIEW);
    }

    /** The labelled-set export (plan I3.3): every reviewer may build it (owner decision), so it is `MAIL.REVIEW` and no new permission. */
    public static function canExport(MailActor $actor): bool
    {
        return self::canReview($actor);
    }
}
