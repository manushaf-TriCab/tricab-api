<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Application\ExpensePermissionMode;
use TriCab\Core\Expense\Contract\ExpenseFinanceCapabilityResolver;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * TriExpense authorization.
 *
 * Entry: authenticated + TRIEXPENSE.ACCESS + ExpenseProfile (operational metadata).
 * Platform ADMINISTRATOR alone is insufficient.
 *
 * Capability cutover ({@see ExpensePermissionMode}):
 *   legacy      → ExpenseProfile.IsFinance / IsExpenseAdmin
 *   permissions → TRIEXPENSE.FINANCE / ADMIN / APPROVE / AUDIT
 *   hybrid      → OR of both; mismatches logged (never silently deny in hybrid)
 *
 * Finance authorization (Phase 5): Graph Tricab-Finance remains diagnostic/sync
 * only. Profile IsFinance is the legacy source; TRIEXPENSE.FINANCE is the new one.
 *
 * Approval assignment is never “permission alone”:
 *   TRIEXPENSE.APPROVE + receipt.ManagerUserId == actor
 *   OR TRIEXPENSE.FINANCE (override, including self-approval)
 * In legacy/hybrid, IsExpenseAdmin may still approve others (not self).
 */
final class ExpenseAccessService
{
    private readonly ExpensePermissionMode $permissionMode;

    public function __construct(
        private readonly ExpenseProfileRepository $profiles,
        private readonly ExpenseFinanceCapabilityResolver $financeCapability,
        ?ExpensePermissionMode $permissionMode = null,
    ) {
        $this->permissionMode = $permissionMode ?? ExpensePermissionMode::hybrid();
    }

    public function permissionMode(): ExpensePermissionMode
    {
        return $this->permissionMode;
    }

    public function requireActiveProfile(ExpenseActor $actor): ExpenseProfile
    {
        if (!$actor->hasPermission(Module::PERMISSION_ACCESS)) {
            throw ExpenseAccessDenied::permissionRequired();
        }

        $profile = $this->profiles->findByUserId($actor->userId);
        if ($profile === null) {
            throw ExpenseAccessDenied::inactiveOrMissingProfile();
        }

        // permissions mode: ACCESS is authoritative for entry; IsActive is a
        // deprecated authorization field (profile row still required for manager metadata).
        // legacy + hybrid: preserve current behaviour — inactive profiles are denied.
        if (!$profile->isActive() && !$this->permissionMode->isPermissions()) {
            throw ExpenseAccessDenied::inactiveOrMissingProfile();
        }

        return $profile;
    }

    /**
     * TriExpense configuration admin (User Management ownership) — not Finance,
     * and not a global receipt approver in permissions mode.
     */
    public function isExpenseAdmin(ExpenseActor $actor, ExpenseProfile $profile): bool
    {
        $legacy = $profile->isExpenseAdmin();
        $permission = $actor->hasPermission(Module::PERMISSION_ADMIN);

        return $this->resolveCapability($actor->userId, 'admin', $legacy, $permission);
    }

    /**
     * @deprecated Use isExpenseAdmin(ExpenseActor, ExpenseProfile). Kept for
     *             transitional call sites that only have a profile in legacy tests.
     */
    public function isExpenseAdminFromProfile(ExpenseProfile $profile): bool
    {
        return $profile->isExpenseAdmin();
    }

    public function hasFinanceCapability(ExpenseActor $actor, ExpenseProfile $profile): bool
    {
        $legacy = $this->financeCapability->hasFinanceCapability($actor->userId, $profile);
        $permission = $actor->hasPermission(Module::PERMISSION_FINANCE);

        return $this->resolveCapability($actor->userId, 'finance', $legacy, $permission);
    }

    public function requireFinance(ExpenseActor $actor): ExpenseProfile
    {
        $profile = $this->requireActiveProfile($actor);
        if (!$this->hasFinanceCapability($actor, $profile)) {
            throw ExpenseAccessDenied::financeRequired();
        }

        return $profile;
    }

    public function hasApproveCapability(ExpenseActor $actor, ExpenseProfile $profile): bool
    {
        // Legacy had no APPROVE permission — any active profile could hit /approvals
        // (empty queue for non-managers). In permissions mode APPROVE is required.
        $legacy = $profile->isActive();
        $permission = $actor->hasPermission(Module::PERMISSION_APPROVE);

        if ($this->permissionMode->isLegacy()) {
            return $legacy;
        }
        if ($this->permissionMode->isPermissions()) {
            return $permission;
        }

        // hybrid: either is enough; log when they diverge
        if ($legacy !== $permission) {
            $this->logMismatch($actor->userId, 'approve', $legacy, $permission);
        }

        return $legacy || $permission;
    }

    public function requireApproveCapability(ExpenseActor $actor): ExpenseProfile
    {
        $profile = $this->requireActiveProfile($actor);
        if (!$this->hasApproveCapability($actor, $profile)) {
            throw ExpenseAccessDenied::approvalForbidden();
        }

        return $profile;
    }

