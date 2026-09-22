<?php

declare(strict_types=1);

namespace TriCab\Api\QuoteOrder\DocumentGeneration;

use DateTimeImmutable;
use TriCab\Core\DocumentGeneration\Legacy\LegacyRecord;
use TriCab\Core\DocumentGeneration\Legacy\LegacyStream;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteItemDetails;

/**
 * Maps authoritative QuoteDetails into a tcqu.ocr LegacyStream so the existing
 * DompdfPdfRenderer can render resources/document-generation/templates/quote.php
 * without modifying that template.
 *
 * Field layouts follow the audited tcqu.ocr dictionary + tcquo.ocr.txt fixture
 * and the field indexes actually read by quote.php.
 */
final class QuoteDetailsToTcquLegacyStreamMapper
{
    private const SELECTOR = '.RET./l/ocr/ps//tcqu.ocr';

    public function map(QuoteDetails $quote): LegacyStream
    {
        $docId = $quote->quoteId !== '' ? $quote->quoteId : $quote->id;
        $document = $quote->document;
        $records = [];

        $records[] = $this->record('S006', 21, [
            $docId,
            $quote->quoteId,
            $this->ocrDate($quote->date),
            '',
            '',
            $document?->sellerTaxId ?? '',
            '',
            '',
            '',
            $quote->customerCode ?? '',
            $quote->reference ?? '',
            $quote->currency ?? '',
            $this->money($quote->total),
            $document?->paymentTermsCode ?? '',
            $document?->paymentTerms ?? '',
            '',
            $this->ocrDate($quote->validDate),
            ($document?->pricesExclusiveOfTax ?? true) ? 'N' : 'Y',
            '',
            '',
            '',
        ], 1);

        $metal = $document?->metalPrices;
        $records[] = $this->record('S026', 7, [
            $docId,
            $metal['copperUsd'] ?? '',
            $metal['copperAud'] ?? '',
            'TONN',
            'LME',
            $metal['aluminiumUsd'] ?? '',
            $metal['aluminiumAud'] ?? '',
        ], 2);

        $records[] = $this->record('S011', 4, [
            $docId,
            $quote->raisedBy ?? '',
            $document?->raisedByEmail ?? '',
            $document?->raisedByPhone ?? '',
        ], 3);

        $sellerLines = $document?->sellerAddressLines ?? [];
        $sellerPhone = $document?->sellerPhone;
        $sellerEmail = $document?->sellerEmail;
        $records[] = $this->record('S007', 8, [
            $docId,
            $document?->sellerCompanyName ?? '',
            $sellerLines[0] ?? '',
            $sellerLines[1] ?? '',
            $sellerLines[2] ?? ($sellerLines[3] ?? ''),
            $sellerPhone !== null && $sellerPhone !== '' ? 'T  ' . $sellerPhone : '',
            $sellerEmail ?? '',
            '',
        ], 4);

        $records[] = $this->record('S012', 6, [
            $docId,
            $quote->contactName ?? '',
            $document?->contactPhone ?? '',
            '',
            '',
            '',
        ], 5);

        $customerLines = $document?->customerAddressLines ?? [];
        $records[] = $this->record('S008', 7, [
            $docId,
            $quote->customerName ?? '',
            $customerLines[0] ?? '',
            $customerLines[1] ?? '',
            $customerLines[2] ?? '',
            $customerLines[3] ?? '',
            '',
        ], 6);

        $line = 7;
        foreach ($quote->items as $index => $item) {
            $records[] = $this->itemRecord($docId, $item, $index + 1, $line);
            ++$line;
        }

        return new LegacyStream(self::SELECTOR, $records);
    }

    private function itemRecord(string $docId, QuoteItemDetails $item, int $fallbackNo, int $sourceLine): LegacyRecord
    {
        $itemNo = $item->itemNo !== null && $item->itemNo !== '' ? $item->itemNo : (string) $fallbackNo;
        $description = $item->description
            ?? (($item->colourName !== null && $item->colourName !== '' ? $item->colourName . ' ' : '') . ($item->productCode ?? ''));
        $unit = $this->itemUnit($item);
        $qty = $item->quantity ?? $item->packCount ?? '';

        return $this->record('S009', 19, [
            $docId,
            $itemNo,
            $item->productCode ?? '',
            $item->colourName ?? '',
            $qty,
            $unit,
            $this->money($item->unitPrice),
            $this->money($item->lineTotal),
            trim($description),
            '',
            '',
            '',
            $this->money($item->lineTotal),
            '',
            '',
            '',
            '',
            '',
            $item->packLabel ?? '',
        ], $sourceLine);
    }

    private function itemUnit(QuoteItemDetails $item): string
    {
        $label = trim((string) ($item->packLabel ?? ''));
        if ($label !== '' && preg_match('/\d+\s*m\b/i', $label) === 1) {
            return 'm';
        }
        if ($item->categoryGroup === 'Cables') {
            return 'm';
        }
        if ($label !== '') {
            return $label;
        }

        return 'U';
    }

    /**
     * @param list<string> $fields
     */
    private function record(string $type, int $declared, array $fields, int $sourceLine): LegacyRecord
    {
        if (count($fields) < $declared) {
            $fields = array_merge($fields, array_fill(0, $declared - count($fields), ''));
        }
        if (count($fields) > $declared) {
            $fields = array_slice($fields, 0, $declared);
        }

        return new LegacyRecord($type, $declared, $fields, $sourceLine);
    }

    private function ocrDate(?string $isoOrRaw): string
    {
        if ($isoOrRaw === null || trim($isoOrRaw) === '') {
            return '';
        }
        $raw = trim($isoOrRaw);
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $raw)
            ?: DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $raw);
        if ($dt instanceof DateTimeImmutable) {
            return $dt->format('d/m/y');
        }

        return $raw;
    }

    private function money(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }
        $trimmed = trim($value);
        if (!is_numeric($trimmed)) {
            return $trimmed;
        }

        return number_format((float) $trimmed, 2, '.', ',');
    }
}
