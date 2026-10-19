<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Mail;

/**
 * Word to the retry sweep that something failed. The sweep runs every minute;
 * without this it would ask the database each time and a serverless database
 * asked every minute never pauses. Best effort: the outbox row is the record.
 */
interface NprMailWake
{
    public function signal(): void;
}
