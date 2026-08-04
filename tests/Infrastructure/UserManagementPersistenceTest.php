<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Identity\Persistence\AzureSqlPermissionManagementRepository;
use TriCab\Api\Identity\Persistence\AzureSqlRoleManagementRepository;
use TriCab\Api\Identity\Persistence\AzureSqlUserManagementAuditRepository;
use TriCab\Api\Identity\Persistence\AzureSqlUserManagementRepository;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserListCriteria;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserManagementAuditCriteria;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserManagementAuditWrite;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

final class UserManagementPersistenceTest extends TestCase
{
    private const SAMPLE_PUBLIC_ID = '550e8400-e29b-41d4-a716-446655440000';

    // ------------------------------------------------------------------
    // AzureSqlUserManagementRepository
    // ------------------------------------------------------------------

    public function test_list_users_builds_parameterized_sql_with_whitelisted_sort_column(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['Total' => 1]]);
        $connection->queueFetch([$this->userSummaryRow()]);
        $connection->queueFetch([$this->summaryCountsRow()]);

        $repository = new AzureSqlUserManagementRepository($connection);

        $criteria = new UserListCriteria(
            page: 2,
            pageSize: 10,
            search: "o'brien",
            status: 'active',
            roleCode: 'employee',
            providerCode: 'microsoft-entra',
            sortBy: UserListCriteria::SORT_EMAIL,
            sortDirection: UserListCriteria::SORT_ASC,
        );

        $result = $repository->listUsers($criteria);

        self::assertSame(1, $result->pagination->totalItems);
        self::assertCount(1, $result->items);

        $listSql = $connection->calls[1]['sql'];
        $listParams = $connection->calls[1]['params'];

        self::assertStringContainsString('OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY', $listSql);
        self::assertStringContainsString('ORDER BY u.[NormalizedEmailAddress] ASC', $listSql);
        self::assertStringNotContainsString("o'brien", $listSql);
        self::assertSame("%o'brien%", $listParams[':searchEmail']);
        self::assertSame("%o'brien%", $listParams[':searchName']);
        self::assertSame("%o'brien%", $listParams[':searchPublicId']);
        self::assertSame('active', $listParams[':status']);
        self::assertSame('EMPLOYEE', $listParams[':roleCode']);
        self::assertSame(10, $listParams[':pageSize']);
        self::assertSame(10, $listParams[':offset']);
    }

    public function test_list_users_parameterizes_provider_filter_with_both_naming_variants(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['Total' => 0]]);
        $connection->queueFetch([]);
        $connection->queueFetch([$this->summaryCountsRow()]);

        $repository = new AzureSqlUserManagementRepository($connection);

        $repository->listUsers(new UserListCriteria(providerCode: 'microsoft-entra'));

        $listParams = $connection->calls[1]['params'];

        self::assertSame('microsoft_entra', $listParams[':provider']);
        self::assertSame('microsoft-entra', $listParams[':providerAlt']);
    }

    public function test_create_user_runs_in_transaction_and_never_touches_external_identities(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['UserStatusId' => 1]]);
        $connection->queueFetch([['UserId' => 42]]);
        $connection->queueFetch([['RoleId' => 7]]);
        $connection->queueFetch([$this->userDetailsMainRow()]);
        $connection->queueFetch([]);
        $connection->queueFetch([]);
        $connection->queueFetch([]);

        $repository = new AzureSqlUserManagementRepository($connection);

        $details = $repository->createUser(
            emailAddress: EmailAddress::fromString('new.user@tricab.com'),
            displayName: 'New User',
            status: UserStatus::active(),
            roleCodes: [RoleCode::fromString('EMPLOYEE')],
            actorUserId: UserId::fromInt(1),
        );

        self::assertSame(self::SAMPLE_PUBLIC_ID, $details->publicId);
        self::assertTrue($connection->beganTransaction);
        self::assertTrue($connection->committed);
        self::assertFalse($connection->rolledBack);

        foreach ($connection->calls as $call) {
            $normalized = strtoupper(ltrim($call['sql']));
            $isWrite = str_starts_with($normalized, 'INSERT') || str_starts_with($normalized, 'UPDATE');
            if ($isWrite) {
                self::assertStringNotContainsString('ExternalIdentities', $call['sql']);
            }
        }

        $insertSql = $connection->calls[1]['sql'];
        self::assertStringContainsString('INSERT INTO [identity].[Users]', $insertSql);
        self::assertStringNotContainsString("'new.user@tricab.com'", $insertSql);
    }

    public function test_create_user_rolls_back_when_write_fails(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['UserStatusId' => 1]]);
        $connection->failNextWrite = new RuntimeException('insert exploded');

        $repository = new AzureSqlUserManagementRepository($connection);

        $this->expectException(RuntimeException::class);

        try {
            $repository->createUser(
                emailAddress: EmailAddress::fromString('new.user@tricab.com'),
                displayName: 'New User',
                status: UserStatus::active(),
                roleCodes: [],
                actorUserId: UserId::fromInt(1),
            );
        } finally {
            self::assertTrue($connection->beganTransaction);
            self::assertTrue($connection->rolledBack);
            self::assertFalse($connection->committed);
        }
    }

    public function test_assign_role_inserts_new_assignment_with_actor_and_expiry(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['UserId' => 42]]);
        $connection->queueFetch([['RoleId' => 7]]);
        $connection->queueFetch([]);

        $repository = new AzureSqlUserManagementRepository($connection);
        $expiresAt = new \DateTimeImmutable('2030-01-01T00:00:00+00:00');

        $repository->assignRole(
            UserPublicId::fromString(self::SAMPLE_PUBLIC_ID),
            RoleCode::fromString('EMPLOYEE'),
            $expiresAt,
            UserId::fromInt(1),
        );

        $insertCall = $connection->calls[3];
        self::assertStringContainsString('INSERT INTO [identity].[UserRoles]', $insertCall['sql']);
        self::assertSame(1, $insertCall['params'][':assignedByUserId']);

        $expiresAtParam = $insertCall['params'][':expiresAt'];
        self::assertIsString($expiresAtParam);
        self::assertStringContainsString('2030-01-01', $expiresAtParam);
    }

    public function test_remove_role_soft_deletes_and_returns_false_when_no_row_matches(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['UserId' => 42]]);
        $connection->queueFetch([['RoleId' => 7]]);
        $connection->queueFetch([]);

        $repository = new AzureSqlUserManagementRepository($connection);

        $removed = $repository->removeRole(
            UserPublicId::fromString(self::SAMPLE_PUBLIC_ID),
            RoleCode::fromString('EMPLOYEE'),
        );

        self::assertFalse($removed);

        $updateSql = $connection->calls[2]['sql'];
        self::assertStringContainsString('SET [IsActive] = 0', $updateSql);
    }

    public function test_remove_role_returns_true_and_touches_user_when_row_removed(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['UserId' => 42]]);
        $connection->queueFetch([['RoleId' => 7]]);
        $connection->queueFetch([['UserRoleId' => 99]]);

        $repository = new AzureSqlUserManagementRepository($connection);

        $removed = $repository->removeRole(
            UserPublicId::fromString(self::SAMPLE_PUBLIC_ID),
            RoleCode::fromString('EMPLOYEE'),
        );

        self::assertTrue($removed);
        self::assertStringContainsString('UPDATE [identity].[Users]', $connection->calls[3]['sql']);
    }

    public function test_change_status_updates_status_and_returns_details(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['UserStatusId' => 3]]);
        $connection->queueFetch([['UserId' => 42]]);
        $connection->queueFetch([$this->userDetailsMainRow()]);
        $connection->queueFetch([]);
        $connection->queueFetch([]);
        $connection->queueFetch([]);

        $repository = new AzureSqlUserManagementRepository($connection);

        $details = $repository->changeStatus(
            UserPublicId::fromString(self::SAMPLE_PUBLIC_ID),
            UserStatus::disabled(),
            UserId::fromInt(1),
        );

        self::assertSame(self::SAMPLE_PUBLIC_ID, $details->publicId);
        self::assertTrue($connection->committed);
    }

    // ------------------------------------------------------------------
    // AzureSqlRoleManagementRepository
    // ------------------------------------------------------------------

    public function test_list_roles_filters_by_search_and_active_flag_with_bound_params(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([$this->roleSummaryRow()]);

        $repository = new AzureSqlRoleManagementRepository($connection);

        $roles = $repository->listRoles(search: 'emp', isActive: true);

        self::assertCount(1, $roles);
        $call = $connection->calls[0];
        self::assertStringContainsString('r.[RoleCode] LIKE :search', $call['sql']);
        self::assertStringContainsString('r.[IsActive] = :isActive', $call['sql']);
        self::assertSame('%emp%', $call['params'][':search']);
        self::assertSame(1, $call['params'][':isActive']);
    }

    public function test_create_role_generates_public_id_and_persists_within_transaction(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['RoleId' => 5]]);
        $connection->queueFetch([$this->roleDetailsMainRow()]);
        $connection->queueFetch([]);
        $connection->queueFetch([]);

        $repository = new AzureSqlRoleManagementRepository($connection);

        $details = $repository->createRole(
            RoleCode::fromString('SUPPORT'),
            'Support',
            'Support role',
            true,
            UserId::fromInt(1),
        );

        self::assertSame('SUPPORT', $details->roleCode);
        self::assertTrue($connection->committed);
        self::assertStringContainsString('INSERT INTO [identity].[Roles]', $connection->calls[0]['sql']);
    }

    public function test_assign_permission_matches_role_and_permission_codes_with_upper(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['RoleId' => 5]]);
        $connection->queueFetch([['PermissionId' => 9]]);
        $connection->queueFetch([]);

        $repository = new AzureSqlRoleManagementRepository($connection);

        $repository->assignPermission(
            RoleCode::fromString('support'),
            PermissionCode::fromString('account.login'),
            UserId::fromInt(1),
        );

        self::assertStringContainsString('UPPER([RoleCode]) = :roleCode', $connection->calls[0]['sql']);
        self::assertSame('SUPPORT', $connection->calls[0]['params'][':roleCode']);
        self::assertStringContainsString('UPPER([PermissionCode]) = :permissionCode', $connection->calls[1]['sql']);
        self::assertSame('ACCOUNT.LOGIN', $connection->calls[1]['params'][':permissionCode']);

        $insertCall = $connection->calls[3];
        self::assertStringContainsString('INSERT INTO [identity].[RolePermissions]', $insertCall['sql']);
    }

    public function test_remove_permission_returns_false_when_not_assigned(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['RoleId' => 5]]);
        $connection->queueFetch([['PermissionId' => 9]]);
        $connection->queueFetch([]);

        $repository = new AzureSqlRoleManagementRepository($connection);

        $removed = $repository->removePermission(
            RoleCode::fromString('SUPPORT'),
            PermissionCode::fromString('ACCOUNT.LOGIN'),
        );

        self::assertFalse($removed);
    }

    // ------------------------------------------------------------------
    // AzureSqlPermissionManagementRepository
    // ------------------------------------------------------------------

    public function test_create_permission_persists_module_code_and_generates_public_id(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['PermissionId' => 11]]);
        $connection->queueFetch([$this->permissionSummaryRow()]);

        $repository = new AzureSqlPermissionManagementRepository($connection);

        $summary = $repository->createPermission(
            PermissionCode::fromString('REPORTS.VIEW'),
            'View Reports',
            'Allows viewing reports',
            'REPORTS',
            true,
            UserId::fromInt(1),
        );

        self::assertSame('REPORTS.VIEW', $summary->permissionCode);
        self::assertTrue($connection->committed);

        $insertCall = $connection->calls[0];
        self::assertStringContainsString('INSERT INTO [identity].[Permissions]', $insertCall['sql']);
        self::assertSame('REPORTS.VIEW', $insertCall['params'][':permissionCode']);
        self::assertSame('REPORTS', $insertCall['params'][':moduleCode']);
    }

    public function test_update_permission_bumps_updated_at_and_supports_partial_active_toggle(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['PermissionId' => 11]]);
        $connection->queueFetch([$this->permissionSummaryRow()]);

        $repository = new AzureSqlPermissionManagementRepository($connection);

        $repository->updatePermission(
            PermissionCode::fromString('REPORTS.VIEW'),
            'View Reports v2',
            null,
            false,
            UserId::fromInt(1),
        );

        $updateCall = $connection->calls[0];
        self::assertStringContainsString('[UpdatedAt] = SYSDATETIMEOFFSET()', $updateCall['sql']);
        self::assertStringContainsString('[IsActive] = :isActive', $updateCall['sql']);
        self::assertSame(0, $updateCall['params'][':isActive']);
    }

    // ------------------------------------------------------------------
    // AzureSqlUserManagementAuditRepository
    // ------------------------------------------------------------------

    public function test_audit_append_json_encodes_previous_and_new_values_verbatim(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([[
            'ActorPublicId' => self::SAMPLE_PUBLIC_ID,
            'TargetUserPublicId' => self::SAMPLE_PUBLIC_ID,
            'TargetRoleCode' => 'EMPLOYEE',
            'TargetPermissionCode' => null,
        ]]);
        $connection->queueFetch([[
            'PublicId' => '660e8400-e29b-41d4-a716-446655440111',
            'CreatedAtUtc' => '2026-08-03 00:00:00.0000000',
        ]]);

        $repository = new AzureSqlUserManagementAuditRepository($connection);

        $entry = $repository->append(new UserManagementAuditWrite(
            eventType: 'ROLE_ASSIGNED',
            eventSummary: 'Assigned EMPLOYEE role',
            actorUserId: 1,
            targetUserId: 42,
            targetRoleId: 7,
            targetPermissionId: null,
            previousValues: ['roles' => ['GUEST']],
            newValues: ['roles' => ['GUEST', 'EMPLOYEE']],
            correlationId: 'corr-123',
            ipAddress: '10.0.0.1',
            userAgent: 'PHPUnit',
        ));

        self::assertSame('EMPLOYEE', $entry->targetRoleCode);
        self::assertSame(['roles' => ['GUEST']], $entry->previousValues);
        self::assertSame(['roles' => ['GUEST', 'EMPLOYEE']], $entry->newValues);

        $insertCall = $connection->calls[1];
        self::assertStringContainsString('INSERT INTO [audit].[UserManagementEvents]', $insertCall['sql']);
        self::assertSame(json_encode(['roles' => ['GUEST']]), $insertCall['params'][':previousValuesJson']);
        self::assertSame(json_encode(['roles' => ['GUEST', 'EMPLOYEE']]), $insertCall['params'][':newValuesJson']);

        foreach ($connection->calls as $call) {
            self::assertStringNotContainsString('TokenHash', $call['sql']);
            foreach ($call['params'] as $paramValue) {
                if (is_string($paramValue)) {
                    self::assertStringNotContainsString('password', strtolower($paramValue));
                }
            }
        }
    }

    public function test_audit_list_paginates_and_filters_by_target_user_and_event_type(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['Total' => 1]]);
        $connection->queueFetch([$this->auditEventRow()]);

        $repository = new AzureSqlUserManagementAuditRepository($connection);

        $criteria = new UserManagementAuditCriteria(
            page: 1,
            pageSize: 20,
            targetUserPublicId: self::SAMPLE_PUBLIC_ID,
            eventType: 'role_assigned',
        );

        $result = $repository->list($criteria);

        self::assertCount(1, $result->items);
        self::assertSame(1, $result->pagination->totalItems);

        $listCall = $connection->calls[1];
        self::assertStringContainsString('OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY', $listCall['sql']);
        self::assertSame(self::SAMPLE_PUBLIC_ID, $listCall['params'][':targetUserPublicId']);
        self::assertSame('ROLE_ASSIGNED', $listCall['params'][':eventType']);
    }

    public function test_audit_list_recent_for_user_limits_and_orders_descending(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([$this->auditEventRow()]);

        $repository = new AzureSqlUserManagementAuditRepository($connection);

        $entries = $repository->listRecentForUser(self::SAMPLE_PUBLIC_ID, 5);

        self::assertCount(1, $entries);
        $call = $connection->calls[0];
        self::assertStringContainsString('ORDER BY e.[CreatedAtUtc] DESC', $call['sql']);
        self::assertSame(5, $call['params'][':limit']);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function userSummaryRow(): array
    {
        return [
            'PublicId' => self::SAMPLE_PUBLIC_ID,
            'EmailAddress' => 'thilanh@tricab.com',
            'DisplayName' => null,
            'StatusCode' => 'active',
            'CreatedAt' => '2026-01-01 00:00:00.0000000',
            'UpdatedAt' => '2026-01-02 00:00:00.0000000',
            'LastSuccessfulLoginAt' => null,
            'RoleCodes' => 'EMPLOYEE',
            'ProviderCodes' => 'microsoft_entra',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function userDetailsMainRow(): array
    {
        return [
            'UserId' => 42,
            'PublicId' => self::SAMPLE_PUBLIC_ID,
            'EmailAddress' => 'new.user@tricab.com',
            'DisplayName' => 'New User',
            'StatusCode' => 'active',
            'CreatedAt' => '2026-01-01 00:00:00.0000000',
            'UpdatedAt' => '2026-01-02 00:00:00.0000000',
            'LastSuccessfulLoginAt' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryCountsRow(): array
    {
        return [
            'TotalUsers' => 10,
            'ActiveUsers' => 8,
            'SuspendedUsers' => 2,
            'GuestUsers' => 1,
            'MicrosoftUsers' => 5,
            'Administrators' => 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function roleSummaryRow(): array
    {
        return [
            'PublicId' => self::SAMPLE_PUBLIC_ID,
            'RoleCode' => 'EMPLOYEE',
            'RoleName' => 'Employee',
            'Description' => null,
            'IsSystemRole' => 1,
            'IsActive' => 1,
            'CreatedAt' => '2026-01-01 00:00:00.0000000',
            'UpdatedAt' => '2026-01-02 00:00:00.0000000',
            'UserCount' => 3,
            'PermissionCount' => 2,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function roleDetailsMainRow(): array
    {
        return [
            'RoleId' => 5,
            'PublicId' => self::SAMPLE_PUBLIC_ID,
            'RoleCode' => 'SUPPORT',
            'RoleName' => 'Support',
            'Description' => 'Support role',
            'IsSystemRole' => 0,
            'IsActive' => 1,
            'CreatedAt' => '2026-01-01 00:00:00.0000000',
            'UpdatedAt' => '2026-01-02 00:00:00.0000000',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function permissionSummaryRow(): array
    {
        return [
            'PublicId' => self::SAMPLE_PUBLIC_ID,
            'PermissionCode' => 'REPORTS.VIEW',
            'PermissionName' => 'View Reports',
            'Description' => 'Allows viewing reports',
            'ModuleCode' => 'REPORTS',
            'IsActive' => 1,
            'CreatedAt' => '2026-01-01 00:00:00.0000000',
            'UpdatedAt' => '2026-01-02 00:00:00.0000000',
            'RoleCodes' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditEventRow(): array
    {
        return [
            'PublicId' => '660e8400-e29b-41d4-a716-446655440111',
            'EventType' => 'ROLE_ASSIGNED',
            'EventSummary' => 'Assigned EMPLOYEE role',
            'ActorPublicId' => self::SAMPLE_PUBLIC_ID,
            'TargetUserPublicId' => self::SAMPLE_PUBLIC_ID,
            'TargetRoleCode' => 'EMPLOYEE',
            'TargetPermissionCode' => null,
            'PreviousValuesJson' => json_encode(['roles' => ['GUEST']]),
            'NewValuesJson' => json_encode(['roles' => ['GUEST', 'EMPLOYEE']]),
            'CreatedAtUtc' => '2026-08-03 00:00:00.0000000',
            'RequestCorrelationId' => 'corr-123',
        ];
    }
}
