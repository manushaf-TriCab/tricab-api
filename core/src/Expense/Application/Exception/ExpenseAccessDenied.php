<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Exception;

use RuntimeException;

final class ExpenseAccessDenied extends RuntimeException
{
    public static function permissionRequired(): self
    {
        return new self('TRIEXPENSE.ACCESS permission is required.');
    }

    public static function inactiveOrMissingProfile(): self
    {
        return new self('An active Tri Expense profile is required.');
    }

    public static function approvalForbidden(): self
    {
        return new self('You are not authorized to approve or reject this receipt.');
    }

    public static function sendBackForbidden(): self
    {
        return new self('Finance capability is required to send back a receipt.');
    }

    public static function ownershipForbidden(): self
    {
        return new self('Client-supplied ownership fields are not allowed.');
    }

    public static function viewForbidden(): self
    {
        return new self('You are not authorized to view this receipt.');
    }

    public static function selfApprovalForbidden(): self
    {
        return new self('You cannot approve or reject your own receipt.');
    }

    public static function deleteForbidden(): self
    {
        return new self('You are not authorized to delete this receipt.');
    }

    public static function restoreForbidden(): self
    {
        return new self('Finance capability is required to restore a receipt.');
    }

    public static function purgeForbidden(): self
    {
        return new self('Finance capability is required to permanently delete a receipt.');
    }

    public static function financeRequired(): self
    {
        return new self('Finance capability is required for this Tri Expense finance operation.');
    }
}