<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Domain\Mapping;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\QuoteOrder\Application\Command\PersistQuoteDraft;
use TriCab\Core\QuoteOrder\Application\Command\PersistQuoteDraftExtendedLine;
use TriCab\Core\QuoteOrder\Application\Command\PersistQuoteDraftLine;

/**
 * Pure NEW → legacy column mapping. No SQL execution.
 */
final class PersistQuoteDraftMapper
{
    public const MELBOURNE_TZ = 'Australia/Melbourne';

    public function __construct(
        private readonly LegacyQuoteCurrencyMapper $currency = new LegacyQuoteCurrencyMapper(),
        private readonly LegacyQuoteStatusMapper $status = new LegacyQuoteStatusMapper(),
    ) {
    }

    /**
     * @return array{
     *   classification: array<string, string>,
     *   header: array<string, scalar|null>,
     *   lines: list<array{line: array<string, scalar|null>, extended: list<array<string, scalar|null>>}>,
     *   totalNet: string
     * }
     */
    public function map(PersistQuoteDraft $draft): array
    {
        $draft->assertValidForPersist();
        $this->status->assertDraftStatus($draft->statusCode);

        $createdMelbourne = $draft->createdAt->setTimezone(new DateTimeZone(self::MELBOURNE_TZ));
        $validMelbourne = $draft->validUntil->setTimezone(new DateTimeZone(self::MELBOURNE_TZ));
        $totalNet = $draft->derivedTotalNet();
        $currencyId = $this->currency->legacyIdForCode($draft->currencyCode);

        $header = [
            'dt' => $draft->documentType,
            'st' => (int) $draft->statusCode,
            'de' => $createdMelbourne->format('Y-m-d'),
            'tme' => (int) $createdMelbourne->format('Hi'),
            'cod' => $draft->companyCode,
            'sa' => $draft->officeId,
            'sa_op' => $draft->raisedByOperatorId,
            'mtt' => $draft->metricType,
            'cus' => $draft->customerId,
            'ptm' => $draft->paymentTermsId,
            'cy' => $currencyId,
            'tnet' => '0.00',
            'tcval' => $this->money($draft->totalCostValue),
            'dvalid' => $validMelbourne->format('Y-m-d'),
            'cref' => $draft->reference,
            'cus_op' => $draft->recipientContactId,
            'cus_gen' => $draft->customerGenerated ? 1 : null,
        ];

        $lines = [];
        foreach ($draft->lines as $index => $line) {
            $lines[] = [
                'line' => $this->mapLine($line, $index + 1),
                'extended' => array_map(
                    static fn (PersistQuoteDraftExtendedLine $ext): array => [
                        'pk' => $ext->unitsPerPack,
                        'qty' => $ext->packCount,
                        'comments' => $ext->comments,
                    ],
                    $line->extended,
                ),
            ];
        }

        return [
            'classification' => $this->fieldClassifications(),
            'header' => $header,
            'lines' => $lines,
            'totalNet' => $totalNet,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function fieldClassifications(): array
    {
        return [
            'dt' => 'PROVEN',
            'st' => 'DERIVED',
            'de' => 'DERIVED',
            'tme' => 'DERIVED',
            'cod' => 'DEFAULTED',
            'sa' => 'DEFAULTED',
            'sa_op' => 'DERIVED',
            'mtt' => 'DEFAULTED',
            'cus' => 'DERIVED',
            'ptm' => 'DEFAULTED',
            'cy' => 'DERIVED',
            'tnet' => 'DERIVED',
            'tcval' => 'DEFAULTED',
            'dvalid' => 'DERIVED',
            'cref' => 'DERIVED',
            'cus_op' => 'DERIVED',
            'cus_gen' => 'DERIVED',
            'qot' => 'DERIVED',
            'itm' => 'DERIVED',
            'cata' => 'DERIVED',
            'catdt' => 'DERIVED',
            'col' => 'DERIVED',
            'qty' => 'DERIVED',
            'pm' => 'DERIVED',
            'nprc' => 'DERIVED',
            'net' => 'DERIVED',
            'cprc' => 'DERIVED',
            'cval' => 'DERIVED',
            'qotqty' => 'DERIVED',
            'ref' => 'DERIVED',
            'qoti' => 'DERIVED',
            'pk' => 'DERIVED',
            'tqote.qty' => 'DERIVED',
            'comments' => 'DERIVED',
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    private function mapLine(PersistQuoteDraftLine $line, int $lineNo): array
    {
        return [
            'itm' => $lineNo,
            'cata' => $line->productCode,
            'catdt' => $line->categoryType,
            'col' => $line->colourId,
            'qty' => $this->decimal($line->packCount, 4),
            'pm' => $line->packMatrixId,
            'nprc' => $this->money($line->sellUnitPrice),
            'net' => $this->money($line->lineNet),
            'cprc' => $this->money($line->costUnitPrice),
            'cval' => $this->money($line->costValue),
            'qotqty' => $this->decimal($line->unitsOrLength, 4),
            'ref' => $line->reference,
        ];
    }

    private function money(string $amount): string
    {
        return bcadd(trim($amount), '0', 2);
    }

    private function decimal(string $amount, int $scale): string
    {
        return bcadd(trim($amount), '0', $scale);
    }

    public function formatCreatedDate(DateTimeImmutable $createdAt): string
    {
        return $createdAt->setTimezone(new DateTimeZone(self::MELBOURNE_TZ))->format('Y-m-d');
    }

    public function formatCreatedTime(DateTimeImmutable $createdAt): int
    {
        return (int) $createdAt->setTimezone(new DateTimeZone(self::MELBOURNE_TZ))->format('Hi');
    }
}
