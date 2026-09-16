<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\Service;

use TriCab\Core\Expense\Module;

/**
 * Legacy compatibility: the submit path mails a different audience depending on
 * how the receipt reached `pending`.
 *
 * - draft_then_submit → owner AND manager
 * - immediate         → owner only
 *
 * The asymmetry is not a defect to be tidied up. Legacy TriExpense only mailed
 * the manager from the explicit "submit a saved draft" action, and Finance
 * reporting has been reconciled against that behaviour for years. Changing it
 * would silently alter approval-chase volumes, so it is reproduced verbatim and
 * isolated here rather than inlined at the call site.
 */
final class ExpenseSubmitNotificationRecipients
{
    /**
     * @param 'draft_then_submit'|'immediate'|string $submitMode
     * @return list<string> de-duplicated lowercase addresses, owner first
     */
    public static function resolve(string $submitMode, ?string $ownerEmail, ?string $managerEmail): array
    {
        $recipients = [];
        $owner = self::normalize($ownerEmail);
        if ($owner !== null) {
            $recipients[] = $owner;
        }

        if (self::notifiesManager($submitMode)) {
            $manager = self::normalize($managerEmail);
            if ($manager !== null && !in_array($manager, $recipients, true)) {
                $recipients[] = $manager;
            }
        }

        return $recipients;
    }

    public static function notifiesManager(string $submitMode): bool
    {
        return trim($submitMode) !== Module::SUBMIT_IMMEDIATE;
    }

    private static function normalize(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }
        $normalized = strtolower(trim($email));

        return $normalized !== '' && str_contains($normalized, '@') ? $normalized : null;
    }
}
