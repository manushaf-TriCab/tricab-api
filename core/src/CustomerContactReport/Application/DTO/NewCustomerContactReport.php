<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\DTO;

use DateTimeImmutable;
use TriCab\Core\CustomerContactReport\Domain\Credit\CustomerContactReportCreditFigures;
use TriCab\Core\CustomerContactReport\Domain\Credit\CustomerContactReportLine;

/**
 * What create is allowed to persist. Status, number, route, assignee and
 * identity are not on this object.
 */
final readonly class NewCustomerContactReport
{
    public function __construct(
        public string $publicId,
        public int $createdByUserId,
        public string $createdByEmail,
        public string $createdByName,
        public DateTimeImmutable $createdAtUtc,
        public DateTimeImmutable $reportDate,
        public string $location,
        public string $customerCode,
        public ?string $customerContact,
        public ?string $tricabRefTrace,
        public ?string $traceNum,
        public ?string $conNoteNo,
        public ?string $tricabContact,
        public string $productCode,
        public string $description,
        public ?string $orderNo = null,
        public ?string $invoiceNo = null,
        public ?string $creditRequired = null,
        public ?string $creditType = null,
        public ?string $restockingFeeApplies = null,
        public ?string $quotedAmount = null,
        public ?string $invoicedAmount = null,
        public ?string $addGst = null,
        public ?string $requestedActions = null,
        public ?CustomerContactReportCreditFigures $figures = null,
        /** @var list<CustomerContactReportLine> */
        public array $lines = [],
    ) {
    }
}
