<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Application\Service;

use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Npr\Domain\NprRequest;
use TriCab\Core\Npr\Domain\RequestStatus;
use TriCab\Core\Npr\Module;

/**
 * Who may do what to one request, decided on the server (as RFQ does) and
 * returned to the client as `allowedActions`. The client never decides.
 *
 * Port of the npr-portal's AccessControl, with its role lookups replaced by the
 * platform permissions in {@see Module}. Each action needs its permission, the
 * right relationship to the record and the right status:
 *
 *  - read             own request (NPR.SELF.READ); the assigned manager (NPR.TEAM.ENDORSE);
 *                     anything in the TSD queue (NPR.TSD.READ);
 *  - edit, submit     the owner, draft or returned to Sales (NPR.SELF.CREATE / NPR.SELF.SUBMIT);
 *  - delete           the owner, a draft never submitted (NPR.SELF.CREATE); returned_to_sales does not qualify;
 *  - endorse          the assigned manager while awaiting_manager, never the requester
 *                     (interim policy: no self-endorsement);
 *  - return, reject   the assigned manager while awaiting_manager (NPR.TEAM.ENDORSE);
 *  - acknowledge, tsd_return, request_screening
 *                     TSD while with_tsd (NPR.TSD.WORK);
 *  - assign, assess   TSD while with_tsd or awaiting_screening (NPR.TSD.WORK);
 *  - decide           the assigned signatory while awaiting_screening (NPR.SCREENING.DECIDE).
 */
final class NprAccessPolicy
{
    public const ACTION_READ = 'read';
    public const ACTION_EDIT = 'edit';
    public const ACTION_SUBMIT = 'submit';
    public const ACTION_DELETE = 'delete';
    public const ACTION_ENDORSE = 'endorse';
    public const ACTION_RETURN = 'return';
    public const ACTION_REJECT = 'reject';
    public const ACTION_ACKNOWLEDGE = 'acknowledge';
    public const ACTION_ASSIGN = 'assign';
    public const ACTION_TSD_RETURN = 'tsd_return';
    public const ACTION_ASSESS = 'assess';
    public const ACTION_REQUEST_SCREENING = 'request_screening';
    public const ACTION_DECIDE = 'decide';

    public function canReadOwn(AuthenticatedUser $user, NprRequest $request): bool
    {
        return $request->isOwnedBy($user->userId) && self::holds($user, Module::PERMISSION_SELF_READ);
    }

    public function canReadAsTsd(AuthenticatedUser $user, NprRequest $request): bool
    {
        return $request->isVisibleToTsd() && self::holds($user, Module::PERMISSION_TSD_READ);
    }

    /** The assigned manager, with the endorse permission. Anyone else, including other managers, is not it. */
    public function canReadAsManager(AuthenticatedUser $user, NprRequest $request): bool
    {
        return $request->isManagedBy($user->userId) && self::holds($user, Module::PERMISSION_TEAM_ENDORSE);
    }

    public function canEdit(AuthenticatedUser $user, NprRequest $request): bool
    {
        return $request->isOwnedBy($user->userId)
            && $request->status->isEditableBySales()
            && self::holds($user, Module::PERMISSION_SELF_CREATE);
    }

    public function canSubmit(AuthenticatedUser $user, NprRequest $request): bool
    {
        return $this->canEdit($user, $request) && self::holds($user, Module::PERMISSION_SELF_SUBMIT);
    }

    /** A draft that was never sent anywhere; once submitted, even a returned request stays on record. */
    public function canDelete(AuthenticatedUser $user, NprRequest $request): bool
    {
        return $request->status === RequestStatus::Draft
            && $request->submittedAt === null
            && $request->isOwnedBy($user->userId)
            && self::holds($user, Module::PERMISSION_SELF_CREATE);
    }

    /** Who the request is with: the assigned manager, status aside. */
    public function isAssignedManager(AuthenticatedUser $user, NprRequest $request): bool
    {
        return $this->canReadAsManager($user, $request);
    }

    /** The nominated signatory, status aside. */
    public function isAssignedSignatory(AuthenticatedUser $user, NprRequest $request): bool
    {
        return $request->isScreenedBy($user->userId) && self::holds($user, Module::PERMISSION_SCREENING_DECIDE);
    }

    /**
     * @return list<string>
     */
    public function allowedActions(AuthenticatedUser $user, NprRequest $request): array
    {
        $actions = [];
        $status = $request->status;
        $asManager = $this->canReadAsManager($user, $request);
        $asTsd = $this->canReadAsTsd($user, $request);
        $tsdWorks = $asTsd && self::holds($user, Module::PERMISSION_TSD_WORK);

        if ($this->canReadOwn($user, $request) || $asManager || $asTsd || $this->isAssignedSignatory($user, $request)) {
            $actions[] = self::ACTION_READ;
        }
        if ($this->canEdit($user, $request)) {
            $actions[] = self::ACTION_EDIT;
        }
        if ($this->canSubmit($user, $request)) {
            $actions[] = self::ACTION_SUBMIT;
        }
        if ($this->canDelete($user, $request)) {
            $actions[] = self::ACTION_DELETE;
        }
        if ($asManager && $status === RequestStatus::AwaitingManager) {
            if (!$request->isOwnedBy($user->userId)) {
                $actions[] = self::ACTION_ENDORSE;
            }
            $actions[] = self::ACTION_RETURN;
            $actions[] = self::ACTION_REJECT;
        }
        if ($tsdWorks && $status === RequestStatus::WithTsd) {
            $actions[] = self::ACTION_ACKNOWLEDGE;
        }
        if ($tsdWorks && in_array($status, [RequestStatus::WithTsd, RequestStatus::AwaitingScreening], true)) {
            $actions[] = self::ACTION_ASSIGN;
            $actions[] = self::ACTION_ASSESS;
        }
        if ($tsdWorks && $status === RequestStatus::WithTsd) {
            $actions[] = self::ACTION_TSD_RETURN;
            $actions[] = self::ACTION_REQUEST_SCREENING;
        }
        if ($this->isAssignedSignatory($user, $request) && $status === RequestStatus::AwaitingScreening) {
            $actions[] = self::ACTION_DECIDE;
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
