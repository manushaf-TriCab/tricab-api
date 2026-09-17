<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Domain\Policy;

/**
 * Proven legacy createNewQuote header defaults.
 * Application supplies these until customer/office-specific resolution is proven.
 */
final class QuoteHeaderDefaults
{
    /** @see QuoteManagement::createNewQuote dt=1611 */
    public const DOCUMENT_TYPE = 1611;

    /** Legacy comment: COD / AU company code — hardcoded, not region-switched in create path */
    public const COMPANY_CODE = 2507;

    /** Legacy comment: SA office — hardcoded */
    public const OFFICE_ID = 7;

    /** Metres metric */
    public const METRIC_TYPE = 1301;

    /** Payment terms id — UI may show tcus.ptm but insert always used 3303 */
    public const PAYMENT_TERMS_ID = 3303;

    public const TOTAL_COST_VALUE = '0.00';

    /**
     * @return list<array{field: string, legacyMeaning: string, legacyCreateValue: string|int, source: string, newSource: string, required: bool, safeDefault: bool, status: string}>
     */
    public static function catalogue(): array
    {
        return [
            [
                'field' => 'cod',
                'legacyMeaning' => 'Company / DBCO-style company code on quote header',
                'legacyCreateValue' => self::COMPANY_CODE,
                'source' => 'QuoteManagement::createNewQuote hardcoded $company=2507',
                'newSource' => 'QuoteHeaderDefaults::COMPANY_CODE until region-aware resolution proven',
                'required' => true,
                'safeDefault' => true,
                'status' => 'DEFAULTED_PROVEN',
            ],
            [
                'field' => 'sa',
                'legacyMeaning' => 'Sales office id',
                'legacyCreateValue' => self::OFFICE_ID,
                'source' => 'Hardcoded $office=7 (SA)',
                'newSource' => 'QuoteHeaderDefaults::OFFICE_ID until staff office resolution proven',
                'required' => true,
                'safeDefault' => true,
                'status' => 'DEFAULTED_PROVEN',
            ],
            [
                'field' => 'mtt',
                'legacyMeaning' => 'Metric type (metres)',
                'legacyCreateValue' => self::METRIC_TYPE,
                'source' => 'Hardcoded $metrics=1301',
                'newSource' => 'QuoteHeaderDefaults::METRIC_TYPE',
                'required' => true,
                'safeDefault' => true,
                'status' => 'DEFAULTED_PROVEN',
            ],
            [
                'field' => 'ptm',
                'legacyMeaning' => 'Payment terms id',
                'legacyCreateValue' => self::PAYMENT_TERMS_ID,
                'source' => 'Hardcoded $paymentMethod=3303 (UI shows customer ptm but does not write it)',
                'newSource' => 'QuoteHeaderDefaults::PAYMENT_TERMS_ID until customer ptm mapping proven',
                'required' => true,
                'safeDefault' => true,
                'status' => 'DEFAULTED_PROVEN',
            ],
            [
                'field' => 'tcval',
                'legacyMeaning' => 'Total cost value on header',
                'legacyCreateValue' => self::TOTAL_COST_VALUE,
                'source' => 'Inserted as 0; never updated after create in QuoteManagement',
                'newSource' => 'Persist as 0.00 on draft create (legacy parity)',
                'required' => true,
                'safeDefault' => true,
                'status' => 'DEFAULTED_PROVEN',
            ],
        ];
    }
}
