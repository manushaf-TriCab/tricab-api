<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\DTO;

/**
 * A saved report plus notes the screen should show. A warning never means the
 * save failed.
 */
final readonly class CustomerContactReportSaveResult
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public CustomerContactReportDetails $report,
        public array $warnings = [],
        public bool $creditReset = false,
    ) {
    }
}
