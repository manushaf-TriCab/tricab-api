<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use TriCab\Core\Finance\Ap\Domain\ApActor;
use TriCab\Core\Finance\Module;

/**
 * `GET /me` (source routes 3 and 4, decision D78).
 *
 * It answers the portal's own permission strings (`Module::AP_PORTAL_PERMISSIONS`: `invoice.invoices.read`,
 * and so on) so the pages' checks work unchanged, and the three fields the source's `_platform_user` worked
 * out: `role`, `finance_role` and `allowed_mailboxes`.
 */
final class ApMeService
{
    public function __construct(private readonly bool $sendingEnabled = false)
    {
    }

    /** @return array<string, mixed> */
    public function me(ApActor $actor): array
    {
        $portal = [];
        foreach (Module::AP_PORTAL_PERMISSIONS as $code => $string) {
            if ($actor->can($code)) {
                $portal[] = $string;
            }
        }
        sort($portal);
        $name = $actor->name !== '' ? $actor->name : $actor->email;

        return [
            'id' => (string) $actor->userId,
            'email' => strtolower($actor->email),
            'full_name' => $name,
            'permissions' => $portal,
            // The source's `admin` was "may manage users"; the platform's equivalent is the settings permission.
            'role' => $actor->isAdmin ? 'admin' : 'user',
            'finance_role' => self::financeRole($actor),
            // null is every mailbox (no scope rows, or an administrator); a list is exactly those (question Q11).
            'allowed_mailboxes' => $actor->mailboxScope->mailboxes(),
            'sending_enabled' => $this->sendingEnabled,
        ];
    }

    /** `_platform_user`: the approver permission wins, then the preparer's, else the operator. */
    private static function financeRole(ApActor $actor): string
    {
        if ($actor->can(Module::PERMISSION_AP_APPROVALS_DECIDE)) {
            return 'approver';
        }
        if ($actor->can(Module::PERMISSION_AP_APPROVALS_PREPARE)) {
            return 'preparer';
        }

        return 'operator';
    }
}
