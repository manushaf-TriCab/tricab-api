<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class ProductPublishReadinessReport
{
    /**
     * @param list<ProductPublishReadinessIssue> $issues
     * @param list<ProductPublishReadinessCheck> $checks
     */
    public function __construct(
        public bool $isReady,
        public array $issues,
        public array $checks,
    ) {
    }

    /**
     * @return list<ProductPublishReadinessIssue>
     */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn (ProductPublishReadinessIssue $issue): bool => $issue->isError(),
        ));
    }

    /**
     * @return list<ProductPublishReadinessIssue>
     */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn (ProductPublishReadinessIssue $issue): bool => !$issue->isError(),
        ));
    }
}
