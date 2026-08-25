<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

/**
 * Shared constants for the development/test smoke user and the 16 admin
 * user-management permission codes, kept in sync between
 * InMemoryApiUserRepository (auth) and InMemoryUserManagementStore (admin CRUD)
 * so /me and /api/v1/admin/* agree on the same seeded identity in memory mode.
 */
final class DevelopmentUserManagementCatalog
{
    public const USER_PUBLIC_ID = '550e8400-e29b-41d4-a716-446655440000';
    public const USER_EMAIL = 'thilanh@tricab.com';
    public const USER_DISPLAY_NAME = 'Thilan Lakshitha';
    public const USER_SUBJECT = 'development-thilan';

    /**
     * @return array<string, array{name: string, module: string}>
     */
    public static function managementPermissions(): array
    {
        return [
            'USER.READ' => ['name' => 'View Users', 'module' => 'USER'],
            'USER.CREATE' => ['name' => 'Create Users', 'module' => 'USER'],
            'USER.UPDATE' => ['name' => 'Update Users', 'module' => 'USER'],
            'USER.STATUS.UPDATE' => ['name' => 'Change User Status', 'module' => 'USER'],
            'USER.ROLE.ASSIGN' => ['name' => 'Assign User Roles', 'module' => 'USER'],
            'USER.ROLE.REMOVE' => ['name' => 'Remove User Roles', 'module' => 'USER'],
            'ROLE.READ' => ['name' => 'View Roles', 'module' => 'ROLE'],
            'ROLE.CREATE' => ['name' => 'Create Roles', 'module' => 'ROLE'],
            'ROLE.UPDATE' => ['name' => 'Update Roles', 'module' => 'ROLE'],
            'ROLE.STATUS.UPDATE' => ['name' => 'Change Role Status', 'module' => 'ROLE'],
            'ROLE.PERMISSION.ASSIGN' => ['name' => 'Assign Role Permissions', 'module' => 'ROLE'],
            'ROLE.PERMISSION.REMOVE' => ['name' => 'Remove Role Permissions', 'module' => 'ROLE'],
            'PERMISSION.READ' => ['name' => 'View Permissions', 'module' => 'PERMISSION'],
            'PERMISSION.CREATE' => ['name' => 'Create Permissions', 'module' => 'PERMISSION'],
            'PERMISSION.UPDATE' => ['name' => 'Update Permissions', 'module' => 'PERMISSION'],
            'AUDIT.USER_MANAGEMENT.READ' => ['name' => 'View User Management Audit Log', 'module' => 'AUDIT'],
            'CUSTOMER.READ' => ['name' => 'View Customers', 'module' => 'CUSTOMER'],
            'CUSTOMER.UPDATE' => ['name' => 'Create/Update Customers', 'module' => 'CUSTOMER'],
            'CUSTOMER.CONTACT.READ' => ['name' => 'View Customer Contacts', 'module' => 'CUSTOMER'],
            'CUSTOMER.CONTACT.CREATE' => ['name' => 'Create Customer Contacts', 'module' => 'CUSTOMER'],
            'CUSTOMER.CONTACT.UPDATE' => ['name' => 'Update Customer Contacts', 'module' => 'CUSTOMER'],
            'CUSTOMER.CONTACT.STATUS.UPDATE' => ['name' => 'Change Customer Contact Status', 'module' => 'CUSTOMER'],
            'CUSTOMER.USER.INVITE' => ['name' => 'Invite Customer Portal Users', 'module' => 'CUSTOMER'],
            'CUSTOMER.USER.LINK' => ['name' => 'Link Customer Contact Users', 'module' => 'CUSTOMER'],
            'CUSTOMER.USER.UNLINK' => ['name' => 'Unlink Customer Contact Users', 'module' => 'CUSTOMER'],
            'CUSTOMER.USER.READ' => ['name' => 'View Customer Portal Users', 'module' => 'CUSTOMER'],
            'CUSTOMER.PORTAL_ACCESS.MANAGE' => ['name' => 'Manage Customer Portal Access', 'module' => 'CUSTOMER'],
            'PRODUCT.READ' => ['name' => 'View Products', 'module' => 'PRODUCT'],
            'PRODUCT.CREATE' => ['name' => 'Create Products', 'module' => 'PRODUCT'],
            'PRODUCT.UPDATE' => ['name' => 'Update Products', 'module' => 'PRODUCT'],
            'PRODUCT.PUBLISH' => ['name' => 'Publish Products', 'module' => 'PRODUCT'],
            'PRODUCT.ARCHIVE' => ['name' => 'Archive Products', 'module' => 'PRODUCT'],
            'PRODUCT.RESTORE' => ['name' => 'Restore Products', 'module' => 'PRODUCT'],
            'PRODUCT.VARIANTS.MANAGE' => ['name' => 'Manage Product Variants', 'module' => 'PRODUCT'],
            'PRODUCT.SPECIFICATIONS.MANAGE' => ['name' => 'Manage Product Specifications', 'module' => 'PRODUCT'],
            'PRODUCT.MEDIA.MANAGE' => ['name' => 'Manage Product Media', 'module' => 'PRODUCT'],
            'PRODUCT.DOCUMENTS.MANAGE' => ['name' => 'Manage Product Documents', 'module' => 'PRODUCT'],
            'PRODUCT.RELATIONSHIPS.MANAGE' => ['name' => 'Manage Product Relationships', 'module' => 'PRODUCT'],
            'PRODUCT.CODES.MANAGE' => ['name' => 'Manage Product Codes', 'module' => 'PRODUCT'],
            'PRODUCT.PRICING.VIEW' => ['name' => 'View Product Pricing', 'module' => 'PRODUCT'],
            'PRODUCT.PRICING.MANAGE' => ['name' => 'Manage Product Pricing', 'module' => 'PRODUCT'],
            'PRODUCT.LOOKUPS.MANAGE' => ['name' => 'Manage Product Lookups', 'module' => 'PRODUCT'],
            'PRODUCT.STYLES.READ' => ['name' => 'View Product Styles', 'module' => 'PRODUCT'],
            'PRODUCT.STYLES.MANAGE' => ['name' => 'Manage Product Styles', 'module' => 'PRODUCT'],
            'PRODUCT.STYLES.INHERITANCE.MANAGE' => ['name' => 'Manage Product Style Inheritance', 'module' => 'PRODUCT'],
            'PRODUCT.MIGRATION.MANAGE' => ['name' => 'Manage Product Migration', 'module' => 'PRODUCT'],
            'MIGRATION.ADMIN' => ['name' => 'Administer Legacy Migration', 'module' => 'MIGRATION'],
            'PRODUCT.AUDIT.VIEW' => ['name' => 'View Product Audit History', 'module' => 'PRODUCT'],
            'REGION.READ' => ['name' => 'View Regions', 'module' => 'REGION'],
            'REGION.CREATE' => ['name' => 'Create Regions', 'module' => 'REGION'],
            'REGION.UPDATE' => ['name' => 'Update Regions', 'module' => 'REGION'],
            'REGION.DELETE' => ['name' => 'Delete Regions', 'module' => 'REGION'],
            'QUOTE.READ' => ['name' => 'View Quotes', 'module' => 'QUOTE'],
            'QUOTE.CREATE' => ['name' => 'Create Quotes', 'module' => 'QUOTE'],
            'QUOTE.UPDATE' => ['name' => 'Update Quotes', 'module' => 'QUOTE'],
            'QUOTE.DELETE' => ['name' => 'Delete Quotes', 'module' => 'QUOTE'],
            'ORDER.READ' => ['name' => 'View Orders', 'module' => 'ORDER'],
            'DOCUMENT.READ' => ['name' => 'View Documents', 'module' => 'DOCUMENT'],
            'PRICE_LOOKUP.READ' => ['name' => 'Access Price Lookup', 'module' => 'PRICE_LOOKUP'],
            'CUSTOMER_NOTE.READ' => ['name' => 'View Customer Notes', 'module' => 'CUSTOMER_NOTE'],
            'CUSTOMER_NOTE.CREATE' => ['name' => 'Create Customer Notes', 'module' => 'CUSTOMER_NOTE'],
            'CUSTOMER_NOTE.UPDATE' => ['name' => 'Update Customer Notes', 'module' => 'CUSTOMER_NOTE'],
            'CUSTOMER_NOTE.DELETE' => ['name' => 'Delete Customer Notes', 'module' => 'CUSTOMER_NOTE'],
            'ALIFLEX_ACCREDITATION.ACCESS' => ['name' => 'Access ALI-FLEX Accreditation', 'module' => 'ALIFLEX'],
            'TRIEXPENSE.ACCESS' => ['name' => 'Access Tri Expense', 'module' => 'TRIEXPENSE'],
            'CRM.ACCESS' => ['name' => 'Access CRM', 'module' => 'CRM'],
            'ERP.ACCESS' => ['name' => 'Access ERP', 'module' => 'ERP'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function managementPermissionCodes(): array
    {
        return array_keys(self::managementPermissions());
    }
}
