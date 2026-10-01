<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CustomerContactReport;

use PHPUnit\Framework\TestCase;
use TriCab\Core\CustomerContactReport\Module;

final class CustomerContactReportSeedScriptTest extends TestCase
{
    public function test_the_seed_creates_exactly_the_phase_permissions(): void
    {
        preg_match_all("/\\(N'(CUSTOMER_CONTACT\\.[A-Z_]+)',/m", $this->script(), $matches);
        $seeded = $matches[1];
        sort($seeded);
        $expected = Module::PERMISSIONS;
        sort($expected);

        self::assertSame($expected, $seeded);
    }

    public function test_platform_roles_receive_nothing_and_dedicated_roles_match_the_module(): void
    {
        preg_match_all("/\\(N'([A-Z_]+)', N'(CUSTOMER_CONTACT\\.[A-Z_]+)'\\)/", $this->script(), $permissionMappings, PREG_SET_ORDER);
        self::assertSame([], $permissionMappings);
        self::assertStringNotContainsString('INSERT INTO [identity].[RolePermissions]', $this->script());
        self::assertStringNotContainsString('INSERT INTO [identity].[UserRoles]', $this->script());
        self::assertStringNotContainsString('INSERT INTO [identity].[UserRoles]', $this->rolesScript());

        preg_match_all("/\\(N'([A-Z_]+)', N'(CUSTOMER_CONTACT\\.[A-Z_]+)'\\)/", $this->rolesScript(), $matches, PREG_SET_ORDER);
        $mapped = [];
        foreach ($matches as $match) {
            $mapped[$match[1]][] = $match[2];
        }
        foreach ($mapped as $role => $permissions) {
            sort($permissions);
            $mapped[$role] = $permissions;
        }
        $expected = [];
        foreach (Module::ROLE_PERMISSIONS as $role => $permissions) {
            $codes = $permissions;
            sort($codes);
            $expected[$role] = $codes;
        }
        ksort($mapped);
        ksort($expected);

        self::assertSame($expected, $mapped);
        self::assertNotContains(Module::PERMISSION_APPROVE, $mapped[Module::ROLE_COORDINATOR]);
        self::assertNotContains(Module::PERMISSION_REMEDY, $mapped[Module::ROLE_APPROVER]);
        self::assertNotContains(Module::PERMISSION_READ_ALL, $mapped[Module::ROLE_USER]);
        self::assertStringNotContainsString('thilanh@tricab.com', $this->rolesScript());
        self::assertStringNotContainsString('ayanj@tricab.com', $this->phpSources());

        $rebuild = $this->rebuild();
        $permissionsAt = strpos($rebuild, "'seed-customer-contact-report-permissions.sql'");
        $rolesAt = strpos($rebuild, "'seed-customer-contact-roles.sql'");
        self::assertIsInt($permissionsAt);
        self::assertIsInt($rolesAt);
        self::assertLessThan($rolesAt, $permissionsAt);
        self::assertStringContainsString('CUSTOMER_CONTACT_USER', $this->rollback());
        self::assertStringContainsString('CUSTOMER_CONTACT_COORDINATOR', $this->rollback());
        self::assertStringContainsString('CUSTOMER_CONTACT_APPROVER', $this->rollback());
        self::assertDoesNotMatchRegularExpression('/\\b(INSERT|UPDATE|DELETE|MERGE)\\b[^;]*\\[cc_report\\]/i', $this->rollback());
        self::assertDoesNotMatchRegularExpression('/\\b(INSERT|UPDATE|DELETE|MERGE)\\b[^;]*\\[cc_report\\]/i', $this->script());
        self::assertDoesNotMatchRegularExpression('/\\b(INSERT|UPDATE|DELETE|MERGE)\\b[^;]*\\[cc_report\\]/i', $this->rolesScript());
        self::assertStringNotContainsString("N'CUSTOMER.CONTACT.", $this->rollback());
    }

