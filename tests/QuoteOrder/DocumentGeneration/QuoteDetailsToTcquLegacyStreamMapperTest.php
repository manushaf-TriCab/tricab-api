<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\QuoteOrder\DocumentGeneration;

use PHPUnit\Framework\TestCase;
use TriCab\Api\QuoteOrder\DocumentGeneration\QuoteDetailsToTcquLegacyStreamMapper;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteDocumentDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteItemDetails;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteOrderType;

final class QuoteDetailsToTcquLegacyStreamMapperTest extends TestCase
{
    public function test_maps_quote_into_tcqu_stream_fields_used_by_quote_php(): void
    {
        $mapper = new QuoteDetailsToTcquLegacyStreamMapper();
        $stream = $mapper->map(new QuoteDetails(
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
                    description: 'Black',
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
            ),
        ));

        self::assertSame('tcqu.ocr', $stream->templateName());
        $header = $stream->first('S006');
        self::assertNotNull($header);
        self::assertSame('100146098', $header->field(1));
        self::assertSame('22/09/26', $header->field(2));
        self::assertSame('ACME01', $header->field(9));
        self::assertSame('PO-77', $header->field(10));
        self::assertSame('AUD', $header->field(11));
        self::assertSame('1,227.43', $header->field(12));
        self::assertSame('30 days from invoice date', $header->field(14));
        self::assertSame('24/09/26', $header->field(16));
        self::assertSame('N', $header->field(17));

        $sales = $stream->first('S011');
        self::assertSame('Alex Rep', $sales?->field(1));
        self::assertSame('alex@tricab.com', $sales?->field(2));

        $item = $stream->first('S009');
        self::assertSame('2', $item?->field(1));
        self::assertSame('KU-PAZN/4C16BK', $item?->field(2));
        self::assertSame('40', $item?->field(4));
        self::assertSame('m', $item?->field(5));
        self::assertSame('12.50', $item?->field(6));
        self::assertSame('Black', $item?->field(8));
        self::assertSame('500.00', $item?->field(12));
    }
}
