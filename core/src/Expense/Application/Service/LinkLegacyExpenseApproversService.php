<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Idempotent linker: legacy TE manager_login → ExpenseProfiles.ManagerUserId.
 *
 * Precedence / safety:
 * - Never uses tc.employee.manager or Graph.
 * - Never overwrites an existing different ManagerUserId (CONFLICT).
 * - Skips inactive profiles.
 * - Default callers should dry-run; apply only when classification is WOULD_LINK.
 */
final class LinkLegacyExpenseApproversService
{
    public function __construct(
        private readonly ExpenseProfileRepository $profiles,
    ) {
    }

    /**
     * @param list<array{
     *   login_norm: string,
     *   email_norm: string,
     *   is_active: int|bool,
     *   manager_login: string
     * }> $legacyTeUsers
     * @param array<string, list<array{userId: int, email_norm: string}>> $v2UsersByEmail
     *        email_norm → candidate V2 users (usually one)
     * @param array<string, string> $loginToEmail legacy login_norm → email_norm
     * @return list<LinkLegacyExpenseApproverResult>
     */
    public function classify(
        array $legacyTeUsers,
        array $v2UsersByEmail,
        array $loginToEmail,
    ): array {
        $results = [];
        foreach ($legacyTeUsers as $te) {
            $results[] = $this->classifyOne($te, $v2UsersByEmail, $loginToEmail);
        }

        return $results;
    }

    /**
     * @param array{
     *   login_norm: string,
     *   email_norm: string,
     *   is_active: int|bool,
     *   manager_login: string
     * } $te
     * @param array<string, list<array{userId: int, email_norm: string}>> $v2UsersByEmail
     * @param array<string, string> $loginToEmail
     */
    public function classifyOne(
        array $te,
        array $v2UsersByEmail,
        array $loginToEmail,
    ): LinkLegacyExpenseApproverResult {
        $active = (int) $te['is_active'] === 1 || $te['is_active'] === true;
        if (!$active) {
            return new LinkLegacyExpenseApproverResult(LinkLegacyExpenseApproverResult::INACTIVE_SKIPPED);
        }

        $email = strtolower(trim((string) $te['email_norm']));
        $candidates = $v2UsersByEmail[$email] ?? [];
        if ($candidates === []) {
            return new LinkLegacyExpenseApproverResult(LinkLegacyExpenseApproverResult::EMPLOYEE_NOT_FOUND);
        }
        if (count($candidates) > 1) {
            return new LinkLegacyExpenseApproverResult(LinkLegacyExpenseApproverResult::AMBIGUOUS, details: [
                'reason' => 'multiple_v2_users_for_employee_email',
            ]);
        }

        $employeeUserId = (int) $candidates[0]['userId'];
        $profile = $this->profiles->findByUserId(UserId::fromInt($employeeUserId));
        if ($profile === null) {
            return new LinkLegacyExpenseApproverResult(
                LinkLegacyExpenseApproverResult::EMPLOYEE_NOT_FOUND,
                employeeUserId: $employeeUserId,
                details: ['reason' => 'no_expense_profile'],
            );
        }
        if (!$profile->isActive()) {
            return new LinkLegacyExpenseApproverResult(
                LinkLegacyExpenseApproverResult::INACTIVE_SKIPPED,
                employeeUserId: $employeeUserId,
            );
        }

        $mgrLogin = strtolower(trim((string) $te['manager_login']));
        if ($mgrLogin === '') {
            return new LinkLegacyExpenseApproverResult(
                LinkLegacyExpenseApproverResult::NO_LEGACY_MANAGER,
                employeeUserId: $employeeUserId,
            );
        }

        $mgrEmail = $loginToEmail[$mgrLogin]
            ?? (str_contains($mgrLogin, '@') ? $mgrLogin : $mgrLogin . '@tricab.com');
        $mgrEmail = strtolower(trim($mgrEmail));
        $mgrCandidates = $v2UsersByEmail[$mgrEmail] ?? [];
        if ($mgrCandidates === []) {
            return new LinkLegacyExpenseApproverResult(
                LinkLegacyExpenseApproverResult::MANAGER_NOT_FOUND,
                employeeUserId: $employeeUserId,
                legacyManagerLogin: $mgrLogin,
            );
        }
        if (count($mgrCandidates) > 1) {
            return new LinkLegacyExpenseApproverResult(
                LinkLegacyExpenseApproverResult::AMBIGUOUS,
                employeeUserId: $employeeUserId,
                legacyManagerLogin: $mgrLogin,
                details: ['reason' => 'multiple_v2_users_for_manager_email'],
            );
        }

        $managerUserId = (int) $mgrCandidates[0]['userId'];
        $existing = $profile->managerUserId()?->toInt();
        if ($existing === $managerUserId) {
            return new LinkLegacyExpenseApproverResult(
                LinkLegacyExpenseApproverResult::ALREADY_LINKED,
                employeeUserId: $employeeUserId,
                managerUserId: $managerUserId,
                legacyManagerLogin: $mgrLogin,
            );
        }
        if ($existing !== null) {
            return new LinkLegacyExpenseApproverResult(
                LinkLegacyExpenseApproverResult::CONFLICT,
                employeeUserId: $employeeUserId,
                managerUserId: $managerUserId,
                legacyManagerLogin: $mgrLogin,
                details: ['existing_manager_user_id' => $existing],
            );
        }

        return new LinkLegacyExpenseApproverResult(
            LinkLegacyExpenseApproverResult::WOULD_LINK,
            employeeUserId: $employeeUserId,
            managerUserId: $managerUserId,
            legacyManagerLogin: $mgrLogin,
        );
    }

