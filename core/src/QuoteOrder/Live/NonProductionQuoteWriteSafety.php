<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Live;

use TriCab\Core\QuoteOrder\Application\Exception\QuoteWriteMisconfiguredException;

/**
 * First non-prod write must target *_dev tables only.
 * Empty table suffix (production tables) is rejected until explicitly approved later.
 */
final class NonProductionQuoteWriteSafety
{
    public function assertDevTableSuffixRequired(LegacyQuoteWriteConfig $config): void
    {
        if (!$config->enabled) {
            return;
        }
        $config->assertExecutable();
        if ($config->resolvedTableSuffix() !== '_dev') {
            throw QuoteWriteMisconfiguredException::firstWriteRequiresDevSuffix();
        }
    }
}
