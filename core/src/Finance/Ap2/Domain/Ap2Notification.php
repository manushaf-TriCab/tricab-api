<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

use DateTimeImmutable;

/** One line in a person's bell (`finance.ap2_notifications`). */
final readonly class Ap2Notification
{
    public function __construct(
        public string $id,
        public int $userId,
        public string $title,
        public string $body,
        public DateTimeImmutable $at,
        public bool $read = false,
        public string $link = '',
    ) {
    }
}
