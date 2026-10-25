<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\QuoteOrder\DocumentGeneration;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TriCab\Api\QuoteOrder\DocumentGeneration\QuoteDetailsToDocGenQuoteMapper;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteDocumentDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteItemDetails;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteOrderType;
use TriCab\DocGen\DocGen;
use TriCab\DocGen\Model\ConductorComparisonRow;

final class QuoteDetailsToDocGenQuoteMapperTest extends TestCase
{
    public function test_maps_quote_details_onto_a_docgen_quote(): void
    {
        $quote = (new QuoteDetailsToDocGenQuoteMapper())->map($this->quoteDetails());

        self::assertSame('100146098', $quote->number);
        self::assertSame('Quotation-100146098.pdf', $quote->filename());
        self::assertInstanceOf(DateTimeImmutable::class, $quote->date);
        self::assertSame('2026-09-22', $quote->date->format('Y-m-d'));
        self::assertInstanceOf(DateTimeImmutable::class, $quote->validUntil);
        self::assertSame('2026-09-24', $quote->validUntil->format('Y-m-d'));
        self::assertSame('ACME01', $quote->customerCode);
        self::assertSame('PO-77', $quote->customerReference);
        self::assertSame('AUD', $quote->currency);
        self::assertSame('1,227.43', $quote->total);
        self::assertSame('30 days from invoice date', $quote->paymentTerms);
        self::assertFalse($quote->pricesIncludeTax);
        self::assertSame('Jordan', $quote->attention);
        self::assertSame('Alex Rep', $quote->raisedBy);
        self::assertSame('alex@tricab.com', $quote->raisedByEmail);

        self::assertSame('TriCab (Australia) Pty. Ltd.', $quote->seller->name);
        self::assertSame(['31 Liberty Road', 'Huntingwood', 'NSW 2148 AU'], $quote->seller->addressLines);
        self::assertSame('+61 2 8662 0098', $quote->seller->phone);
        self::assertSame(['ACN 006 373 824'], $quote->seller->taxIds);
        self::assertSame(['Acme Pty', '1 Street', 'SYDNEY NSW AU'], $quote->customer->lines());
        self::assertSame('14037', $quote->metalPrices?->copperUsd);
        self::assertSame('13', $quote->metalPrices->aluminiumAud);

        self::assertCount(1, $quote->lines);
        $line = $quote->lines[0];
        self::assertSame('2', $line->itemNo);
        self::assertSame('KU-PAZN/4C16BK', $line->productCode);
        self::assertSame('40', $line->quantity);
        self::assertSame('m', $line->unit);
        self::assertSame('12.50', $line->unitPrice);
        self::assertSame('500.00', $line->total);
        self::assertSame('40m', $line->packLabel);
        self::assertSame('Black Flexible Rubber EMC X-90 0.6/1KV 90C AS/NZS 5000.1 - (RE,WH,BL,EA)', $line->description);

        self::assertEquals([
            new ConductorComparisonRow('50', '658', '378', '662'),
            new ConductorComparisonRow('70', '969', '342', '927'),
            new ConductorComparisonRow('630', '8784', '2155', null),
        ], $quote->conductorComparison);
    }

    public function test_rendered_quotation_shows_the_mapped_values(): void
    {
        $html = (new DocGen())->renderHtml((new QuoteDetailsToDocGenQuoteMapper())->map($this->quoteDetails()));

        self::assertStringContainsString('QUOTATION No 100146098', $html);
        self::assertStringContainsString('22 Sep 26', $html);
        self::assertStringContainsString('AUD 1,227.43', $html);
        self::assertStringContainsString('KU-PAZN/4C16BK — 40m', $html);
        self::assertStringContainsString('Conductor Comparison - TriCab versus Class 5 &amp; 6', $html);
    }

