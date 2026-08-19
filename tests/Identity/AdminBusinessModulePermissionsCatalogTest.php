<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Identity;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Identity\Persistence\DevelopmentUserManagementCatalog;
use TriCab\Api\Identity\Persistence\InMemoryUserManagementStore;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;

final class AdminBusinessModulePermissionsCatalogTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function expectedBusinessPermissionCodes(): array
    {
        return [
            'PRICE_LOOKUP.READ',
            'QUOTE.READ',
            'QUOTE.CREATE',
            'QUOTE.UPDATE',
            'QUOTE.DELETE',
            'CUSTOMER_NOTE.READ',
            'CUSTOMER_NOTE.CREATE',
            'CUSTOMER_NOTE.UPDATE',
            'CUSTOMER_NOTE.DELETE',
            'ALIFLEX_ACCREDITATION.ACCESS',
            'TRIEXPENSE.ACCESS',
            'CRM.ACCESS',
            'ERP.ACCESS',
            'REGION.READ',
            'REGION.CREATE',
            'REGION.UPDATE',
            'REGION.DELETE',
            'MIGRATION.ADMIN',
        ];
    }

    public function test_catalog_registers_admin_business_module_permissions(): void
    {
        $codes = DevelopmentUserManagementCatalog::managementPermissionCodes();

        foreach ($this->expectedBusinessPermissionCodes() as $code) {
            self::assertContains($code, $codes);
        }

        // Existing modules must still be present.
        self::assertContains('PRODUCT.READ', $codes);
        self::assertContains('USER.READ', $codes);
        self::assertContains('ROLE.READ', $codes);
        self::assertContains('CUSTOMER.READ', $codes);
    }

    public function test_administrator_receives_business_module_permissions_in_memory_seed(): void
    {
        $store = new InMemoryUserManagementStore();
        $store->seedDevelopmentUserManagement();

        $administrator = $store->findRoleByCode(RoleCode::fromString('ADMINISTRATOR'));
        self::assertNotNull($administrator);

        $assigned = array_map(
            static fn ($permission): string => strtoupper((string) $permission->permissionCode),
            $administrator->permissions,
        );

        foreach ($this->expectedBusinessPermissionCodes() as $code) {
            self::assertContains($code, $assigned, sprintf('ADMINISTRATOR missing %s', $code));
        }
    }
}
