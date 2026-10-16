<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use TriCab\Core\Identity\Application\DTO\UserManagement\EffectivePermissionGrant;
use TriCab\Core\Identity\Application\DTO\UserManagement\IdentityProviderSummary;
use TriCab\Core\Identity\Application\DTO\UserManagement\Pagination;
use TriCab\Core\Identity\Application\DTO\UserManagement\PermissionSummary;
use TriCab\Core\Identity\Application\DTO\UserManagement\RoleDetails;
use TriCab\Core\Identity\Application\DTO\UserManagement\RolePermissionAssignment;
use TriCab\Core\Identity\Application\DTO\UserManagement\RoleSummary;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserDetails;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserManagementAuditEntry;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserManagementSummaryCounts;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserRoleAssignment;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserSummary;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserCustomerAssociation;

/**
 * Serializes Core user-management DTOs to admin API response arrays.
 * Never exposes numeric userId — publicId is the stable admin-facing identifier.
 */
final class UserManagementDtoSerializer
{
    /**
     * @return array<string, mixed>
     */
    public static function userSummary(UserSummary $summary): array
    {
        return [
            'publicId' => $summary->publicId,
            'emailAddress' => $summary->emailAddress,
            'displayName' => $summary->displayName,
            'status' => $summary->status,
            'roles' => $summary->roles,
            'providers' => $summary->providers,
            'createdAtUtc' => $summary->createdAtUtc,
            'updatedAtUtc' => $summary->updatedAtUtc,
            'lastLoginAtUtc' => $summary->lastLoginAtUtc,
            'userType' => $summary->userType,
            'customerPublicId' => $summary->customerPublicId,
            'customerName' => $summary->customerName,
            'contactPublicId' => $summary->contactPublicId,
            'contactDisplayName' => $summary->contactDisplayName,
            'portalAccessStatus' => $summary->portalAccessStatus,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function userDetails(UserDetails $details): array
    {
        return [
            'publicId' => $details->publicId,
            'emailAddress' => $details->emailAddress,
            'displayName' => $details->displayName,
            'status' => $details->status,
            'roles' => array_map(self::userRoleAssignment(...), $details->roles),
            'effectivePermissions' => array_map(self::effectivePermissionGrant(...), $details->effectivePermissions),
            'identityProviders' => array_map(self::identityProviderSummary(...), $details->identityProviders),
            'createdAtUtc' => $details->createdAtUtc,
            'updatedAtUtc' => $details->updatedAtUtc,
            'lastLoginAtUtc' => $details->lastLoginAtUtc,
            'recentAuditEvents' => array_map(self::auditEntry(...), $details->recentAuditEvents),
            'userType' => $details->userType,
            'customerAssociations' => array_map(self::customerAssociation(...), $details->customerAssociations),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function customerAssociation(UserCustomerAssociation $association): array
    {
        return [
            'customerPublicId' => $association->customerPublicId,
            'customerName' => $association->customerName,
            'contactPublicId' => $association->contactPublicId,
            'contactDisplayName' => $association->contactDisplayName,
            'portalAccessStatus' => $association->portalAccessStatus,
            'isPrimary' => $association->isPrimary,
            'isActive' => $association->isActive,
            'linkedAtUtc' => $association->linkedAtUtc,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function roleSummary(RoleSummary $summary): array
    {
        return [
            'publicId' => $summary->publicId,
            'roleCode' => $summary->roleCode,
            'roleName' => $summary->roleName,
            'description' => $summary->description,
            'isSystemRole' => $summary->isSystemRole,
            'isActive' => $summary->isActive,
            'userCount' => $summary->userCount,
            'permissionCount' => $summary->permissionCount,
            'createdAtUtc' => $summary->createdAtUtc,
            'updatedAtUtc' => $summary->updatedAtUtc,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function roleDetails(RoleDetails $details): array
    {
        return [
            'publicId' => $details->publicId,
            'roleCode' => $details->roleCode,
            'roleName' => $details->roleName,
            'description' => $details->description,
            'isSystemRole' => $details->isSystemRole,
            'isActive' => $details->isActive,
            'permissions' => array_map(self::rolePermissionAssignment(...), $details->permissions),
            'assignedUsers' => array_map(self::userSummary(...), $details->assignedUsers),
            'createdAtUtc' => $details->createdAtUtc,
            'updatedAtUtc' => $details->updatedAtUtc,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function permissionSummary(PermissionSummary $summary): array
    {
        return [
            'publicId' => $summary->publicId,
            'permissionCode' => $summary->permissionCode,
            'permissionName' => $summary->permissionName,
            'description' => $summary->description,
            'moduleCode' => $summary->moduleCode,
            'isActive' => $summary->isActive,
            'roleCodes' => $summary->roleCodes,
            'createdAtUtc' => $summary->createdAtUtc,
            'updatedAtUtc' => $summary->updatedAtUtc,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function pagination(Pagination $pagination): array
    {
        return [
            'page' => $pagination->page,
            'pageSize' => $pagination->pageSize,
            'totalItems' => $pagination->totalItems,
            'totalPages' => $pagination->totalPages,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function counts(UserManagementSummaryCounts $counts): array
    {
        return [
            'totalUsers' => $counts->totalUsers,
            'activeUsers' => $counts->activeUsers,
            'suspendedUsers' => $counts->suspendedUsers,
            'guestUsers' => $counts->guestUsers,
            'microsoftUsers' => $counts->microsoftUsers,
            'administrators' => $counts->administrators,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function auditEntry(UserManagementAuditEntry $entry): array
    {
        return [
            'publicId' => $entry->publicId,
            'eventType' => $entry->eventType,
            'eventSummary' => $entry->eventSummary,
            'actorPublicId' => $entry->actorPublicId,
            'actorDisplayName' => $entry->actorDisplayName,
            'actorEmailAddress' => $entry->actorEmailAddress,
            'targetUserPublicId' => $entry->targetUserPublicId,
            'targetUserDisplayName' => $entry->targetUserDisplayName,
            'targetUserEmailAddress' => $entry->targetUserEmailAddress,
            'targetRoleCode' => $entry->targetRoleCode,
            'targetPermissionCode' => $entry->targetPermissionCode,
            'previousValues' => $entry->previousValues,
            'newValues' => $entry->newValues,
            'createdAtUtc' => $entry->createdAtUtc,
            'correlationId' => $entry->correlationId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function userRoleAssignment(UserRoleAssignment $assignment): array
    {
        return [
            'roleCode' => $assignment->roleCode,
            'roleName' => $assignment->roleName,
            'isActive' => $assignment->isActive,
            'assignedAtUtc' => $assignment->assignedAtUtc,
            'expiresAtUtc' => $assignment->expiresAtUtc,
            'isExpired' => $assignment->isExpired,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function effectivePermissionGrant(EffectivePermissionGrant $grant): array
    {
        return [
            'permissionCode' => $grant->permissionCode,
            'permissionName' => $grant->permissionName,
            'grantedByRoleCodes' => $grant->grantedByRoleCodes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function identityProviderSummary(IdentityProviderSummary $summary): array
    {
        return [
            'providerCode' => $summary->providerCode,
            'isLinked' => $summary->isLinked,
            'isActive' => $summary->isActive,
            'lastAuthenticatedAtUtc' => $summary->lastAuthenticatedAtUtc,
            'maskedSubject' => $summary->maskedSubject,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function rolePermissionAssignment(RolePermissionAssignment $assignment): array
    {
        return [
            'permissionCode' => $assignment->permissionCode,
            'permissionName' => $assignment->permissionName,
            'moduleCode' => $assignment->moduleCode,
            'isActive' => $assignment->isActive,
            'assignedAtUtc' => $assignment->assignedAtUtc,
        ];
    }
}