    /**
     * Apply a WOULD_LINK result. Refuses other classifications.
     */
    public function apply(LinkLegacyExpenseApproverResult $result): ExpenseProfile
    {
        if ($result->classification !== LinkLegacyExpenseApproverResult::WOULD_LINK) {
            throw new \InvalidArgumentException(
                'Only WOULD_LINK results may be applied; got ' . $result->classification,
            );
        }
        if ($result->employeeUserId === null || $result->managerUserId === null) {
            throw new \InvalidArgumentException('WOULD_LINK requires employee and manager user ids.');
        }

        $profile = $this->profiles->findByUserId(UserId::fromInt($result->employeeUserId));
        if ($profile === null || !$profile->isActive()) {
            throw new \RuntimeException('Employee ExpenseProfile missing or inactive at apply time.');
        }
        if ($profile->managerUserId() !== null) {
            // Race / concurrent change → treat as conflict, do not overwrite.
            throw new \RuntimeException('CONFLICT: ManagerUserId became set before apply.');
        }

        $updated = new ExpenseProfile(
            $profile->userId(),
            $profile->legacyLogin(),
            $profile->legacyEmail(),
            $profile->isActive(),
            $profile->isFinance(),
            $profile->isExpenseAdmin(),
            $result->legacyManagerLogin,
            UserId::fromInt($result->managerUserId),
        );

        return $this->profiles->save($updated);
    }

    /**
     * @param list<LinkLegacyExpenseApproverResult> $results
     * @return array<string, int>
     */
    public static function countByClassification(array $results): array
    {
        $counts = [
            LinkLegacyExpenseApproverResult::WOULD_LINK => 0,
            LinkLegacyExpenseApproverResult::ALREADY_LINKED => 0,
            LinkLegacyExpenseApproverResult::MANAGER_NOT_FOUND => 0,
            LinkLegacyExpenseApproverResult::EMPLOYEE_NOT_FOUND => 0,
            LinkLegacyExpenseApproverResult::AMBIGUOUS => 0,
            LinkLegacyExpenseApproverResult::CONFLICT => 0,
            LinkLegacyExpenseApproverResult::INACTIVE_SKIPPED => 0,
            LinkLegacyExpenseApproverResult::NO_LEGACY_MANAGER => 0,
        ];
        foreach ($results as $result) {
            $counts[$result->classification] = ($counts[$result->classification] ?? 0) + 1;
        }

        return $counts;
    }
}
