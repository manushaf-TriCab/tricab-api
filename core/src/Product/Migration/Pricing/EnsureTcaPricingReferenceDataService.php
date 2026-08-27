<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Pricing;

/**
 * Idempotent TCA reference-data plan (no writes). Caller applies CREATED actions.
 */
final class EnsureTcaPricingReferenceDataService
{
    public const CREATED = 'CREATED';
    public const NO_CHANGE = 'NO_CHANGE';
    public const CONFLICT = 'REFERENCE_CONFLICT';
    public const BLOCKED = 'AUD_REFERENCE_INVALID';

    /**
     * @param array{
     *   externalSystemPresent: bool,
     *   audCurrencyPresent: bool,
     *   audCurrencyActive: bool,
     *   tcaBase: null|array{currencyCode: string, isActive: bool, deleted: bool, dbcoMapped: ?string, priceListPublicId?: string}
     * } $state
     * @return array{status: string, actions: list<string>, reasons: list<string>}
     */
    public function plan(array $state): array
    {
        $actions = [];
        $reasons = [];

        if (!($state['audCurrencyPresent'] ?? false) || !($state['audCurrencyActive'] ?? false)) {
            return [
                'status' => self::BLOCKED,
                'actions' => [],
                'reasons' => ['AUD_REFERENCE_INVALID'],
            ];
        }

        if (!($state['externalSystemPresent'] ?? false)) {
            $actions[] = 'CREATE_EXTERNAL_SYSTEM_legacy-tc';
        }

        $tca = $state['tcaBase'] ?? null;
        if ($tca === null) {
            $actions[] = 'CREATE_PRICELIST_TCA-BASE';
            $actions[] = 'CREATE_MAPPING_dbco_2507';
        } else {
            if (($tca['deleted'] ?? false) === true || ($tca['isActive'] ?? false) !== true) {
                return [
                    'status' => self::CONFLICT,
                    'actions' => [],
                    'reasons' => ['TCA-BASE inactive or deleted'],
                ];
            }
            if (strtoupper((string) ($tca['currencyCode'] ?? '')) !== 'AUD') {
                return [
                    'status' => self::CONFLICT,
                    'actions' => [],
                    'reasons' => ['TCA-BASE currency is not AUD'],
                ];
            }
            $mapped = $tca['dbcoMapped'] ?? null;
            if ($mapped === null || $mapped === '') {
                $actions[] = 'CREATE_MAPPING_dbco_2507';
            } elseif ((string) $mapped !== '2507') {
                return [
                    'status' => self::CONFLICT,
                    'actions' => [],
                    'reasons' => ['TCA-BASE mapped to unexpected dbco ' . $mapped],
                ];
            }
        }

        if ($actions === []) {
            return ['status' => self::NO_CHANGE, 'actions' => [], 'reasons' => []];
        }

        return ['status' => self::CREATED, 'actions' => $actions, 'reasons' => $reasons];
    }
}