    public function test_the_safety_check_matches_only_the_report_permission_namespace(): void
    {
        $sql = $this->script();
        self::assertStringContainsString(
            "LEFT(UPPER(p.[PermissionCode]), LEN(N'CUSTOMER_CONTACT.')) = N'CUSTOMER_CONTACT.'",
            $sql,
        );
        self::assertStringNotContainsString("LIKE N'CUSTOMER_CONTACT.%'", $sql);

        self::assertTrue(self::isReportPermission('CUSTOMER_CONTACT.ACCESS'));
        self::assertTrue(self::isReportPermission('customer_contact.read_all'));
        self::assertFalse(self::isReportPermission('CUSTOMER.CONTACT.READ'));
        self::assertFalse(self::isReportPermission('CUSTOMERXCONTACT.READ'));
        self::assertFalse(self::isReportPermission('RFQ.ACCESS'));
        self::assertFalse(self::isReportPermission('QUOTE.READ'));
        self::assertFalse(self::isReportPermission('CUSTOMER_CONTACT'));

        self::assertTrue(self::unescapedUnderscoreLike('CUSTOMER.CONTACT.READ', 'CUSTOMER_CONTACT.%'));
        self::assertTrue(self::unescapedUnderscoreLike('CUSTOMERXCONTACT.READ', 'CUSTOMER_CONTACT.%'));
        self::assertFalse(self::isReportPermission('CUSTOMER.CONTACT.READ'));

        $personContactAlreadyGranted = [
            ['role' => 'ADMINISTRATOR', 'code' => 'CUSTOMER.CONTACT.CREATE'],
            ['role' => 'ADMINISTRATOR', 'code' => 'CUSTOMER.CONTACT.READ'],
            ['role' => 'ADMINISTRATOR', 'code' => 'CUSTOMER.CONTACT.STATUS.UPDATE'],
            ['role' => 'ADMINISTRATOR', 'code' => 'CUSTOMER.CONTACT.UPDATE'],
            ['role' => 'EMPLOYEE', 'code' => 'CUSTOMER.CONTACT.READ'],
        ];
        self::assertFalse(self::safetyCheckFails($personContactAlreadyGranted));
        self::assertTrue(self::safetyCheckFails([
            ['role' => 'EMPLOYEE', 'code' => 'CUSTOMER_CONTACT.READ_ALL'],
        ]));
        self::assertTrue(self::safetyCheckFails([
            ['role' => 'ADMINISTRATOR', 'code' => 'CUSTOMER_CONTACT.ACCESS'],
        ]));
        self::assertTrue(self::safetyCheckFails([
            ['role' => 'EMPLOYEE', 'code' => 'CUSTOMER_CONTACT.ACCESS'],
        ]));
        self::assertTrue(self::safetyCheckFails([
            ['role' => 'GUEST', 'code' => 'CUSTOMER_CONTACT.READ'],
        ]));
        self::assertFalse(self::safetyCheckFails([
            ['role' => 'CUSTOMER_CONTACT_USER', 'code' => 'CUSTOMER_CONTACT.ACCESS'],
            ['role' => 'CUSTOMER_CONTACT_APPROVER', 'code' => 'CUSTOMER_CONTACT.APPROVE'],
        ]));
    }

    /**
     * Same rule as the seed: the prefix length is the length of the literal,
     * and the following characters are not part of the comparison.
     */
    private static function isReportPermission(string $code): bool
    {
        $prefix = 'CUSTOMER_CONTACT.';

        return strtoupper(substr($code, 0, strlen($prefix))) === $prefix;
    }

    /**
     * SQL Server LIKE with no ESCAPE clause: _ is one character and % is the rest.
     * Bracket escapes are not applied. This is the behaviour the seed must not use.
     */
    private static function unescapedUnderscoreLike(string $value, string $pattern): bool
    {
        $regex = '';
        $length = strlen($pattern);
        for ($index = 0; $index < $length; $index++) {
            $character = $pattern[$index];
            if ($character === '%') {
                $regex .= '.*';
            } elseif ($character === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($character, '/');
            }
        }

        return preg_match('/^' . $regex . '$/i', $value) === 1;
    }

    /**
     * @param list<array{role: string, code: string}> $activeMappings
     */
    private static function safetyCheckFails(array $activeMappings): bool
    {
        foreach ($activeMappings as $mapping) {
            if (!self::isReportPermission($mapping['code'])) {
                continue;
            }
            $role = strtoupper($mapping['role']);
            if (in_array($role, ['EMPLOYEE', 'ADMINISTRATOR', 'GUEST'], true)) {
                return true;
            }
        }

        return false;
    }

