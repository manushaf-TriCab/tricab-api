<?php

declare(strict_types=1);

namespace TriCab\Core\Analytics\Domain;

/**
 * The reporting categories for module activity, derived from the web admin navigation (ADMIN_NAV) and the
 * mobile app's routes. Clients map their own routes and screens onto these codes; the server accepts only
 * these and files anything else under "other", so a client cannot invent a module.
 *
 * Deliberately absent: Marketing and Production, which are not modules in any TriCab application, and
 * CRM/ERP, which are external links whose use happens outside TriCab.
 */
final class ActivityModuleCatalog
{
    public const OTHER = 'other';

    /** @var array<string, string> code => label, in display order */
    private const MODULES = [
        'administration' => 'Administration',
        'finance' => 'Finance',
        'products' => 'Products & Catalogue',
        'technical-tools' => 'Technical Tools',
        'quotes-orders' => 'Quotes & Orders',
        'rfq' => 'RFQ / Quotegen',
        'inbox' => 'Inbox',
        'customers' => 'Customer Management',
        'hr' => 'HR',
        'tsd' => 'TSD',
        self::OTHER => 'Other',
    ];

    private const CODE_PATTERN = '/^[a-z][a-z0-9-]{0,39}$/';

    public static function isWellFormed(string $code): bool
    {
        return preg_match(self::CODE_PATTERN, $code) === 1;
    }

    /** A well-formed code the server does not know is reported as "other" rather than rejected, so older clients keep counting. */
    public static function normalize(string $code): string
    {
        return array_key_exists($code, self::MODULES) ? $code : self::OTHER;
    }

    public static function label(string $code): string
    {
        return self::MODULES[$code] ?? self::MODULES[self::OTHER];
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::MODULES);
    }
}