    public function hasAuditCapability(ExpenseActor $actor, ExpenseProfile $profile): bool
    {
        // Legacy: finance could read audit. Prefer AUDIT permission via role composition.
        $legacy = $this->financeCapability->hasFinanceCapability($actor->userId, $profile)
            || $profile->isExpenseAdmin();
        $permission = $actor->hasPermission(Module::PERMISSION_AUDIT)
            || $actor->hasPermission(Module::PERMISSION_FINANCE);

        return $this->resolveCapability($actor->userId, 'audit', $legacy, $permission);
    }

    public function requireAudit(ExpenseActor $actor): ExpenseProfile
    {
        $profile = $this->requireActiveProfile($actor);
        if (!$this->hasAuditCapability($actor, $profile)) {
            throw ExpenseAccessDenied::financeRequired();
        }

        return $profile;
    }

    /**
     * Privileged receipt mutation (edit/attach others' receipts) — finance or
     * expense-admin (legacy) / ADMIN is NOT used for this in permissions mode;
     * finance only, matching "ADMIN must not approve unrelated receipts".
     */
    public function canMutateOthersReceipts(ExpenseActor $actor, ExpenseProfile $profile): bool
    {
        if ($this->hasFinanceCapability($actor, $profile)) {
            return true;
        }

        if ($this->permissionMode->isPermissions()) {
            return false;
        }

        // legacy + hybrid: IsExpenseAdmin may still mutate others
        return $this->resolveCapability(
            $actor->userId,
            'mutate_others',
            $profile->isExpenseAdmin(),
            false,
        );
    }

    /**
     * Phase 3 approval gate. Preserves:
     * - frozen ManagerUserId assignment
     * - owner self-approval forbidden unless Finance
     * - Finance override (including self-approval)
     * - legacy IsExpenseAdmin may approve others (not self) in legacy/hybrid
     */
    public function assertCanActAsManager(
        ExpenseActor $actor,
        ExpenseProfile $profile,
        ExpenseReceipt $receipt,
    ): void {
        $isFinance = $this->hasFinanceCapability($actor, $profile);
        $isOwner = $receipt->ownerUserId()->equals($actor->userId);
        if ($isOwner && !$isFinance) {
            throw ExpenseAccessDenied::selfApprovalForbidden();
        }

        $isAssignedManager = $receipt->managerUserId() !== null
            && $receipt->managerUserId()->equals($actor->userId);

        $assignedApprover = false;
        if ($isAssignedManager) {
            if ($this->permissionMode->isLegacy()) {
                $assignedApprover = true;
            } elseif ($this->permissionMode->isPermissions()) {
                $assignedApprover = $actor->hasPermission(Module::PERMISSION_APPROVE);
            } else {
                // hybrid: assigned manager may act if they have APPROVE or legacy profile active
                $assignedApprover = $actor->hasPermission(Module::PERMISSION_APPROVE) || $profile->isActive();
            }
        }

        $teAdminOverride = false;
        if (!$this->permissionMode->isPermissions()) {
            $teAdminOverride = $profile->isExpenseAdmin();
            if ($this->permissionMode->isHybrid() && $actor->hasPermission(Module::PERMISSION_ADMIN)) {
                // ADMIN alone does not grant approval override in the new model;
                // only legacy IsExpenseAdmin does. Log if ADMIN without legacy flag.
                if (!$profile->isExpenseAdmin() && $actor->hasPermission(Module::PERMISSION_ADMIN)) {
                    $this->logMismatch($actor->userId, 'te_admin_approval_override', true, false);
                }
            }
        }

        if (!$assignedApprover && !$isFinance && !$teAdminOverride) {
            throw ExpenseAccessDenied::approvalForbidden();
        }
    }

    public function platformAdministratorIsNotExpenseAdmin(ExpenseActor $actor, ExpenseProfile $profile): bool
    {
        return $actor->isPlatformAdministrator() && !$this->isExpenseAdmin($actor, $profile);
    }

    private function resolveCapability(
        UserId $userId,
        string $capability,
        bool $legacy,
        bool $permission,
    ): bool {
        if ($this->permissionMode->isLegacy()) {
            return $legacy;
        }
        if ($this->permissionMode->isPermissions()) {
            return $permission;
        }

        if ($legacy !== $permission) {
            $this->logMismatch($userId, $capability, $legacy, $permission);
        }

        // hybrid preserves access: either source is enough
        return $legacy || $permission;
    }

    private function logMismatch(UserId $userId, string $capability, bool $legacy, bool $permission): void
    {
        error_log(sprintf(
            '[triexpense.permission.mismatch] userId=%d capability=%s legacy=%s permission=%s mode=%s',
            $userId->toInt(),
            $capability,
            $legacy ? 'yes' : 'no',
            $permission ? 'yes' : 'no',
            $this->permissionMode->value(),
        ));
    }
}
