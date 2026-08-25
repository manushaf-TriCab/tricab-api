<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Doubles;

use TriCab\Core\Product\Application\DTO\LegacyStyleOccurrence;
use TriCab\Core\Product\Application\DTO\StyleInventorySourceStat;
use TriCab\Core\Product\Application\DTO\StyleMembershipEvidence;
use TriCab\Core\Product\Contract\LegacyStyleOccurrenceSource;

final class InMemoryLegacyStyleOccurrenceSource implements LegacyStyleOccurrenceSource
{
    /**
     * @param list<LegacyStyleOccurrence> $occurrences
     * @param list<StyleInventorySourceStat> $stats
     */
    public function __construct(
        private array $occurrences = [],
        private array $stats = [],
        private int $wordpressReadCount = 0,
        private int $tcReadCount = 0,
        private int $filesystemReadCount = 0,
        public int $listCalls = 0,
        /** @var array<string, StyleMembershipEvidence> */
        private array $membership = [],
        public int $inspectCalls = 0,
    ) {
    }

    public function seedMembership(StyleMembershipEvidence $evidence): void
    {
        $this->membership[strtoupper($evidence->styleCode)] = $evidence;
    }

    public function seed(LegacyStyleOccurrence $occurrence): void
    {
        $this->occurrences[] = $occurrence;
    }

    public function seedStat(StyleInventorySourceStat $stat): void
    {
        $this->stats[] = $stat;
    }

    public function listOccurrences(): array
    {
        ++$this->listCalls;

        return $this->occurrences;
    }

    public function sourceStats(): array
    {
        return $this->stats;
    }

    public function wordpressReads(): int
    {
        return $this->wordpressReadCount;
    }

    public function tcReads(): int
    {
        return $this->tcReadCount;
    }

    public function filesystemReads(): int
    {
        return $this->filesystemReadCount;
    }

    public function inspectMembership(array $styleCodes): array
    {
        ++$this->inspectCalls;
        $out = [];
        foreach ($styleCodes as $code) {
            $key = strtoupper(trim((string) $code));
            if ($key === '') {
                continue;
            }
            $out[$key] = $this->membership[$key] ?? new StyleMembershipEvidence(
                styleCode: $key,
                wordpressProductCount: 0,
                tcVariantCount: 0,
                sampleTitles: [],
                sampleProducts: [],
                terms: [],
                postTypes: [],
                attachmentFiles: [],
                specificationValues: [],
                descriptionSnippets: [],
                sourcesAvailable: [],
                wordpressAvailable: true,
                tcAvailable: true,
            );
        }

        return $out;
    }
}
