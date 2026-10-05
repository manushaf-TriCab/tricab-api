<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\Service;

use TriCab\Core\Rfq\Storage\DraftChangeLog;

/**
 * Whether a saved quote may be turned into an ALI-FLEX alternative (parity plan
 * E7.4): the source's `aliflex.eligibility`, ported with its wording.
 *
 * Only a finished quote is converted, or the alternative inherits whatever was
 * still wrong with it:
 *
 *  - a quote synced to 4js with no change since (the store's `synced`, which any
 *    edit after a sync moves back to `in_progress`); or
 *  - a quote whose every line was read from a printed TriCab quote (`imported_line`),
 *    which only migrated drafts carry.
 *
 * An alternative is never converted again: that would move the sizes up twice.
 * The editor shows the button by this rule and the service applies it again, so a
 * hidden button is not the only guard.
 */
final class AliflexEligibility
{
    public const NO_LINES = 'The quote has no lines.';
    public const ALREADY = 'This quote is already an ALI-FLEX alternative.';
    public const NOT_FINAL = 'Sync this quote to 4js first. An ALI-FLEX alternative is made from a finished quote: one synced to 4js, or an imported TriCab quote.';

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>       $emailMeta
     *
     * @return array{eligible: bool, reason: ?string}
     */
    public static function check(string $storeStatus, array $rows, array $emailMeta): array
    {
        if ($rows === []) {
            return ['eligible' => false, 'reason' => self::NO_LINES];
        }
        foreach ($rows as $row) {
            if (DraftChangeLog::truthy($row['aliflex'] ?? null)) {
                return ['eligible' => false, 'reason' => self::ALREADY];
            }
        }
        if (DraftChangeLog::truthy($emailMeta['aliflex'] ?? null)) {
            return ['eligible' => false, 'reason' => self::ALREADY];
        }
        if ($storeStatus === 'synced') {
            return ['eligible' => true, 'reason' => null];
        }
        $imported = true;
        foreach ($rows as $row) {
            $imported = $imported && DraftChangeLog::truthy($row['imported_line'] ?? null);
        }

        return $imported ? ['eligible' => true, 'reason' => null] : ['eligible' => false, 'reason' => self::NOT_FINAL];
    }
}
