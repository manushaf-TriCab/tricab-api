<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

use DateTimeImmutable;
use DateTimeZone;

/** The contract's timestamps: ISO 8601 UTC with a `Z`, never a local offset. */
final class MailTime
{
    public static function iso(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
