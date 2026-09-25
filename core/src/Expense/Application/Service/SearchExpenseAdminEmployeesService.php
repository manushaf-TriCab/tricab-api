<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserListCriteria;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserTypeClassifier;
use TriCab\Core\Identity\Contract\UserManagementRepository;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\TelDirectory\Application\Service\TelDirectoryMapper;
use TriCab\Core\TelDirectory\Contract\TelDirectoryGraphReader;

/**
 * Employee directory search for TriExpense Administration (Add User / Approver).
 *
 * Source of truth for selectable identities: identity.Users via User Management.
 * Optional Tel Directory enrichment surfaces Entra employees not yet onboarded to V2
 * (not selectable for Add User — no ad-hoc identity provisioning).
 */
final class SearchExpenseAdminEmployeesService
{
    public const PURPOSE_TE_USER = 'te_user';
    public const PURPOSE_APPROVER = 'approver';

    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly UserManagementRepository $users,
        private readonly ExpenseProfileRepository $profiles,
        private readonly ?TelDirectoryGraphReader $telDirectory = null,
        private readonly TelDirectoryMapper $telMapper = new TelDirectoryMapper(),
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, query: string, purpose: string}
     */
    public function execute(ExpenseActor $actor, string $query, string $purpose = self::PURPOSE_TE_USER): array
    {
        if ($purpose === self::PURPOSE_APPROVER) {
            $this->access->requireProfileAdministration($actor);
        } else {
            $this->access->requireExpenseAdmin($actor);
            $purpose = self::PURPOSE_TE_USER;
        }

        $needle = strtolower(trim($query));
        if (strlen($needle) < 2) {
            return ['items' => [], 'query' => $needle, 'purpose' => $purpose];
        }

        $listed = $this->users->listUsers(new UserListCriteria(
            page: 1,
            pageSize: 25,
            search: $needle,
            sortBy: UserListCriteria::SORT_DISPLAY_NAME,
            sortDirection: UserListCriteria::SORT_ASC,
        ));

        $items = [];
        $seenEmails = [];

        foreach ($listed->items as $summary) {
            $status = strtolower($summary->status);
            if ($status === UserStatus::DISABLED) {
                continue;
            }

            $userType = $summary->userType ?? UserTypeClassifier::fromRoleCodes($summary->roles);
            if ($userType === UserTypeClassifier::GUEST || $userType === UserTypeClassifier::CUSTOMER) {
                continue;
            }

            $details = $this->users->findDetailsByPublicId(
                UserPublicId::fromString($summary->publicId),
            );
            $email = strtolower($summary->emailAddress);
            $seenEmails[$email] = true;

            $teConfigured = $this->isTeConfigured(
                $details !== null ? $details->effectivePermissions : [],
                $summary->publicId,
            );
            $eligibility = $this->eligibility($purpose, $status, $teConfigured, true);

            $items[] = [
                'publicId' => $summary->publicId,
                'displayName' => $summary->displayName,
                'email' => $summary->emailAddress,
                'status' => $status,
                'userType' => $userType,
                'source' => 'v2',
                'onboarded' => true,
                'teConfigured' => $teConfigured,
                'eligible' => $eligibility['eligible'],
                'ineligibleReason' => $eligibility['reason'],
                'statusLabel' => $this->statusLabel($status),
            ];
        }

        if ($purpose === self::PURPOSE_TE_USER) {
            foreach ($this->directoryOnlyMatches($needle, $seenEmails) as $row) {
                $items[] = $row;
            }
        }

        usort(
            $items,
            static fn (array $a, array $b): int => strcasecmp((string) $a['displayName'], (string) $b['displayName']),
        );

        return [
            'items' => array_slice($items, 0, 25),
            'query' => $needle,
            'purpose' => $purpose,
        ];
    }

    /**
     * @param list<\TriCab\Core\Identity\Application\DTO\UserManagement\EffectivePermissionGrant> $grants
     */
    private function isTeConfigured(array $grants, string $publicId): bool
    {
        foreach ($grants as $grant) {
            if (str_starts_with(strtoupper($grant->permissionCode), 'TRIEXPENSE.')) {
                return true;
            }
        }
        $userId = $this->users->findUserIdByPublicId(UserPublicId::fromString($publicId));
        if ($userId === null) {
            return false;
        }
        $profile = $this->profiles->findByUserId($userId);

        return $profile !== null && $profile->isActive();
    }

    /**
     * @return array{eligible: bool, reason: string|null}
     */
    private function eligibility(string $purpose, string $status, bool $teConfigured, bool $onboarded): array
    {
        if (!$onboarded) {
            return [
                'eligible' => false,
                'reason' => 'Not yet onboarded to TriCab. Create/link the user in User Management first.',
            ];
        }
        if ($status === UserStatus::DISABLED) {
            return ['eligible' => false, 'reason' => 'Account is disabled.'];
        }
        if ($purpose === self::PURPOSE_TE_USER && $teConfigured) {
            return ['eligible' => false, 'reason' => 'Already configured for TriExpense'];
        }
        if ($status === UserStatus::PENDING || $status === UserStatus::ACTIVE) {
            return ['eligible' => true, 'reason' => null];
        }

        return ['eligible' => false, 'reason' => 'Account status is not eligible.'];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            UserStatus::PENDING => 'Pending activation',
            UserStatus::ACTIVE => 'Active',
            UserStatus::DISABLED => 'Disabled',
            default => $status,
        };
    }

    /**
     * @param array<string, true> $seenEmails
     * @return list<array<string, mixed>>
     */
    private function directoryOnlyMatches(string $needle, array $seenEmails): array
    {
        if ($this->telDirectory === null || !$this->telDirectory->isConfigured()) {
            return [];
        }

        try {
            $rows = $this->telDirectory->listActiveDirectoryUsers();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $entry = $this->telMapper->map($row);
            if ($entry === null) {
                continue;
            }
            $email = strtolower($entry->email);
            if ($email === '' || isset($seenEmails[$email])) {
                continue;
            }
            $hay = strtolower($entry->name . ' ' . $entry->email);
            if (!str_contains($hay, $needle)) {
                continue;
            }
            $out[] = [
                'publicId' => null,
                'displayName' => $entry->name,
                'email' => $entry->email,
                'status' => null,
                'userType' => UserTypeClassifier::INTERNAL,
                'source' => 'directory',
                'onboarded' => false,
                'teConfigured' => false,
                'eligible' => false,
                'ineligibleReason' => 'Not yet onboarded to TriCab. Create/link the user in User Management first.',
                'statusLabel' => 'Directory employee · Not yet onboarded',
            ];
            if (count($out) >= 10) {
                break;
            }
        }

        return $out;
    }
}
