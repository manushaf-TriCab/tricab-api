<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Assert;
use TriCab\Core\Finance\Ar\Domain\ArRecord;

/** Invented records for the Tri-AR tests: `example.test` addresses and made-up customers only. */
final class ArFixtures
{
    public static function utc(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when, new DateTimeZone('UTC'));
    }

    /** A record with plausible defaults; pass any property by name to change it. */
    public static function record(string $id, mixed ...$over): ArRecord
    {
        $record = new ArRecord(
            id: $id,
            emailDate: self::utc('2026-09-21 02:09:00'),
            customerName: 'Acme Marine Pty Ltd',
            senderEmail: 'accounts@acme.example.test',
            senderName: 'Alex Accounts',
            subject: 'Purchase order 77363',
            body: 'Please release the order on hold.',
            aiSummary: 'Customer asks for an order to be released.',
            followUpCategory: 'release_customer_orders',
            assignedUserId: null,
            assignedName: '',
            proposedResponseType: 'email',
            proposedResponse: '',
            followUpStatus: 'in_progress',
            dueDate: null,
            finalOutcome: '',
            sourceMessageId: 'msg-' . $id,
            messageUnreachable: false,
            mailbox: 'ar@tricab.com',
            classificationConfidence: 0.91,
            status: 'new',
            assignedSalesRepEmail: '',
            creditNoteRequestId: null,
            reminderSentAt: null,
            deletedAt: null,
            deletedByUserId: null,
            deletedBy: '',
            createdAt: self::utc('2026-09-21 02:10:00'),
            updatedAt: self::utc('2026-09-21 02:10:00'),
        );

        return $over === [] ? $record : $record->with(...$over);
    }

    /**
     * The ids of a list answer's records, in order.
     *
     * @param array<string, mixed> $result
     * @return list<string>
     */
    public static function idsOf(array $result): array
    {
        $out = [];
        foreach (self::rows($result['records'] ?? null) as $row) {
            Assert::assertIsString($row['id']);
            $out[] = $row['id'];
        }

        return $out;
    }

    /**
     * A JSON list of objects, checked to be one.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(mixed $value): array
    {
        Assert::assertIsArray($value);
        $out = [];
        foreach ($value as $row) {
            Assert::assertIsArray($row);
            $typed = [];
            foreach ($row as $key => $cell) {
                $typed[(string) $key] = $cell;
            }
            $out[] = $typed;
        }

        return $out;
    }
}
