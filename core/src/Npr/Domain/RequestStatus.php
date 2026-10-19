<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Domain;

/**
 * Where an NPR is in its life. Values match `[npr].[Requests].[Status]`'s CHECK
 * constraint and the npr-portal's `npr_requests.status`.
 *
 * The screening outcome (approved, deferred, rejected) is not a status; it sits
 * beside `screening_completed`.
 */
enum RequestStatus: string
{
    case Draft = 'draft';
    case AwaitingManager = 'awaiting_manager';
    case ReturnedToSales = 'returned_to_sales';
    case WithTsd = 'with_tsd';
    case AwaitingScreening = 'awaiting_screening';
    case ScreeningCompleted = 'screening_completed';
    case Cancelled = 'cancelled';

    /** Sales may change the answers (source `canEditRequest`). */
    public function isEditableBySales(): bool
    {
        return $this === self::Draft || $this === self::ReturnedToSales;
    }

    /**
     * Statuses that are in the TSD queue on their own: with TSD or later. A
     * `returned_to_sales` request counts too, but only if TSD had received it
     * ({@see NprRequest::isVisibleToTsd()}).
     */
    public function isVisibleToTsd(): bool
    {
        return $this === self::WithTsd
            || $this === self::AwaitingScreening
            || $this === self::ScreeningCompleted;
    }

    /** @return list<self> */
    public static function tsdVisible(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $status): bool => $status->isVisibleToTsd()));
    }
}
