<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Mail;

/** One outbox row about to be written, with the addresses snapshotted. */
final readonly class NewNprOutboxEntry
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUPPRESSED = 'suppressed';
    public const STATUS_FAILED = 'failed';

    /**
     * @param list<string>                            $to
     * @param list<string>                            $cc
     * @param list<array{role: string, type: string}> $missing
     * @param array<string, mixed>                    $payload what the template needs, kept as the row's summary
     */
    public function __construct(
        public string $event,
        public string $requestPublicId,
        public array $to,
        public array $cc,
        public array $missing,
        public array $payload,
        public string $status,
    ) {
    }
}
