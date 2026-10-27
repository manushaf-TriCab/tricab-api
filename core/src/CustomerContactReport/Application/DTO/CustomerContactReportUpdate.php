<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\DTO;

use DateTimeImmutable;
use TriCab\Core\CustomerContactReport\Domain\Credit\CustomerContactReportLine;

/**
 * An already validated, already authorised change to one report.
 * $changes are Details property names. $lines null leaves lines alone; a list
 * (even empty) replaces them in the same transaction.
 */
final readonly class CustomerContactReportUpdate
{
    /**
     * @param array<string, mixed> $changes
     * @param list<CustomerContactReportLine>|null $lines
     */
    public function __construct(
        public string $publicId,
        public string $expectedRowVersion,
        public array $changes,
        public ?array $lines,
        /** When true and a credit has been decided (or a Finance step exists), it goes back to pending. */
        public bool $creditChanged,
        public DateTimeImmutable $updatedAtUtc,
    ) {
    }
}
