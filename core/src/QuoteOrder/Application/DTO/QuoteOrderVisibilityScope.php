<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\DTO;

/**
 * Server-side visibility for Quotes & Orders. Never trust client-only filters.
 *
 * Phase 1B: Azure customerPublicId → legacy customer code resolution is unknown
 * when allowedCustomerCodes is not supplied by the caller.
 */
final readonly class QuoteOrderVisibilityScope
{
    /**
     * @param list<string> $allowedCustomerCodes
     */
    public function __construct(
        public bool $canSearch,
        public bool $isCustomerPortalScoped,
        public bool $isAdministrator,
        public array $allowedCustomerCodes = [],
        public ?string $regionCode = null,
    ) {
    }

    public function forcesEmptyResults(): bool
    {
        return $this->isCustomerPortalScoped && $this->allowedCustomerCodes === [];
    }
}
