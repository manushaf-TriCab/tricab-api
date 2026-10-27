<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Service;

use TriCab\Core\CustomerContactReport\Domain\Credit\CustomerContactReportCreditCalculator;
use TriCab\Core\CustomerContactReport\Domain\Credit\CustomerContactReportCreditFigures;
use TriCab\Core\CustomerContactReport\Domain\Credit\CustomerContactReportCreditType;
use TriCab\Core\CustomerContactReport\Domain\Credit\CustomerContactReportLine;
use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;

/**
 * Turns the credit-related fields of a request into what the server stores:
 * validated answers, lines rebuilt from 4JS, and server-calculated figures.
 * Shared by create and update so the two cannot drift.
 *
 * Warnings are not failures. A report with a 4JS outage still saves when the
 * raiser typed the amount; the response says the lines were not confirmed.
 */
final class CustomerContactReportCreditPlanner
{
    public const WARNING_FOURJS_UNAVAILABLE = 'fourjs_unavailable';

    public const WARNING_LINES_UNCONFIRMED = 'lines_unconfirmed';

    public const WARNING_CREDIT_CAPPED = 'credit_capped';

    public function __construct(
        private readonly CustomerContactReportLineResolver $lines,
    ) {
    }

    /**
     * @param list<array<string, mixed>>|null $wantedLines null keeps $existingLines
     * @param list<CustomerContactReportLine> $existingLines
     * @return array{
     *     creditRequired: string,
     *     creditType: ?string,
     *     restockingFeeApplies: ?string,
     *     addGst: ?string,
     *     figures: ?CustomerContactReportCreditFigures,
     *     lines: list<CustomerContactReportLine>,
     *     linesReplaced: bool,
     *     warnings: list<string>
     * }
     */
    public function plan(
        string $location,
        string $creditRequiredRaw,
        ?string $creditTypeRaw,
        ?string $restockingRaw,
        ?string $addGstRaw,
        ?string $typedValueExGst,
        ?array $wantedLines,
        array $existingLines,
    ): array {
        $creditRequired = self::yesNo($creditRequiredRaw, 'creditRequired');
        $warnings = [];
        $lines = $existingLines;
        $replaced = false;
        if ($wantedLines !== null) {
            $resolved = $this->lines->resolve($wantedLines);
            $lines = $resolved['lines'];
            $replaced = true;
            if ($resolved['unavailable']) {
                $warnings[] = self::WARNING_FOURJS_UNAVAILABLE;
            } elseif ($resolved['unconfirmed'] > 0) {
                $warnings[] = self::WARNING_LINES_UNCONFIRMED;
            }
            foreach ($lines as $line) {
                if ($line->capped) {
                    $warnings[] = self::WARNING_CREDIT_CAPPED;
                    break;
                }
            }
        }

        if ($creditRequired === 'No') {
            return [
                'creditRequired' => 'No',
                'creditType' => null,
                'restockingFeeApplies' => null,
                'addGst' => null,
                'figures' => null,
                'lines' => $lines,
                'linesReplaced' => $replaced,
                'warnings' => $warnings,
            ];
        }

        if ($creditTypeRaw === null || trim($creditTypeRaw) === '') {
            throw InvalidCustomerContactReportValue::for('creditType', 'is required when a credit is required.');
        }
        $creditType = CustomerContactReportCreditType::fromString($creditTypeRaw);
        $restocking = $creditType === CustomerContactReportCreditType::RETURNED_GOODS
            ? self::yesNo($restockingRaw ?? 'No', 'restockingFeeApplies')
            : 'No';
        $addGst = $creditType === CustomerContactReportCreditType::OVERCHARGED
            ? self::yesNo($addGstRaw ?? 'Yes', 'addGst')
            : null;

        $figures = CustomerContactReportCreditCalculator::calculate(
            $creditType,
            $lines,
            $typedValueExGst,
            $restocking === 'Yes',
            $addGst !== 'No',
            $location,
        );

        return [
            'creditRequired' => 'Yes',
            'creditType' => $creditType,
            'restockingFeeApplies' => $restocking,
            'addGst' => $addGst,
            'figures' => $figures,
            'lines' => $lines,
            'linesReplaced' => $replaced,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    public static function yesNo(string $raw, string $field): string
    {
        return match (strtolower(trim($raw))) {
            'yes' => 'Yes',
            'no' => 'No',
            default => throw InvalidCustomerContactReportValue::for($field, 'must be Yes or No.'),
        };
    }
}
