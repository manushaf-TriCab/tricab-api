<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Migration;

/** What one run of the tool was asked to do. */
final readonly class ArMigrationOptions
{
    /**
     * @param bool $apply write to the target; false plans only
     * @param bool $translateIds ask the translator for each record's immutable message id (question Q1)
     * @param bool $withoutMessageIds blank every message id and flag every record unreachable
     * @param string $mailbox the mailbox the tombstones' ids are translated for (records carry their own)
     */
    public function __construct(
        public bool $apply = false,
        public bool $translateIds = false,
        public bool $withoutMessageIds = false,
        public string $mailbox = ArRowMapper::DEFAULT_MAILBOX,
    ) {
    }
}
