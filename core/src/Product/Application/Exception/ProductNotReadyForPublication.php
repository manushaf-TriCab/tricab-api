<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;
use TriCab\Core\Product\Application\DTO\ProductPublishReadinessReport;

final class ProductNotReadyForPublication extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly ProductPublishReadinessReport $report,
    ) {
        parent::__construct($message);
    }

    public static function forReport(string $publicId, ProductPublishReadinessReport $report): self
    {
        $codes = array_map(
            static fn ($issue): string => $issue->code,
            $report->errors(),
        );

        return new self(
            sprintf(
                'Product "%s" is not ready for publication: %s.',
                $publicId,
                implode(', ', $codes),
            ),
            $report,
        );
    }

    public function report(): ProductPublishReadinessReport
    {
        return $this->report;
    }
}
