<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap2\Domain\Ap2Assessment;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invoice;
use TriCab\Core\Finance\Ap2\Domain\Ap2Supplier;

/**
 * The named work queues. The six dashboard queues use the prototype's dashboard definitions (so a tile count equals
 * the list it opens); the others are the invoice's derived `queue`.
 */
final class Ap2Queues
{
    public const NAMES = [
        'Needs review', 'Awaiting matching', 'Approval', 'Ready to import', 'Open AP', 'Settled', 'Duplicate',
        'Payment hold', 'Sync failed', 'Due in 5 days',
    ];

    public static function matches(string $queue, Ap2Invoice $b, ?Ap2Supplier $s, Ap2Assessment $a, string $today): bool
    {
        $open = Ap2Positions::isOpen($b);

        return match ($queue) {
            'Needs review' => !$b->excluded && $a->validation !== [],
            'Awaiting matching' => !$b->excluded && $a->matching !== 'Matched',
            'Due in 5 days' => $open && $b->due !== null && $b->due >= $today && $b->due <= self::plusDays($today, 5),
            'Payment hold' => $open && ($s === null || $s->hold || $b->paymentHold || !$s->bankVerified),
            'Sync failed' => $b->importState === 'Import failed',
            default => $a->queue === $queue,
        };
    }

    public static function plusDays(string $date, int $days): string
    {
        return (new DateTimeImmutable($date . ' 12:00:00', new \DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }
}
