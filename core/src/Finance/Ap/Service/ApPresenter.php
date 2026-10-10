<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Finance\Ap\Domain\ApReferenceRow;
use TriCab\Core\Finance\Ap\Domain\ApSigningLimit;

/**
 * Every Tri-AP value as the source's JSON shaped it, which is what the ported pages read.
 * Differences, all for the platform: ids that name a person are text (`"12"`) and a row id is 32 hex
 * characters rather than a UUID.
 */
final class ApPresenter
{
    /** A stored UTC timestamp as ISO 8601 with its offset (`+00:00`): JavaScript reads an offset-less one as local time. */
    public static function iso(?DateTimeImmutable $value): ?string
    {
        return $value?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    }

    public static function person(?int $userId): ?string
    {
        return $userId === null ? null : (string) $userId;
    }

    /**
     * A Settings row in the source's keys: `id`, the list's text columns, `is_active`, `created_date`.
     *
     * @return array<string, mixed>
     */
    public static function reference(ApReferenceRow $row): array
    {
        return ['id' => $row->id] + $row->values + [
            'is_active' => $row->active,
            'created_date' => self::iso($row->createdAt),
        ];
    }

    /**
     * One signing-authority row. The source's keys (`full_name`, `d365_position`, `authority_role`) and the
     * names the web notes list (`name`, `position`) are both present, so either reads.
     *
     * @return array<string, mixed>
     */
    public static function signingLimit(ApSigningLimit $row): array
    {
        return [
            'name' => $row->fullName,
            'full_name' => $row->fullName,
            'email' => $row->email,
            'position' => $row->d365Position,
            'd365_position' => $row->d365Position,
            'authority_role' => $row->authorityRole,
            'limits' => [
                'po_inventory' => $row->poInventory,
                'po_non_inventory' => $row->poNonInventory,
                'expense_claim' => $row->expenseClaim,
                'customer_order' => $row->customerOrder,
                'credit_notes' => $row->creditNotes,
            ],
        ];
    }
}