    public function test_a_second_authorization_seed_inserts_nothing_and_leaves_platform_roles(): void
    {
        $fixture = new CustomerContactAuthorizationSeedFixture();
        $fixture->grant('EMPLOYEE', 'CUSTOMER.CONTACT.READ');
        $fixture->grant('ADMINISTRATOR', 'CUSTOMER.CONTACT.CREATE');
        $fixture->grant('GUEST', 'ACCOUNT.LOGIN');

        $first = $fixture->apply();
        self::assertSame(11, $first['permissions']);
        self::assertSame(3, $first['roles']);
        self::assertSame(15, $first['grants']);
        self::assertSame(0, $first['userRoles']);
        self::assertTrue($fixture->grantIsActive('EMPLOYEE', 'CUSTOMER.CONTACT.READ'));
        self::assertTrue($fixture->grantIsActive('ADMINISTRATOR', 'CUSTOMER.CONTACT.CREATE'));
        self::assertFalse($fixture->hasReportGrant('EMPLOYEE'));
        self::assertFalse($fixture->hasReportGrant('ADMINISTRATOR'));
        self::assertFalse($fixture->hasReportGrant('GUEST'));

        $second = $fixture->apply();
        self::assertSame(0, $second['permissions']);
        self::assertSame(0, $second['roles']);
        self::assertSame(0, $second['grants']);
        self::assertSame(0, $second['userRoles']);
        self::assertSame(15, $fixture->activeReportGrants());

        $fixture->deactivate('CUSTOMER_CONTACT_USER', 'CUSTOMER_CONTACT.CREATE');
        $fixture->grant('CUSTOMER_CONTACT_COORDINATOR', 'CUSTOMER_CONTACT.APPROVE');
        $third = $fixture->apply();
        self::assertSame(0, $third['permissions']);
        self::assertSame(1, $third['reactivated']);
        self::assertTrue($fixture->grantIsActive('CUSTOMER_CONTACT_USER', 'CUSTOMER_CONTACT.CREATE'));
        self::assertFalse($fixture->grantIsActive('CUSTOMER_CONTACT_COORDINATOR', 'CUSTOMER_CONTACT.APPROVE'));
        self::assertSame(15, $fixture->activeReportGrants());

        $blocked = new CustomerContactAuthorizationSeedFixture();
        $blocked->grant('ADMINISTRATOR', 'CUSTOMER_CONTACT.ACCESS');
        try {
            $blocked->apply();
            self::fail('A platform report grant was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertSame('54012', $exception->getMessage());
        }
        self::assertTrue($blocked->grantIsActive('ADMINISTRATOR', 'CUSTOMER_CONTACT.ACCESS'));
        self::assertSame(1, $blocked->activeReportGrants());
        self::assertSame(0, $blocked->roleCount());
    }

    public function test_the_azure_allocator_locks_the_year_and_does_not_use_max_plus_one(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/CustomerContactReport/Persistence/AzureSqlCustomerContactReportRepository.php');
        self::assertIsString($source);
        self::assertStringContainsString('WITH (UPDLOCK, HOLDLOCK)', $source);
        self::assertStringNotContainsString('MAX(', $source);
        self::assertStringContainsString('runInTransaction', $source);
        self::assertStringContainsString('a.[RequestedBy]', $source);
        self::assertStringContainsString('CustomerContactReportApprovalSeparation::samePerson', $source);
        self::assertStringContainsString('selfApproval()', $source);
    }

    private function script(): string
    {
        return $this->sqlFile('seed-customer-contact-report-permissions.sql');
    }

    private function rolesScript(): string
    {
        return $this->sqlFile('seed-customer-contact-roles.sql');
    }

    private function rollback(): string
    {
        return $this->sqlFile('rollback-customer-contact-authorization.sql');
    }

    private function sqlFile(string $name): string
    {
        $sql = file_get_contents(dirname(__DIR__, 2) . '/database/scripts/development/' . $name);
        self::assertIsString($sql);

        return $sql;
    }

    private function rebuild(): string
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/database/scripts/development/rebuild-local-db.ps1');
        self::assertIsString($script);

        return $script;
    }

    private function phpSources(): string
    {
        $roots = [
            dirname(__DIR__, 2) . '/core/src/CustomerContactReport',
            dirname(__DIR__, 2) . '/src/CustomerContactReport',
            dirname(__DIR__, 2) . '/src/Http/Action/CustomerContactReport',
        ];
        $combined = '';
        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $contents = file_get_contents($file->getPathname());
                    self::assertIsString($contents);
                    $combined .= $contents;
                }
            }
        }

        return $combined;
    }
}

