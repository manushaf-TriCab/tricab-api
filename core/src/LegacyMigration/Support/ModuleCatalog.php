<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Support;

/**
 * Registered migration modules. Execution order is dependency order.
 * Product catalogue migration is intentionally excluded (existing Product Migration).
 */
final class ModuleCatalog
{
    public const USERS = 'users';
    public const EMPLOYEES = 'employees';
    public const CUSTOMERS = 'customers';
    public const CONTACTS = 'contacts';
    public const ROLES = 'roles';
    public const PERMISSIONS = 'permissions';
    public const ALIFLEX = 'aliflex';
    public const CERTIFICATES = 'certificates';
    public const RFQS = 'rfqs';
    public const QUOTES = 'quotes';
    public const CUSTOMER_NOTES = 'customer-notes';

    /**
     * @return list<array{code: string, label: string, sourceSystem: string, sortOrder: int, implemented: bool}>
     */
    public static function modules(): array
    {
        return [
            ['code' => self::USERS, 'label' => 'Users', 'sourceSystem' => SourceCatalog::WORDPRESS, 'sortOrder' => 10, 'implemented' => false],
            ['code' => self::EMPLOYEES, 'label' => 'Employees', 'sourceSystem' => SourceCatalog::BUSINESS_DB, 'sortOrder' => 20, 'implemented' => false],
            ['code' => self::CUSTOMERS, 'label' => 'Customers', 'sourceSystem' => SourceCatalog::BUSINESS_DB, 'sortOrder' => 30, 'implemented' => false],
            ['code' => self::CONTACTS, 'label' => 'Contacts', 'sourceSystem' => SourceCatalog::BUSINESS_DB, 'sortOrder' => 40, 'implemented' => false],
            ['code' => self::ROLES, 'label' => 'Roles', 'sourceSystem' => SourceCatalog::WORDPRESS, 'sortOrder' => 50, 'implemented' => false],
            ['code' => self::PERMISSIONS, 'label' => 'Permissions', 'sourceSystem' => SourceCatalog::WORDPRESS, 'sortOrder' => 60, 'implemented' => false],
            ['code' => self::ALIFLEX, 'label' => 'ALI-FLEX', 'sourceSystem' => SourceCatalog::MOODLE, 'sortOrder' => 70, 'implemented' => false],
            ['code' => self::CERTIFICATES, 'label' => 'Certificates', 'sourceSystem' => SourceCatalog::BUSINESS_DB, 'sortOrder' => 80, 'implemented' => false],
            ['code' => self::RFQS, 'label' => 'RFQs', 'sourceSystem' => SourceCatalog::BUSINESS_DB, 'sortOrder' => 90, 'implemented' => false],
            ['code' => self::QUOTES, 'label' => 'Quotes', 'sourceSystem' => SourceCatalog::BUSINESS_DB, 'sortOrder' => 100, 'implemented' => false],
            ['code' => self::CUSTOMER_NOTES, 'label' => 'Customer Notes', 'sourceSystem' => SourceCatalog::BUSINESS_DB, 'sortOrder' => 110, 'implemented' => false],
        ];
    }

    /** @return list<string> */
    public static function codesInDependencyOrder(): array
    {
        $modules = self::modules();
        usort($modules, static fn (array $a, array $b): int => $a['sortOrder'] <=> $b['sortOrder']);

        return array_map(static fn (array $m): string => $m['code'], $modules);
    }

    public static function rawTable(string $moduleCode): string
    {
        return match ($moduleCode) {
            self::USERS => 'LegacyUsersRaw',
            self::EMPLOYEES => 'LegacyEmployeesRaw',
            self::CUSTOMERS => 'LegacyCustomersRaw',
            self::CONTACTS => 'LegacyContactsRaw',
            self::ROLES => 'LegacyRolesRaw',
            self::PERMISSIONS => 'LegacyPermissionsRaw',
            self::ALIFLEX, self::CERTIFICATES => 'LegacyCertificatesRaw',
            self::RFQS => 'LegacyRfqsRaw',
            self::QUOTES => 'LegacyQuotesRaw',
            self::CUSTOMER_NOTES => 'LegacyCustomerNotesRaw',
            default => 'LegacyUsersRaw',
        };
    }
}
