<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Intake;

use RuntimeException;

/**
 * The model was still throttled (429/529) after the backfill's retries. Deliberately not a {@see ClassifierFailed}:
 * the live reader records that as `uncertain`, but the backfill defers the message (records nothing) so a later run
 * classifies it for real.
 */
final class ClassifierThrottled extends RuntimeException
{
}