/**
 * In-memory model of the two authorization scripts. It is not a database.
 */
final class CustomerContactAuthorizationSeedFixture
{
    /** @var array<string, true> */
    private array $permissions = [];

    /** @var array<string, true> */
    private array $roles = [];

    /** @var array<string, bool> */
    private array $grants = [];

    public function grant(string $role, string $permission): void
    {
        $this->grants[$this->key($role, $permission)] = true;
    }

    public function deactivate(string $role, string $permission): void
    {
        $this->grants[$this->key($role, $permission)] = false;
    }

    public function grantIsActive(string $role, string $permission): bool
    {
        return ($this->grants[$this->key($role, $permission)] ?? false) === true;
    }

    public function hasReportGrant(string $role): bool
    {
        foreach ($this->grants as $key => $active) {
            if (!$active) {
                continue;
            }
            [$grantRole, $permission] = explode('|', $key, 2);
            if ($grantRole === strtoupper($role) && $this->isReportPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function activeReportGrants(): int
    {
        $count = 0;
        foreach ($this->grants as $key => $active) {
            if (!$active) {
                continue;
            }
            [, $permission] = explode('|', $key, 2);
            if ($this->isReportPermission($permission)) {
                $count++;
            }
        }

        return $count;
    }

    public function roleCount(): int
    {
        return count($this->roles);
    }

    /**
     * @return array{permissions: int, roles: int, grants: int, userRoles: int, reactivated: int}
     */
    public function apply(): array
    {
        $permissions = $this->permissions;
        $roles = $this->roles;
        $grants = $this->grants;
        try {
            return $this->applyInTransaction();
        } catch (\RuntimeException $exception) {
            $this->permissions = $permissions;
            $this->roles = $roles;
            $this->grants = $grants;
            throw $exception;
        }
    }

    /**
     * @return array{permissions: int, roles: int, grants: int, userRoles: int, reactivated: int}
     */
    private function applyInTransaction(): array
    {
        $insertedPermissions = 0;
        foreach (Module::PERMISSIONS as $permission) {
            if (!isset($this->permissions[$permission])) {
                $this->permissions[$permission] = true;
                $insertedPermissions++;
            }
        }

        $insertedRoles = 0;
        foreach (array_keys(Module::ROLE_PERMISSIONS) as $role) {
            if (!isset($this->roles[$role])) {
                $this->roles[$role] = true;
                $insertedRoles++;
            }
        }

        $insertedGrants = 0;
        $reactivated = 0;
        foreach (Module::ROLE_PERMISSIONS as $role => $permissions) {
            foreach ($permissions as $permission) {
                $key = $this->key($role, $permission);
                if (!array_key_exists($key, $this->grants)) {
                    $this->grants[$key] = true;
                    $insertedGrants++;
                } elseif ($this->grants[$key] === false) {
                    $this->grants[$key] = true;
                    $reactivated++;
                }
            }
        }

        foreach (array_keys($this->grants) as $key) {
            [$role, $permission] = explode('|', $key, 2);
            if (!isset($this->roles[$role]) || !$this->isReportPermission($permission)) {
                continue;
            }
            $expected = Module::ROLE_PERMISSIONS[$role] ?? [];
            if (!in_array($permission, $expected, true)) {
                $this->grants[$key] = false;
            }
        }

        foreach (['EMPLOYEE', 'ADMINISTRATOR', 'GUEST'] as $role) {
            if ($this->hasReportGrant($role)) {
                throw new \RuntimeException('54012');
            }
        }

        $granted = 0;
        foreach (Module::ROLE_PERMISSIONS as $role => $permissions) {
            foreach ($permissions as $permission) {
                if ($this->grantIsActive($role, $permission)) {
                    $granted++;
                }
            }
        }
        if ($granted !== 15) {
            throw new \RuntimeException('54013');
        }

        return [
            'permissions' => $insertedPermissions,
            'roles' => $insertedRoles,
            'grants' => $insertedGrants,
            'userRoles' => 0,
            'reactivated' => $reactivated,
        ];
    }

    private function key(string $role, string $permission): string
    {
        return strtoupper($role) . '|' . strtoupper($permission);
    }

    private function isReportPermission(string $permission): bool
    {
        return str_starts_with($permission, 'CUSTOMER_CONTACT.');
    }
}
