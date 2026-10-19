<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Mail;

use DateTimeImmutable;

/** One stored outbox row with its deliveries. */
final readonly class NprOutboxEntry
{
    /**
     * @param list<string>               $to
     * @param list<string>               $cc
     * @param array<string, mixed>       $payload
     * @param list<NprOutboxDelivery>    $deliveries
     */
    public function __construct(
        public int $id,
        public string $requestPublicId,
        public string $requestNumber,
        public string $event,
        public array $to,
        public array $cc,
        public array $payload,
        public string $status,
        public int $attempts,
        public ?DateTimeImmutable $lastAttemptAt,
        public ?string $error,
        public DateTimeImmutable $createdAt,
        public array $deliveries,
    ) {
    }

    public function hasMissingRecipients(): bool
    {
        foreach ($this->deliveries as $delivery) {
            if ($delivery->isMissingRecipient()) {
                return true;
            }
        }

        return false;
    }
}
