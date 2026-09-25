<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

/**
 * Classification for linking legacy TriExpense approvers → V2 ExpenseProfiles.
 *
 * Source of truth when present: tc.triexpense_users.manager_login
 * (NOT tc.employee.manager, NOT Microsoft Graph).
 */
final class LinkLegacyExpenseApproverResult
{
    public const WOULD_LINK = 'WOULD_LINK';
    public const ALREADY_LINKED = 'ALREADY_LINKED';
    public const MANAGER_NOT_FOUND = 'MANAGER_NOT_FOUND';
    public const EMPLOYEE_NOT_FOUND = 'EMPLOYEE_NOT_FOUND';
    public const AMBIGUOUS = 'AMBIGUOUS';
    public const CONFLICT = 'CONFLICT';
    public const INACTIVE_SKIPPED = 'INACTIVE_SKIPPED';
    public const NO_LEGACY_MANAGER = 'NO_LEGACY_MANAGER';

    /**
     * @param array<string, mixed> $details Masked / non-PII diagnostics only.
     */
    public function __construct(
        public readonly string $classification,
        public readonly ?int $employeeUserId = null,
        public readonly ?int $managerUserId = null,
        public readonly ?string $legacyManagerLogin = null,
        public readonly array $details = [],
    ) {
    }
}
