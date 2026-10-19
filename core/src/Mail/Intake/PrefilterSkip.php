<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Intake;

/** Why the pre-filter will not send a message to the model. `reason` is what the review screen shows. */
final readonly class PrefilterSkip
{
    public const SELF_SENT = 'self_sent';
    public const OWN_REPLY = 'own_reply';
    public const OWN_NOTIFICATION = 'own_notification';
    public const BOUNCE = 'bounce';
    public const AUTO_REPLY = 'auto_reply';
    public const CALENDAR = 'calendar_invite';
    public const BULK = 'bulk';

    public function __construct(public string $code, public string $reason)
    {
    }
}
