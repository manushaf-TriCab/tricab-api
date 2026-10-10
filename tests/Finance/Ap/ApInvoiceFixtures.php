<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Finance\Ap\Domain\ApActor;
use TriCab\Core\Finance\Ap\Domain\ApInvoice;
use TriCab\Core\Finance\Ap\Domain\ApMailboxScope;
use TriCab\Core\Finance\Module;

/** Invented invoices and people for the Tri-AP register tests: `example.test` addresses and made-up vendors only. */
final class ApInvoiceFixtures
{
    public static function utc(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when, new DateTimeZone('UTC'));
    }

    /** A live, scanned invoice with plausible defaults; pass any property by name to change it. */
    public static function invoice(string $id, mixed ...$over): ApInvoice
    {
        $invoice = new ApInvoice(
            $id,
            self::utc('2026-10-05 02:00:00'),
            self::utc('2026-10-05 02:00:00'),
            emailId: 'msg-' . $id,
            mailbox: 'ap@example.test',
            emailDate: self::utc('2026-10-05 01:00:00'),
            sender: 'billing@vendor.example.test',
            subject: 'Invoice ' . $id,
            attachment: $id . '.pdf',
            invoiceNo: 'INV-' . $id,
            vendor: 'Acme Freight Pty Ltd',
            invoiceDate: '2026-10-01',
            dueDate: '2026-11-01',
            currency: 'AUD',
            netAmount: 100.00,
            taxAmount: 10.00,
            totalAmount: 110.00,
            status: ApInvoice::STATUS_IN_PROGRESS,
        );

        return $over === [] ? $invoice : $invoice->with(...$over);
    }

    /**
     * @param list<string>|null $mailboxes null for everyone
     * @param list<string> $permissions
     */
    public static function actor(?array $mailboxes = null, array $permissions = [Module::PERMISSION_AP_INVOICES_READ, Module::PERMISSION_AP_INVOICES_WRITE, Module::PERMISSION_AP_INVOICES_DELETE], int $userId = 7): ApActor
    {
        return new ApActor(
            $userId,
            'Dee Preparer',
            'Dee@Example.test',
            $permissions,
            $mailboxes === null ? ApMailboxScope::unrestricted() : ApMailboxScope::limitedTo($mailboxes),
        );
    }

    /**
     * The ids of an answer's rows, in order.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    public static function ids(array $rows): array
    {
        return array_map(static function (array $row): string {
            $id = $row['id'] ?? '';

            return is_string($id) ? $id : '';
        }, $rows);
    }
}
