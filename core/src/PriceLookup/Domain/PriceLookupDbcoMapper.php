<?php

declare(strict_types=1);

namespace TriCab\Core\PriceLookup\Domain;

use TriCab\Core\PriceLookup\Application\Exception\PriceLookupValidationException;
use TriCab\Core\PriceLookup\Module;

/**
 * Server-only company → DBCO mapping for Price Lookup.
 * Distinct from ResolvePricingDbcoService (which does not enable TCASIA/2519).
 */
final class PriceLookupDbcoMapper
{
    public const DBCO_TCA = '2507';
    public const DBCO_TCUS = '2517';
    public const DBCO_TCASIA = '2519';

    public function toDbco(string $company): string
    {
        $code = strtoupper(trim($company));

        return match ($code) {
            'TCA' => self::DBCO_TCA,
            'TCUS' => self::DBCO_TCUS,
            'TCASIA' => self::DBCO_TCASIA,
            default => throw PriceLookupValidationException::invalidCompany($company),
        };
    }

    public function toCompany(string $dbco): string
    {
        return match (trim($dbco)) {
            self::DBCO_TCA => 'TCA',
            self::DBCO_TCUS => 'TCUS',
            self::DBCO_TCASIA => 'TCASIA',
            default => throw PriceLookupValidationException::invalidCompany($dbco),
        };
    }

    public function assertKnownCompany(string $company): string
    {
        $code = strtoupper(trim($company));
        if (!in_array($code, Module::COMPANIES, true)) {
            throw PriceLookupValidationException::invalidCompany($company);
        }

        return $code;
    }
}
