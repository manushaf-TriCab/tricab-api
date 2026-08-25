<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Product\Application\DTO\LegacyStyleOccurrence;
use TriCab\Core\Product\Application\DTO\StyleInventorySourceStat;
use TriCab\Core\Product\Application\DTO\StyleMembershipEvidence;

/**
 * Read-only style-code inventory extraction. Implementations must not write
 * WordPress, legacy tc MySQL, or the legacy filesystem.
 */
interface LegacyStyleOccurrenceSource
{
    /**
     * @return list<LegacyStyleOccurrence>
     */
    public function listOccurrences(): array;

    /**
     * @return list<StyleInventorySourceStat>
     */
    public function sourceStats(): array;

    public function wordpressReads(): int;

    public function tcReads(): int;

    public function filesystemReads(): int;

    /**
     * Read-only membership/title/term/spec/media snapshot. Never writes.
     *
     * @param list<string> $styleCodes
     * @return array<string, StyleMembershipEvidence>
     */
    public function inspectMembership(array $styleCodes): array;
}
