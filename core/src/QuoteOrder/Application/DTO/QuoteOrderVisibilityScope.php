<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\DTO;

/**
 * Server-side visibility for Quotes & Orders. Never trust client-only filters.
 *
 * Filters may only narrow this scope; they must never expand it.
 */
final readonly class QuoteOrderVisibilityScope
{
    /**
     * @param list<string> $allowedCustomerCodes portal (and constrained) customer codes
     * @param list<string> $allowedCountryCodes reserved for future staff region parity (empty = unused)
     */
    public function __construct(
        public bool $canSearch,
        public bool $isCustomerPortalScoped,
        public bool $isAdministrator,
        public bool $staffSearchAll,
        public array $allowedCustomerCodes = [],
        public array $allowedCountryCodes = [],
        public ?string $regionCode = null,
    ) {
    }

    /**
     * The only scope that sees every customer. Anything else is limited to
     * allowedCustomerCodes, so a scope built without a positive staff decision
     * fails closed.
     */
    public function isUnrestricted(): bool
    {
        return $this->staffSearchAll && !$this->isCustomerPortalScoped;
    }

    public function forcesEmptyResults(): bool
    {
        return !$this->isUnrestricted() && $this->allowedCustomerCodes === [];
    }
}
