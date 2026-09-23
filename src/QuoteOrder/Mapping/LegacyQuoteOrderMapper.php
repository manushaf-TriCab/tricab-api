<?php

declare(strict_types=1);

namespace TriCab\Api\QuoteOrder\Mapping;

use TriCab\Api\QuoteOrder\Support\LegacyRowValue;
use TriCab\Core\Product\Migration\Pricing\LegacyCyrteMappingContract;
use TriCab\Core\QuoteOrder\Application\DTO\OrderDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteDocumentDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteItemDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteItemPackDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderSummary;
use TriCab\Core\QuoteOrder\Domain\Mapping\LegacyQuoteLineTypeDefaults;
use TriCab\Core\QuoteOrder\Domain\ValueObject\MoneyTotal;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteOrderType;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteStatus;

/**
 * Maps legacy MySQL rows to public DTOs. Legacy column names stay here only.
 */
final class LegacyQuoteOrderMapper
{
    private readonly LegacyCyrteMappingContract $currency;

    public function __construct(?LegacyCyrteMappingContract $currency = null)
    {
        $this->currency = $currency ?? new LegacyCyrteMappingContract();
    }

    /**
     * @param array<string, mixed> $row
     */
    public function mapSummary(array $row): QuoteOrderSummary
    {
        $quoteId = $this->string($row['quote_id'] ?? $row['id'] ?? '');
        $orderId = $this->nullableString($row['order_id'] ?? $row['po_id'] ?? null);
        $isOrder = $orderId !== null && $orderId !== '';
        $type = $isOrder ? QuoteOrderType::ORDER : QuoteOrderType::QUOTE;
        $status = QuoteStatus::fromCode(LegacyRowValue::statusCode($row['status_code'] ?? $row['st'] ?? null));
        $money = MoneyTotal::fromLegacyNet(LegacyRowValue::moneyNet($row['total_net'] ?? $row['tnet'] ?? null));
        $raisedBy = $this->resolveRaisedBy($row);

        return new QuoteOrderSummary(
            id: $isOrder ? (string) $orderId : $quoteId,
            type: $type,
            quoteId: $quoteId,
            orderId: $isOrder ? $orderId : null,
            date: $this->nullableString($row['quote_date'] ?? $row['de'] ?? $row['date_sort'] ?? null),
            raisedBy: $raisedBy,
            representative: $this->nullableString($row['representative'] ?? $row['rep_name'] ?? null),
            customerCode: $this->nullableString($row['customer_code'] ?? $row['code'] ?? null),
            customerName: $this->nullableString($row['customer_name'] ?? $row['name'] ?? null),
            reference: $this->resolveReference($row, $isOrder),
            status: $status->status,
            statusCode: $status->statusCode,
            statusLabel: $status->statusLabel,
            total: $money->amount,
            totalStatus: $money->status,
            currency: $this->currencyCode($row['currency_id'] ?? $row['cy'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $header
     * @param list<QuoteItemDetails> $items
     * @param QuoteDocumentDetails|null $document
     */
    public function mapQuoteDetails(array $header, array $items, ?QuoteDocumentDetails $document = null): QuoteDetails
    {
        $summary = $this->mapSummary($header);
        $cusGen = LegacyRowValue::int($header['cus_gen'] ?? null);
        $contactEmail = $this->nullableString($header['contact_email'] ?? null);
        $contactPhone = $this->nullableString($header['contact_phone'] ?? $header['contact_mobile'] ?? null);
        $raisedByEmail = $cusGen === 1 ? $contactEmail : null;
        $raisedByPhone = $cusGen === 1 ? $contactPhone : null;

        $document ??= $this->mapDocumentDetails($header, $raisedByEmail, $raisedByPhone);

        return new QuoteDetails(
            id: $summary->quoteId,
            type: QuoteOrderType::QUOTE,
            quoteId: $summary->quoteId,
            orderId: $summary->orderId,
            date: $summary->date,
            validDate: $this->nullableString($header['valid_date'] ?? $header['dvalid'] ?? null),
            raisedBy: $summary->raisedBy,
            representative: $summary->representative,
            customerCode: $summary->customerCode,
            customerName: $summary->customerName,
            contactName: $this->nullableString($header['contact_name'] ?? null),
            reference: $summary->reference,
            status: $summary->status,
            statusCode: $summary->statusCode,
            statusLabel: $summary->statusLabel,
            total: $summary->total,
            totalStatus: $summary->totalStatus,
            currency: $summary->currency,
            items: $items,
            document: $document,
        );
    }

    /**
     * @param array<string, mixed> $header
     * @param list<array{sizeMm2: string, tricab: string|null, class5: string|null, class6: string|null}> $conductorRows
     * @param array{copperUsd: string|null, copperAud: string|null, aluminiumUsd: string|null, aluminiumAud: string|null, asAt: string|null}|null $metalPrices
     */
    public function mapDocumentDetails(
        array $header,
        ?string $raisedByEmail = null,
        ?string $raisedByPhone = null,
        array $conductorRows = [],
        ?array $metalPrices = null,
    ): QuoteDocumentDetails {
        $country = $this->nullableString($header['customer_country'] ?? $header['country'] ?? null);
        $territory = $this->nullableString($header['customer_territory'] ?? $header['territory'] ?? null);
        $addressLines = [];
        foreach (['customer_ln1', 'customer_ln2', 'customer_ln3', 'customer_ln4', 'ln1', 'ln2', 'ln3', 'ln4'] as $key) {
            $line = $this->nullableString($header[$key] ?? null);
            if ($line !== null && $line !== '' && !in_array($line, $addressLines, true)) {
                $addressLines[] = $line;
            }
        }
        $locality = trim(implode(' ', array_filter([
            $this->nullableString($header['customer_suburb'] ?? $header['suburb'] ?? null),
            $this->nullableString($header['customer_pcode'] ?? $header['pcode'] ?? null),
            $country,
        ], static fn (?string $v): bool => $v !== null && $v !== '')));
        if ($locality !== '') {
            $addressLines[] = strtoupper($locality);
        }

        return new QuoteDocumentDetails(
            customerAddressLines: $addressLines,
            customerPhone: $this->nullableString($header['customer_phone'] ?? $header['phone'] ?? null),
            customerCountry: $country,
            customerTerritory: $territory,
            contactEmail: $this->nullableString($header['contact_email'] ?? null),
            contactPhone: $this->nullableString($header['contact_phone'] ?? $header['contact_mobile'] ?? null),
            contactPosition: $this->nullableString($header['contact_position'] ?? null),
            raisedByEmail: $raisedByEmail ?? $this->nullableString($header['raised_by_email'] ?? null),
            raisedByPhone: $raisedByPhone ?? $this->nullableString($header['raised_by_phone'] ?? null),
            paymentTerms: $this->nullableString($header['payment_terms'] ?? $header['payment_terms_name'] ?? null),
            paymentTermsCode: $this->nullableString($header['payment_terms_code'] ?? null),
            pricesExclusiveOfTax: true,
            sellerTaxId: $this->sellerTaxId($country),
            sellerCompanyName: $this->sellerCompanyName($country, $territory),
            sellerAddressLines: $this->sellerAddressLines($country, $territory),
            sellerPhone: $this->sellerPhone($country, $territory),
            sellerEmail: $this->sellerEmail($country, $territory),
            commercialNotes: $this->commercialNotes($country, $territory),
            metalPrices: $metalPrices,
            conductorComparison: $conductorRows,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param list<QuoteItemPackDetails> $packs
     */
    public function mapItem(array $row, array $packs = []): QuoteItemDetails
    {
        $catdt = LegacyRowValue::int($row['category_type'] ?? $row['catdt'] ?? null);
        $categoryGroup = match (true) {
            $catdt === LegacyQuoteLineTypeDefaults::CABLE_CATEGORY_TYPE => 'Cables',
            $catdt === LegacyQuoteLineTypeDefaults::ACCESSORY_CATEGORY_TYPE => 'Accessories',
            $catdt > 0 => 'Other',
            default => null,
        };
        $colourName = $this->nullableString($row['colour_name'] ?? null);
        $styleDescription = $this->nullableString($row['style_description'] ?? null);
        $productDescription2 = $this->nullableString($row['product_description_2'] ?? null);
        $description = $this->buildLegacyQuoteLineDescription($colourName, $styleDescription, $productDescription2);

        $unitPrice = $this->nullableString($row['unit_price'] ?? $row['nprc'] ?? null);
        $packCount = $this->nullableString($row['pack_count'] ?? $row['qty'] ?? null);
        // Accessories: nprc is pack/unit sell price; cables may differ. Expose as packPrice when packs > 0.
        $packPrice = $unitPrice;
        $packLabel = null;
        if ($categoryGroup === 'Accessories' && $packCount !== null && $packCount !== '') {
            $packLabel = ((float) $packCount === 1.0) ? '1 pack' : $packCount . ' packs';
        } elseif ($categoryGroup === 'Cables' && $packCount !== null) {
            $qty = $this->nullableString($row['quantity'] ?? $row['qotqty'] ?? null);
            $packLabel = $qty !== null ? $qty . 'm' : null;
        }

        return new QuoteItemDetails(
            id: $this->string($row['item_id'] ?? $row['id'] ?? ''),
            itemNo: $this->nullableString($row['item_no'] ?? $row['itm'] ?? null),
            productCode: $this->nullableString($row['product_code'] ?? $row['cata'] ?? null),
            quantity: $this->nullableString($row['quantity'] ?? $row['qotqty'] ?? null),
            packCount: $packCount,
            unitPrice: $unitPrice,
            reference: $this->nullableString($row['item_reference'] ?? $row['ref'] ?? null),
            packs: $packs,
            lineTotal: $this->nullableString($row['line_total'] ?? $row['net'] ?? null),
            categoryGroup: $categoryGroup,
            colourName: $colourName,
            description: $description,
            weightKg: $this->nullableString($row['weight_kg'] ?? null),
            packPrice: $packPrice,
            packLabel: $packLabel,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public function mapPack(array $row): QuoteItemPackDetails
    {
        return new QuoteItemPackDetails(
            id: $this->string($row['id'] ?? ''),
            unitsPerPack: $this->nullableString($row['pk'] ?? null),
            packCount: $this->nullableString($row['qty'] ?? null),
            comments: $this->nullableString($row['comments'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $orderRow
     */
    public function mapOrderDetails(array $orderRow, ?QuoteDetails $quote): OrderDetails
    {
        $orderId = $this->string($orderRow['po_id'] ?? $orderRow['order_id'] ?? '');

        return new OrderDetails(
            id: $orderId,
            orderId: $orderId,
            customerPoNumber: $this->nullableString($orderRow['customer_po_no'] ?? null),
            reference: $this->nullableString($orderRow['reference'] ?? null),
            issueDate: $this->nullableString($orderRow['po_issue_date'] ?? null),
            comments: $this->nullableString($orderRow['comments'] ?? null),
            quoteId: $this->nullableString($orderRow['quote_id'] ?? null),
            quote: $quote,
        );
    }

    public function currencyCode(mixed $currencyId): ?string
    {
        if ($currencyId === null || $currencyId === '') {
            return null;
        }

        $id = LegacyRowValue::int($currencyId);
        if ($id <= 0) {
            return null;
        }

        return $this->currency->currencyForId($id);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveRaisedBy(array $row): ?string
    {
        $cusGen = LegacyRowValue::int($row['cus_gen'] ?? null);
        if ($cusGen === 1) {
            return $this->nullableString($row['contact_name'] ?? $row['raised_by'] ?? null);
        }

        return $this->nullableString($row['raised_by'] ?? $row['rep_name'] ?? null);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveReference(array $row, bool $isOrder): ?string
    {
        if ($isOrder) {
            $ref = $this->nullableString($row['order_reference'] ?? $row['reference'] ?? null);
            if ($ref !== null) {
                return $ref;
            }

            return $this->nullableString($row['customer_po_no'] ?? null);
        }

        return $this->nullableString($row['quote_reference'] ?? $row['cref'] ?? $row['reference'] ?? null);
    }

    private function sellerTaxId(?string $country): ?string
    {
        $country = $country !== null ? strtoupper(trim($country)) : '';
        // Authoritative legacy quotation PDF rule (quote-class.php / UserManagement::getTax).
        if ($country === 'NZ') {
            return 'GST# 68 994 950';
        }
        if ($country === 'AU') {
            return 'ACN 006 373 824';
        }

        return null;
    }

    /**
     * Legal entity names from legacy UserManagement warehouse directory
     * (used when building PDF "From" when office/rep company is not on the quote row).
     */
    private function sellerCompanyName(?string $country, ?string $territory): ?string
    {
        $territory = $territory !== null ? strtoupper(trim($territory)) : '';
        $country = $country !== null ? strtoupper(trim($country)) : '';

        // Legal entity names for quotation From block. NZ offices all use the NZ Ltd entity
        // (legacy PDFs print $repCompanyName / warehouse legal name, not city trade names).
        $byTerritory = [
            'NZNTH' => 'TriCab (New Zealand) Ltd.',
            'NZSTH' => 'TriCab (New Zealand) Ltd.',
            'NZCTL' => 'TriCab (New Zealand) Ltd.',
            'AUC' => 'TriCab (New Zealand) Ltd.',
            'CHR' => 'TriCab (New Zealand) Ltd.',
            'WEL' => 'TriCab (New Zealand) Ltd.',
            'VIC' => 'TriCab (Australia) Pty. Ltd.',
            'QLD' => 'TriCab (Australia) Pty. Ltd.',
            'WA' => 'TriCab (Australia) Pty. Ltd.',
            'NSW' => 'TriCab (Australia) Pty. Ltd.',
            'SA' => 'TriCab (Australia) Pty. Ltd.',
            'TAS' => 'TriCab (Australia) Pty. Ltd.',
            'FNQ' => 'TriCab (Australia) Pty. Ltd.',
            'MA' => 'TriCab (USA) Inc',
        ];
        if ($territory !== '' && isset($byTerritory[$territory])) {
            return $byTerritory[$territory];
        }
        if ($country === 'NZ' || str_starts_with($territory, 'NZ')) {
            return 'TriCab (New Zealand) Ltd.';
        }
        if ($country === 'AU') {
            return 'TriCab (Australia) Pty. Ltd.';
        }
        if ($country === 'US' || $country === 'USA') {
            return 'TriCab (USA) Inc';
        }

        return null;
    }

    /**
     * Seller/FROM office address lines from legacy contacts.php + territory office mapping.
     * Independent of customer address / raised-by contact.
     *
     * @return list<string>
     */
    private function sellerAddressLines(?string $country, ?string $territory): array
    {
        $office = $this->sellerOffice($country, $territory);
        if ($office === null) {
            return [];
        }

        return $office['addressLines'];
    }

    private function sellerPhone(?string $country, ?string $territory): ?string
    {
        return $this->sellerOffice($country, $territory)['phone'] ?? null;
    }

    private function sellerEmail(?string $country, ?string $territory): ?string
    {
        return $this->sellerOffice($country, $territory)['email'] ?? null;
    }

    /**
     * Authoritative TriCab office directory used by legacy quotation PDFs when office/rep
     * address is resolved from territory (contacts.php + UserManagement::hardCodeTricabOffice).
     *
     * @return array{addressLines: list<string>, phone: string, email: string}|null
     */
    private function sellerOffice(?string $country, ?string $territory): ?array
    {
        $territory = $territory !== null ? strtoupper(trim($territory)) : '';
        $country = $country !== null ? strtoupper(trim($country)) : '';

        $byTerritory = [
            // NZ — contacts.php nz_cities + hardCodeTricabOffice
            'NZNTH' => [
                'addressLines' => ['88 Hastie Ave', 'Mangere Bridge', 'AUCKLAND NZ'],
                'phone' => '+64 9 887 1051',
                'email' => 'auckland@tricab.com',
            ],
            'AUC' => [
                'addressLines' => ['88 Hastie Ave', 'Mangere Bridge', 'AUCKLAND NZ'],
                'phone' => '+64 9 887 1051',
                'email' => 'auckland@tricab.com',
            ],
            'NZSTH' => [
                'addressLines' => ['109 Buchanans Road', 'Hornby', 'CHRISTCHURCH NZ'],
                'phone' => '+64 3 381 0447',
                'email' => 'christchurch@tricab.com',
            ],
            'CHR' => [
                'addressLines' => ['109 Buchanans Road', 'Hornby', 'CHRISTCHURCH NZ'],
                'phone' => '+64 3 381 0447',
                'email' => 'christchurch@tricab.com',
            ],
            'NZCTL' => [
                'addressLines' => ['WELLINGTON NZ'],
                'phone' => '+64 4 887 9908',
                'email' => 'wellington@tricab.com',
            ],
            'WEL' => [
                'addressLines' => ['WELLINGTON NZ'],
                'phone' => '+64 4 887 9908',
                'email' => 'wellington@tricab.com',
            ],
            // AU — contacts.php au_cities
            'VIC' => [
                'addressLines' => ['57 Freight Drive', 'Somerton', 'VIC 3062 AU'],
                'phone' => '+61 3 9081 5202',
                'email' => 'melbourne@tricab.com',
            ],
            'QLD' => [
                'addressLines' => ['Building E1, 77 Darlington Drive', 'Yatala', 'QLD 4207 AU'],
                'phone' => '+61 7 3193 6084',
                'email' => 'brisbane@tricab.com',
            ],
            'WA' => [
                'addressLines' => ['11 Fairbrother Street', 'Belmont', 'WA 6104 AU'],
                'phone' => '+61 8 6146 0191',
                'email' => 'perth@tricab.com',
            ],
            'NSW' => [
                'addressLines' => ['31 Liberty Road', 'Huntingwood', 'NSW 2148 AU'],
                'phone' => '+61 2 8662 0098',
                'email' => 'sydney@tricab.com',
            ],
            'SA' => [
                'addressLines' => ['3 Williams Circuit', 'Pooraka', 'SA 5095 AU'],
                'phone' => '+61 8 8423 9951',
                'email' => 'adelaide@tricab.com',
            ],
            'TAS' => [
                'addressLines' => ['34 Chesterman Street', 'Moonah', 'TAS 7009 AU'],
                'phone' => '+61 3 6124 2120',
                'email' => 'hobart@tricab.com',
            ],
            'FNQ' => [
                'addressLines' => ['TOWNSVILLE QLD AU'],
                'phone' => '+61 7 4774 4484',
                'email' => 'townsville@tricab.com',
            ],
        ];

        if ($territory !== '' && isset($byTerritory[$territory])) {
            return $byTerritory[$territory];
        }
        if ($country === 'NZ' || str_starts_with($territory, 'NZ')) {
            return $byTerritory['NZNTH'];
        }
        if ($country === 'AU') {
            return $byTerritory['VIC'];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    /**
     * Commercial notices inside the orange Payment Terms box
     * (tc-web quotes/quote-pdf-format.php).
     *
     * Always includes fitness-of-purpose. NZ region/country appends freight and
     * quantity-revision notices. AU and other regions do NOT get NZ wording.
     * The temporary VIC stock-take notice in legacy is intentionally omitted
     * (dated announcement, not standing commercial terms).
     *
     * @return list<string>
     */
    private function commercialNotes(?string $country, ?string $territory): array
    {
        $notes = [
            'Product "fitness of purpose" is the sole responsilibilty of the customer.',
        ];
        $country = $country !== null ? strtoupper(trim($country)) : '';
        $territory = $territory !== null ? strtoupper(trim($territory)) : '';
        // Legacy gate: if ($custRegion == 'NZ')
        if ($country === 'NZ' || str_starts_with($territory, 'NZ')) {
            $notes[] = 'Please note any orders supplied that do not exceed $500.00 GST incl will incur a $ 25.00 freight fee.';
            $notes[] = 'Pricing supplied on NZ Tricab quotations are based on quantities requested.  Any changes to requested quantities will be subject to a revision of pricing at Tricab NZ discretion.';
            $notes[] = 'Any purchase orders received with amendments to the quantities within the validity period will be subject to revision of pricing by Tricab NZ with an order confirmation sent advising of any changes.';
        }

        return $notes;
    }

    /**
     * Legacy quotation PDF description (quote-pdf-format.php):
     * ucfirst(colour) + ' ' + Description1 + optional ' - ' + description2
     * where Description1 = descript.desc_name (desc_type=24, style)
     * and description2 = tpro_ext.desc2.
     *
     * colourName stays a separate semantic field on QuoteItemDetails.
     */
    private function buildLegacyQuoteLineDescription(
        ?string $colourName,
        ?string $styleDescription,
        ?string $productDescription2,
    ): ?string {
        $colour = $colourName !== null && trim($colourName) !== ''
            ? ucfirst(strtolower(trim($colourName)))
            : '';
        $style = $styleDescription !== null ? trim($styleDescription) : '';
        $desc2 = $productDescription2 !== null ? trim($productDescription2) : '';

        $primary = trim($colour . ' ' . $style);
        if ($desc2 !== '') {
            $primary = $primary === '' ? $desc2 : ($primary . ' - ' . $desc2);
        }

        return $primary === '' ? null : $primary;
    }

    private function string(mixed $value): string
    {
        return trim(LegacyRowValue::string($value));
    }

    private function nullableString(mixed $value): ?string
    {
        return LegacyRowValue::stringOrNull($value);
    }
}
