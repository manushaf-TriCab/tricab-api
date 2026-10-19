<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Mail;

use DateTimeImmutable;

/** One recipient of one outbox row. A missing required recipient is a row whose address reads `(missing: role)`. */
final readonly class NprOutboxDelivery
{
    public const MISSING_PREFIX = '(missing: ';

    public function __construct(
        public int $id,
        public string $email,
        public string $type,
        public string $status,
        public int $attempts,
        public ?DateTimeImmutable $sentAt,
        public ?string $error,
    ) {
    }

    public function isMissingRecipient(): bool
    {
        return str_starts_with($this->email, self::MISSING_PREFIX);
    }
}
