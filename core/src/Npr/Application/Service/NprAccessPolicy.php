<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Application\Service;

use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Npr\Domain\NprRequest;
use TriCab\Core\Npr\Module;

/**
 * Who may do what to one request, decided on the server (as RFQ does) and
 * returned to the client as `allowedActions`. The client never decides.
 *
 * Port of the requester half of the npr-portal's AccessControl, with its role
 * lookups replaced by the platform permissions in {@see Module}:
 *
 *  - read    own request with NPR.SELF.READ; or any request that has reached TSD
 *            with NPR.TSD.READ (source `canReadRequest`, narrowed to the TSD queue);
 *  - edit    own request, draft or returned to Sales, with NPR.SELF.CREATE;
 *  - submit  the same, with NPR.SELF.SUBMIT. Listed now so the editor can show
 *            the button; the endpoint arrives with N2.
 *
 * N2 adds manager and TSD actions (endorse, return, reject, acknowledge,
 * assign, assess, request_screening, decide) here, each from its own
 * permission and status.
 */
final class NprAccessPolicy
{
    public const ACTION_READ = 'read';
    public const ACTION_EDIT = 'edit';
    public const ACTION_SUBMIT = 'submit';

    public function canReadOwn(AuthenticatedUser $user, NprRequest $request): bool
    {
        return $request->isOwnedBy($user->userId) && self::holds($user, Module::PERMISSION_SELF_READ);
    }

    public function canReadAsTsd(AuthenticatedUser $user, NprRequest $request): bool
    {
        return $request->isVisibleToTsd() && self::holds($user, Module::PERMISSION_TSD_READ);
    }

    public function canEdit(AuthenticatedUser $user, NprRequest $request): bool
    {
        return $request->isOwnedBy($user->userId)
            && $request->status->isEditableBySales()
            && self::holds($user, Module::PERMISSION_SELF_CREATE);
    }

    /**
     * @return list<string>
     */
    public function allowedActions(AuthenticatedUser $user, NprRequest $request): array
    {
        $actions = [];
        if ($this->canReadOwn($user, $request) || $this->canReadAsTsd($user, $request)) {
            $actions[] = self::ACTION_READ;
        }
        if ($this->canEdit($user, $request)) {
            $actions[] = self::ACTION_EDIT;
            if (self::holds($user, Module::PERMISSION_SELF_SUBMIT)) {
                $actions[] = self::ACTION_SUBMIT;
            }
        }

        return $actions;
    }

    public static function holds(AuthenticatedUser $user, string $permission): bool
    {
        $wanted = strtoupper($permission);
        foreach ($user->permissions as $held) {
            if (strtoupper(trim($held)) === $wanted) {
                return true;
            }
        }

        return false;
    }
}