    public function test_falls_back_when_the_quote_has_no_document_details(): void
    {
        $quote = (new QuoteDetailsToDocGenQuoteMapper())->map(new QuoteDetails(
            id: '1001',
            type: QuoteOrderType::QUOTE,
            quoteId: '',
            orderId: null,
            date: 'not a date',
            validDate: null,
            raisedBy: null,
            representative: null,
            customerCode: null,
            customerName: 'Acme Pty',
            contactName: null,
            reference: null,
            status: 'open',
            statusCode: '1710',
            statusLabel: 'Open',
            total: null,
            totalStatus: 'unresolved',
            currency: null,
            items: [new QuoteItemDetails(
                id: '1',
                itemNo: null,
                productCode: 'G07-AAXX/001',
                quantity: null,
                packCount: '3',
                unitPrice: null,
                reference: null,
                colourName: 'Black',
            )],
        ));

        self::assertSame('1001', $quote->number);
        self::assertSame('not a date', $quote->date);
        self::assertNull($quote->validUntil);
        self::assertNull($quote->total);
        self::assertNull($quote->metalPrices);
        self::assertSame([], $quote->seller->taxIds);
        self::assertSame('1', $quote->lines[0]->itemNo);
        self::assertSame('Black G07-AAXX/001', $quote->lines[0]->description);
        self::assertSame('3', $quote->lines[0]->quantity);
        self::assertSame('U', $quote->lines[0]->unit);
    }

    private function quoteDetails(): QuoteDetails
    {
        return new QuoteDetails(
            id: '100146098',
            type: QuoteOrderType::QUOTE,
            quoteId: '100146098',
            orderId: null,
            date: '2026-09-22',
            validDate: '2026-09-24',
            raisedBy: 'Alex Rep',
            representative: 'Alex Rep',
            customerCode: 'ACME01',
            customerName: 'Acme Pty',
            contactName: 'Jordan',
            reference: 'PO-77',
            status: 'open',
            statusCode: '1710',
            statusLabel: 'Open',
            total: '1227.43',
            totalStatus: 'resolved',
            currency: 'AUD',
            items: [
                new QuoteItemDetails(
                    id: '1',
                    itemNo: '2',
                    productCode: 'KU-PAZN/4C16BK',
                    quantity: '40',
                    packCount: '40',
                    unitPrice: '12.50',
                    reference: null,
                    packs: [],
                    lineTotal: '500.00',
                    categoryGroup: 'Cables',
                    colourName: 'Black',
                    description: 'Black Flexible Rubber EMC X-90 0.6/1KV 90C AS/NZS 5000.1 - (RE,WH,BL,EA)',
                    packLabel: '40m',
                ),
            ],
            document: new QuoteDocumentDetails(
                customerAddressLines: ['1 Street', 'SYDNEY NSW AU'],
                customerCountry: 'AU',
                customerTerritory: 'NSW',
                raisedByEmail: 'alex@tricab.com',
                paymentTerms: '30 days from invoice date',
                pricesExclusiveOfTax: true,
                sellerTaxId: 'ACN 006 373 824',
                sellerCompanyName: 'TriCab (Australia) Pty. Ltd.',
                sellerAddressLines: ['31 Liberty Road', 'Huntingwood', 'NSW 2148 AU'],
                sellerPhone: '+61 2 8662 0098',
                sellerEmail: 'sydney@tricab.com',
                metalPrices: [
                    'copperUsd' => '14037',
                    'copperAud' => '21010',
                    'aluminiumUsd' => '1657',
                    'aluminiumAud' => '13',
                    'asAt' => null,
                ],
                conductorComparison: [
                    ['sizeMm2' => '50', 'tricab' => '658', 'class5' => '378', 'class6' => '662'],
                    ['sizeMm2' => '70', 'tricab' => '969', 'class5' => '342', 'class6' => '927'],
                    ['sizeMm2' => '630', 'tricab' => '8784', 'class5' => '2155', 'class6' => null],
                ],
            ),
        );
    }
}
