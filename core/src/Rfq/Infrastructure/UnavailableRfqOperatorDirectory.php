<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Infrastructure;

use TriCab\Core\Rfq\Contract\RfqOperatorDirectory;

/**
 * No operator table to ask (memory mode, or the legacy database is not
 * configured): nobody's code is confirmed, so a save keeps the author it has.
 */
final class UnavailableRfqOperatorDirectory implements RfqOperatorDirectory
{
    public function codeFor(string $email): ?string
    {
        return null;
    }
}
