<?php

declare(strict_types=1);

namespace TriCab\Core\Npr;

/**
 * One NPR request as CableFit sees it: a read-only copy of the current
 * revision, never the NPR tables themselves. Returned by
 * {@see NprRequestReader::findForTsd()}.
 */
final readonly class NprRequestSnapshot
{
    /**
     * @param array<string, mixed>       $section5Answers The Sales "Section 5 requirements" answers, keyed by field id.
     * @param list<array<string, mixed>> $variants
     */
    public function __construct(
        public string $publicId,
        public string $requestNumber,
        public int $currentRevision,
        public string $title,
        public string $customerName,
        public array $section5Answers,
        public array $variants,
    ) {
    }
}
