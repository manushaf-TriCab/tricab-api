<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Command;

use DateTimeImmutable;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteWriteInvalidCommandException;

/**
 * Already-authorized, server-priced draft persistence command.
 * Not an HTTP request DTO — controllers must not populate authoritative pricing.
 *
 * Header fields such as documentType/companyCode/officeId/metricType/paymentTermsId
 * must be supplied by the application layer from proven defaults or customer context;
 * the repository does not invent them.
 */
final readonly class PersistQuoteDraft
{
    /**
     * @param list<PersistQuoteDraftLine> $lines
     */
    public function __construct(
        public int $documentType,
        public string $statusCode,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $validUntil,
        public int $companyCode,
        public int $officeId,
        public int $raisedByOperatorId,
        public int $metricType,
        public int $customerId,
        public int $paymentTermsId,
        public string $currencyCode,
        public string $reference,
        public bool $customerGenerated,
        public array $lines,
        public ?int $recipientContactId = null,
        public string $totalCostValue = '0.00',
    ) {
    }

    /**
     * Authoritative quote total = sum of validated line nets (bcmath).
     */
    public function derivedTotalNet(): string
    {
        $sum = '0.00';
        foreach ($this->lines as $line) {
            $sum = bcadd($sum, $this->normalizeMoney($line->lineNet), 2);
        }

        return $sum;
    }

    public function assertValidForPersist(): void
    {
        if ($this->lines === []) {
            throw QuoteWriteInvalidCommandException::because('Draft must contain at least one line.');
        }
        if ($this->customerId <= 0) {
            throw QuoteWriteInvalidCommandException::because('customerId must be a positive legacy cust.id.');
        }
        if ($this->documentType <= 0
            || $this->companyCode <= 0
            || $this->officeId <= 0
            || $this->metricType <= 0
            || $this->paymentTermsId <= 0
        ) {
            throw QuoteWriteInvalidCommandException::because(
                'documentType, companyCode, officeId, metricType, and paymentTermsId must be positive proven values.',
            );
        }
        if (trim($this->currencyCode) === '') {
            throw QuoteWriteInvalidCommandException::because('currencyCode is required.');
        }
        if (trim($this->statusCode) === '') {
            throw QuoteWriteInvalidCommandException::because('statusCode is required.');
        }
        foreach ($this->lines as $index => $line) {
            if (trim($line->productCode) === '') {
                throw QuoteWriteInvalidCommandException::because(
                    sprintf('Line %d productCode is required.', $index + 1),
                );
            }
            if ($line->categoryType <= 0) {
                throw QuoteWriteInvalidCommandException::because(
                    sprintf('Line %d categoryType must be positive.', $index + 1),
                );
            }
            foreach (['packCount', 'unitsOrLength', 'sellUnitPrice', 'lineNet', 'costUnitPrice', 'costValue'] as $field) {
                $value = $line->{$field};
                if (!is_string($value) || trim($value) === '' || !is_numeric($value)) {
                    throw QuoteWriteInvalidCommandException::because(
                        sprintf('Line %d %s must be a numeric string.', $index + 1, $field),
                    );
                }
            }
        }
    }

    private function normalizeMoney(string $amount): string
    {
        return bcadd(trim($amount), '0', 2);
    }
}
