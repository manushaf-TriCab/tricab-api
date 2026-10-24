<?php

declare(strict_types=1);

namespace TriCab\Api\QuoteOrder\DocumentGeneration;

use DateTimeImmutable;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteItemDetails;
use TriCab\DocGen\Model\Company;
use TriCab\DocGen\Model\ConductorComparisonRow;
use TriCab\DocGen\Model\MetalPrices;
use TriCab\DocGen\Model\Party;
use TriCab\DocGen\Model\Quote;
use TriCab\DocGen\Model\QuoteLine;

/**
 * Maps the quote read model onto a tricab/docgen Quote.
 *
 * Money arrives as unformatted decimal strings ("1227.43") and is formatted
 * here ("1,227.43"); anything else that is not numeric is printed as given.
 */
final class QuoteDetailsToDocGenQuoteMapper
{
    public function map(QuoteDetails $quote): Quote
    {
        $document = $quote->document;
        $metal = $document?->metalPrices;
        $taxId = trim($document->sellerTaxId ?? '');

        return new Quote(
            number: $quote->quoteId !== '' ? $quote->quoteId : $quote->id,
            seller: new Company(
                name: $document->sellerCompanyName ?? '',
                addressLines: $document->sellerAddressLines ?? [],
                phone: $document?->sellerPhone,
                email: $document?->sellerEmail,
                taxIds: $taxId !== '' ? [$taxId] : [],
            ),
            customer: new Party($quote->customerName ?? '', $document->customerAddressLines ?? []),
            lines: array_map(
                fn (QuoteItemDetails $item, int $index): QuoteLine => $this->line($item, $index + 1),
                $quote->items,
                array_keys($quote->items),
            ),
            total: $this->money($quote->total),
            currency: $quote->currency,
            date: $this->date($quote->date),
            validUntil: $this->date($quote->validDate),
            customerCode: $quote->customerCode,
            customerReference: $quote->reference,
            attention: $quote->contactName,
            raisedBy: $quote->raisedBy,
            raisedByEmail: $document?->raisedByEmail,
            paymentTerms: $document?->paymentTerms,
            pricesIncludeTax: $document !== null && !$document->pricesExclusiveOfTax,
            metalPrices: $metal === null ? null : new MetalPrices(
                copperUsd: $metal['copperUsd'] ?? null,
                copperAud: $metal['copperAud'] ?? null,
                aluminiumUsd: $metal['aluminiumUsd'] ?? null,
                aluminiumAud: $metal['aluminiumAud'] ?? null,
            ),
            conductorComparison: array_map(
                static fn (array $row): ConductorComparisonRow => new ConductorComparisonRow(
                    $row['sizeMm2'],
                    $row['tricab'],
                    $row['class5'],
                    $row['class6'],
                ),
                $document->conductorComparison ?? [],
            ),
        );
    }

    private function line(QuoteItemDetails $item, int $fallbackNo): QuoteLine
    {
        $colour = trim($item->colourName ?? '');
        $description = $item->description
            ?? ($colour !== '' ? $colour . ' ' : '') . ($item->productCode ?? '');

        return new QuoteLine(
            itemNo: $item->itemNo !== null && $item->itemNo !== '' ? $item->itemNo : (string) $fallbackNo,
            description: trim($description),
            quantity: $item->quantity ?? $item->packCount ?? '',
            unit: $this->unit($item),
            unitPrice: $this->money($item->unitPrice),
            total: $this->money($item->lineTotal),
            productCode: $item->productCode,
            packLabel: $item->packLabel,
        );
    }

    private function unit(QuoteItemDetails $item): string
    {
        $label = trim($item->packLabel ?? '');
        if ($label !== '' && preg_match('/\d+\s*m\b/i', $label) === 1) {
            return 'm';
        }
        if ($item->categoryGroup === 'Cables') {
            return 'm';
        }

        return $label !== '' ? $label : 'U';
    }

    private function date(?string $value): DateTimeImmutable|string|null
    {
        $raw = trim($value ?? '');
        if ($raw === '') {
            return null;
        }

        return DateTimeImmutable::createFromFormat('!Y-m-d', $raw)
            ?: DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $raw)
            ?: $raw;
    }

    private function money(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $trimmed = trim($value);

        return is_numeric($trimmed) ? number_format((float) $trimmed, 2, '.', ',') : $trimmed;
    }
}
