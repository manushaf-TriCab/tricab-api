<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Service;

use TriCab\Core\QuoteOrder\Application\Command\PersistQuoteDraft;
use TriCab\Core\QuoteOrder\Application\Command\PersistQuoteDraftLine;
use TriCab\Core\QuoteOrder\Application\DTO\SaveQuoteLinePricingSnapshot;
use TriCab\Core\QuoteOrder\Domain\Mapping\LegacyQuoteStatusMapper;
use TriCab\Core\QuoteOrder\Domain\Mapping\PersistQuoteDraftMapper;
use TriCab\Core\QuoteOrder\Domain\Policy\QuoteHeaderDefaults;
use TriCab\Core\QuoteOrder\Domain\Policy\QuoteValidityPolicy;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;
use DateTimeZone;

/**
 * Maps server-priced line snapshots → PersistQuoteDraft (no SQL).
 */
final class CartQuoteDraftMapper
{
    public function __construct(
        private readonly QuoteValidityPolicy $validity,
        private readonly LegacyQuoteStatusMapper $status = new LegacyQuoteStatusMapper(),
        private readonly PersistQuoteDraftMapper $persistMapper = new PersistQuoteDraftMapper(),
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    /**
     * @param list<SaveQuoteLinePricingSnapshot> $snapshots
     */
    public function toDraft(
        int $legacyCustomerId,
        string $currencyCode,
        string $reference,
        bool $customerGenerated,
        int $raisedByOperatorId,
        ?int $recipientContactId,
        array $snapshots,
        ?string $platformOperationId = null,
    ): PersistQuoteDraft {
        $createdAt = $this->clock->now()->setTimezone(new DateTimeZone(PersistQuoteDraftMapper::MELBOURNE_TZ));
        $validUntil = $this->validity->validUntil($createdAt);
        $statusCode = $this->status->draftStatusCode($customerGenerated);

        $lines = [];
        foreach ($snapshots as $snap) {
            $sell = $snap->isTbc() ? '0.00' : bcadd($snap->sellUnitPrice, '0', 2);
            $net = $snap->isTbc() ? '0.00' : bcadd($snap->lineNet, '0', 2);
            $costUnit = $snap->isTbc() ? '0.00' : bcadd($snap->costUnitPrice, '0', 2);
            $costVal = $snap->isTbc() ? '0.00' : bcadd($snap->costValue, '0', 2);

            $lines[] = new PersistQuoteDraftLine(
                productCode: $snap->productCode,
                categoryType: $snap->categoryType,
                packCount: (string) $snap->packCount,
                unitsOrLength: $snap->unitsOrLength,
                sellUnitPrice: $sell,
                lineNet: $net,
                costUnitPrice: $costUnit,
                costValue: $costVal,
                colourId: $snap->colourId,
                packMatrixId: $snap->packMatrixId,
                reference: $snap->reference,
                extended: [],
            );
        }

        return new PersistQuoteDraft(
            documentType: QuoteHeaderDefaults::DOCUMENT_TYPE,
            statusCode: $statusCode,
            createdAt: $createdAt,
            validUntil: $validUntil,
            companyCode: QuoteHeaderDefaults::COMPANY_CODE,
            officeId: QuoteHeaderDefaults::OFFICE_ID,
            raisedByOperatorId: $raisedByOperatorId,
            metricType: QuoteHeaderDefaults::METRIC_TYPE,
            customerId: $legacyCustomerId,
            paymentTermsId: QuoteHeaderDefaults::PAYMENT_TERMS_ID,
            currencyCode: $currencyCode,
            reference: $reference,
            customerGenerated: $customerGenerated,
            lines: $lines,
            recipientContactId: $recipientContactId,
            totalCostValue: QuoteHeaderDefaults::TOTAL_COST_VALUE,
            platformOperationId: $platformOperationId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(PersistQuoteDraft $draft): array
    {
        return $this->persistMapper->map($draft);
    }
}
