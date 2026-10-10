<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

use DateTimeImmutable;

/**
 * What `GET /emails` asks of the store, already validated: live emails or the Bin, an optional classification, the period as
 * UTC instants (`start` inclusive, `end` EXCLUSIVE: the start of the day after the last day), the person's mailbox scope,
 * and the page. The order is fixed: `received_at DESC, id DESC`, so paging neither repeats nor skips a row.
 *
 * The scope, the period and the classification are applied in the query, before the page.
 */
final readonly class ApEmailQuery
{
    public const DEFAULT_LIMIT = 500;
    public const MAX_LIMIT = 1000;

    /**
     * @param list<string>|null $mailboxes lower-case addresses the person may see; null is unrestricted
     */
    public function __construct(
        public bool $deleted,
        public ?string $classification,
        public ?DateTimeImmutable $start,
        public ?DateTimeImmutable $end,
        public ?array $mailboxes,
        public int $limit = self::DEFAULT_LIMIT,
        public int $offset = 0,
    ) {
    }
}
