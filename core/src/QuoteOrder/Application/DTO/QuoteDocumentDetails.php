<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\DTO;

/**
 * Document-facing enrichment for quotation presentation (additive to the core quote read model).
 *
 * @phpstan-type MetalPrices array{
 *   copperUsd: string|null,
 *   copperAud: string|null,
 *   aluminiumUsd: string|null,
 *   aluminiumAud: string|null,
 *   asAt: string|null
 * }
 * @phpstan-type ConductorRow array{
 *   sizeMm2: string,
 *   tricab: string|null,
 *   class5: string|null,
 *   class6: string|null
 * }
 */
final readonly class QuoteDocumentDetails
{
    /**
     * @param list<string> $customerAddressLines
     * @param list<string> $sellerAddressLines
     * @param list<string> $commercialNotes
     * @param MetalPrices|null $metalPrices
     * @param list<ConductorRow> $conductorComparison
     */
    public function __construct(
        public array $customerAddressLines = [],
        public ?string $customerPhone = null,
        public ?string $customerCountry = null,
        public ?string $customerTerritory = null,
        public ?string $contactEmail = null,
        public ?string $contactPhone = null,
        public ?string $contactPosition = null,
        public ?string $raisedByEmail = null,
        public ?string $raisedByPhone = null,
        public ?string $paymentTerms = null,
        public ?string $paymentTermsCode = null,
        public bool $pricesExclusiveOfTax = true,
        public ?string $sellerTaxId = null,
        public ?string $sellerCompanyName = null,
        /** Seller/FROM office street lines (legacy quote PDF From block). */
        public array $sellerAddressLines = [],
        public ?string $sellerPhone = null,
        public ?string $sellerEmail = null,
        public array $commercialNotes = [],
        public ?array $metalPrices = null,
        public array $conductorComparison = [],
    ) {
    }
}
