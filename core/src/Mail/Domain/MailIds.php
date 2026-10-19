<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Domain;

/** The ids in `[mail]`: 32 lower-case hex characters (`CHAR(32)`), made here. */
final class MailIds
{
    public static function new(): string
    {
        return bin2hex(random_bytes(16));
    }
}
