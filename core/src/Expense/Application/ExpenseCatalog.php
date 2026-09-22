<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application;

use TriCab\Core\Expense\Application\DTO\ExpenseFeatureFlags;
use TriCab\Core\Expense\Module;

/**
 * Client-safe TriExpense catalogue. Keep Web/Mobile from duplicating GL codes.
 */
final class ExpenseCatalog
{
    /** @return array<string, string> */
    public static function categories(): array
    {
        return [
            '53110' => 'Freight In - Air',
            '53120' => 'Freight Inbound',
            '53130' => 'Customs & Duty',
            '61100' => 'Freight - Branches',
            '61200' => 'Freight - Customers',
            '61300' => 'Project Freight',
            '62050' => 'Warehouse Charges',
            '62100' => 'Packing Expenses',
            '62150' => 'Safety Supplies',
            '62160' => 'Materials & Supplies',
            '62200' => 'Hire Charges',
            '62250' => 'Quality Control & Testing',
            '62310' => 'R & M - General',
            '62320' => 'R & M - Equipment',
            '62330' => 'R & M - Electrical',
            '62340' => 'Maintenance Agreements',
            '62350' => 'Waste Disposal',
            '63510' => 'Telephone',
            '63520' => 'Electricity',
            '63530' => 'Gas',
            '63540' => 'Rates & Taxes',
            '63550' => 'Water',
            '64100' => 'Petrol & Oil',
            '64150' => 'KM Reimbursement',
            '64300' => 'Motor Vehicle - R & M',
            '64400' => 'Motor Vehicle - Other',
            '65120' => 'Cleaning',
            '65200' => 'Computer & Information',
            '65240' => 'Computer Licences',
            '65300' => 'Fees & Permits',
            '65320' => 'Office Expenses',
            '65325' => 'Postage',
            '65330' => 'Printing & Stationery',
            '65350' => 'Subscriptions',
            '65410' => 'Security',
            '65500' => 'Seminars & Training',
            '65550' => 'Staff Amenities',
            '65600' => 'Uniforms',
            '65700' => 'Recruiting Expense',
            '65800' => 'Employment Expenses',
            '66100' => 'Advertising',
            '66200' => 'Promotional & Marketing',
            '66550' => 'Travel - Airfares',
            '66560' => 'Travel - Accommodation & other costs',
            '66700' => 'Meal Entertainment',
            '66750' => 'Other Entertainment',
            '67175' => 'Accountancy Fees',
            '67177' => 'Certifications & Approvals',
            '67180' => 'Consulting Fees',
            '67185' => 'Legal & Professional Services',
            '68100' => 'Research & Development',
            '80120' => 'Bank Charges',
            '89999' => 'Reallocation of Cost',
            '91200' => 'Interest Expense',
        ];
    }

    /** @return list<string> */
    public static function currencies(): array
    {
        return ['AUD', 'USD', 'EUR', 'GBP', 'CAD', 'NZD', 'SGD', 'HKD', 'AED', 'SAR', 'QAR', 'KWD', 'BHD', 'INR', 'CNY', 'JPY', 'THB', 'MYR', 'PGK', 'Other'];
    }

    /**
     * @param ?ExpenseFeatureFlags $features runtime capability snapshot; when
     *        omitted the integration-dependent features report off, which is the
     *        correct answer for any caller that has no wiring to consult.
     * @param list<array{code: string, name: string}> $costCentres
     *        Active cost-centre labels from ExpenseCostCentreOptionsPort.
     *        `code` is the value persisted on receipts (legacy label string).
     * @return array<string, mixed>
     */
    public static function clientConfig(
        ?ExpenseFeatureFlags $features = null,
        array $costCentres = [],
        bool $costCentresAvailable = true,
    ): array {
        $categories = [];
        foreach (self::categories() as $code => $name) {
            $categories[] = ['code' => $code, 'name' => $name];
        }

        return [
            'categories' => $categories,
            'costCentres' => array_values($costCentres),
            'costCentresAvailable' => $costCentresAvailable,
            'currencies' => self::currencies(),
            'fileExtensions' => Module::ALLOWED_EXTENSIONS,
            'maxUploadBytes' => Module::MAX_UPLOAD_BYTES,
            'gstModes' => [Module::GST_INCLUSIVE, Module::GST_EXCLUSIVE],
            'recycleDays' => Module::RECYCLE_DAYS,
            'ocrMaxBytes' => Module::OCR_MAX_BYTES,
            'features' => ($features ?? ExpenseFeatureFlags::disabledIntegrations())->toArray(),
        ];
    }
}
