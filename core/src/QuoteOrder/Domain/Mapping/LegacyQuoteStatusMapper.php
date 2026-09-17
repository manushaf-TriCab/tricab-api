<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Domain\Mapping;

use InvalidArgumentException;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteWriteInvalidCommandException;

/**
 * Central legacy status codes for create/finalize. Phase 4B persists drafts only.
 */
final class LegacyQuoteStatusMapper
{
    public const DRAFT_STAFF = '1705';
    public const FINAL_STAFF = '1703';
    public const DRAFT_CUSTOMER = '1710';
    public const FINAL_CUSTOMER = '1711';

    public function draftStatusCode(bool $customerGenerated): string
    {
        return $customerGenerated ? self::DRAFT_CUSTOMER : self::DRAFT_STAFF;
    }

    public function finalStatusCode(bool $customerGenerated): string
    {
        return $customerGenerated ? self::FINAL_CUSTOMER : self::FINAL_STAFF;
    }

    public function assertDraftStatus(string $statusCode): void
    {
        $code = trim($statusCode);
        if ($code !== self::DRAFT_STAFF && $code !== self::DRAFT_CUSTOMER) {
            throw QuoteWriteInvalidCommandException::because(
                sprintf('Phase 4B persists draft statuses only (1705/1710). Got "%s".', $statusCode),
            );
        }
    }

    public function isDraft(string $statusCode): bool
    {
        $code = trim($statusCode);

        return $code === self::DRAFT_STAFF || $code === self::DRAFT_CUSTOMER;
    }

    public function isFinal(string $statusCode): bool
    {
        $code = trim($statusCode);

        return $code === self::FINAL_STAFF || $code === self::FINAL_CUSTOMER;
    }

    /**
     * Proven legacy createNewQuote defaults. Application may override with resolved context.
     *
     * @return array{
     *   documentType: array{value: int, classification: string, note: string},
     *   companyCode: array{value: int, classification: string, note: string},
     *   officeId: array{value: int, classification: string, note: string},
     *   metricType: array{value: int, classification: string, note: string},
     *   paymentTermsId: array{value: int, classification: string, note: string},
     *   totalCostValue: array{value: string, classification: string, note: string}
     * }
     */
    public function provenHeaderFieldClassifications(): array
    {
        return [
            'documentType' => [
                'value' => 1611,
                'classification' => 'PROVEN',
                'note' => 'Legacy createNewQuote hardcodes dt=1611',
            ],
            'companyCode' => [
                'value' => 2507,
                'classification' => 'DEFAULTED',
                'note' => 'Legacy hardcodes cod=2507 (COD); future service should resolve when proven',
            ],
            'officeId' => [
                'value' => 7,
                'classification' => 'DEFAULTED',
                'note' => 'Legacy hardcodes sa=7; future service should resolve staff office when proven',
            ],
            'metricType' => [
                'value' => 1301,
                'classification' => 'DEFAULTED',
                'note' => 'Legacy hardcodes mtt=1301 (metres)',
            ],
            'paymentTermsId' => [
                'value' => 3303,
                'classification' => 'DEFAULTED',
                'note' => 'Legacy hardcodes ptm=3303; customer-specific terms not yet resolved for write path',
            ],
            'totalCostValue' => [
                'value' => '0.00',
                'classification' => 'DEFAULTED',
                'note' => 'Legacy inserts tcval=0 on create',
            ],
        ];
    }

    /**
     * @throws InvalidArgumentException
     */
    public function requirePositiveInt(string $field, int $value): int
    {
        if ($value <= 0) {
            throw new InvalidArgumentException($field . ' must be positive.');
        }

        return $value;
    }
}
