<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Mail;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Mail\AzureSqlFinanceMailLedger;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Core\Finance\Shared\Mail\FinanceMailDelivery;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRules;

/**
 * The same adapters over a fake connection, so their SQL and mapping are checked
 * where there is no database. Not grouped: always runs.
 */
final class AzureSqlFinanceMailFakeConnectionTest extends TestCase
{
    public function test_the_ledger_reads_a_row_into_a_delivery(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([[
            'id' => 'd1', 'application_key' => 'TASK', 'actor_user_id' => '7', 'mailbox_email' => 'ap@tricab.com',
            'to_addresses_json' => '["a@x.com"]', 'cc_addresses_json' => '[]', 'subject' => 'S', 'body_sha256' => str_repeat('a', 64),
            'attachment_names_json' => '["f.pdf"]', 'idempotency_key' => 'k', 'status' => 'sent', 'error_message' => null,
            'created_at' => '2026-10-07 01:02:03.456789', 'sent_at' => '2026-10-07 01:02:04.000000',
        ]]);

        $row = (new AzureSqlFinanceMailLedger($connection))->findByIdempotencyKey('TASK', 'k');

        self::assertNotNull($row);
        self::assertSame(7, $row->actorUserId);
        self::assertTrue($row->isSent());
        self::assertSame(['f.pdf'], $row->attachmentNames);
        self::assertStringContainsString('[finance].[mail_deliveries]', $connection->calls[0]['sql']);
        self::assertSame(['app' => 'TASK', 'key' => 'k'], $connection->calls[0]['params']);
    }

    public function test_a_bcc_has_nowhere_to_be_written(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['id' => 'd1']]);

        (new AzureSqlFinanceMailLedger($connection))->recordPending(new FinanceMailDelivery(
            'd1', 'TASK', null, 'ap@tricab.com', ['a@x.com'], [], 'S', str_repeat('a', 64), [], null,
            FinanceMailRules::STATUS_PENDING, null, new DateTimeImmutable('2026-10-07', new DateTimeZone('UTC')), null,
        ));

        self::assertStringNotContainsStringIgnoringCase('bcc', $connection->calls[0]['sql']);
        self::assertNull($connection->calls[0]['params']['actor']);
    }
}
