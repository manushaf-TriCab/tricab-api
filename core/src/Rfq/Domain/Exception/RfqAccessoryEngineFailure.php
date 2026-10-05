<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\Exception;

use RuntimeException;

/** The accessory engine could not answer for one cable (the source's per-row `skipped` entry). */
final class RfqAccessoryEngineFailure extends RuntimeException
{
}
