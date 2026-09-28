<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

/**
 * The quote-level fields a revision carries. Contract `rfq.header.v1`.
 *
 * The six the source requires before a sync (`REQUIRED_HEADER_FIELDS`:
 * customer, contact, author, sales office, pay terms, region), plus currency.
 * Each is validated separately (invariant 5), and changing customer, region or
 * currency makes the revision's pricing snapshot stale — the source re-resolves
 * margins on exactly those changes (`test_margin_persistence`).
 *
 * These are legacy 4js identities, held as the codes 4js uses. They are not
 * platform ids and are never mixed with them.
 */
final class QuoteHeader
{
    /** Source `quote_readiness.SUPPORTED_CURRENCIES`. */
    public const SUPPORTED_CURRENCIES = ['AUD', 'NZD', 'USD', 'SGD'];

    public function __construct(
        public readonly ?string $customerCode,
        public readonly ?string $contactCode,
        public readonly ?string $authorCode,
        public readonly ?string $salesOfficeCode,
        public readonly ?string $payTermsCode,
        public readonly ?string $regionCode,
        public readonly ?string $currency,
        public readonly ?string $customerReference,
        public readonly ?string $projectReference,
    ) {
    }

    /** Fields that invalidate a pricing snapshot when they change. */
    public const PRICING_INPUTS = ['customerCode', 'regionCode', 'currency'];

    /**
     * @return array<string, ?string>
     */
    public function toArray(): array
    {
        return [
            'customerCode' => $this->customerCode,
            'contactCode' => $this->contactCode,
            'authorCode' => $this->authorCode,
            'salesOfficeCode' => $this->salesOfficeCode,
            'payTermsCode' => $this->payTermsCode,
            'regionCode' => $this->regionCode,
            'currency' => $this->currency,
            'customerReference' => $this->customerReference,
            'projectReference' => $this->projectReference,
        ];
    }

    public function pricingInputsDifferFrom(self $other): bool
    {
        $mine = $this->toArray();
        $theirs = $other->toArray();
        foreach (self::PRICING_INPUTS as $field) {
            if ($mine[$field] !== $theirs[$field]) {
                return true;
            }
        }

        return false;
    }
}
