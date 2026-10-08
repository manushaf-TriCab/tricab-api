<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar\Migration;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Ar\Migration\ArAttachmentFolder;
use TriCab\Core\Finance\Ar\Migration\ArMigratedAttachment;
use TriCab\Core\Finance\Ar\Migration\ArMigrationBadValue;
use TriCab\Core\Finance\Ar\Migration\ArMigrationGuard;
use TriCab\Core\Finance\Ar\Migration\ArMigrationReport;
use TriCab\Core\Finance\Ar\Migration\ArMigrationUsers;
use TriCab\Core\Finance\Ar\Migration\ArRowMapper;
use TriCab\Core\Finance\Shared\Access\FinancePortalUserMapping;

/** The column rules and the guards of the Tri-AR migration (T5.15), one at a time. */
final class ArMigrationRulesTest extends TestCase
{
    private InventedTriArData $data;

    private ArMigrationReport $report;

    private ArRowMapper $mapper;

    protected function setUp(): void
    {
        $this->data = new InventedTriArData();
        $this->report = new ArMigrationReport();
        $this->mapper = new ArRowMapper($this->report, new ArMigrationUsers(), new ArAttachmentFolder($this->data->attachments));
    }

    /**
     * @param array<string, mixed> $over
     */
    private function emailDate(array $over): string
    {
        $row = $over + ['email_date' => '2026-09-21T02:09:00Z', 'created_date' => '2026-09-21T02:09:00Z', 'subject' => 's', 'sender_email' => 'a@example.test'];

        return $this->mapper->record($row, str_repeat('a', 32), null, true)->emailDate->format('Y-m-d H:i:s.u e');
    }

    /** @return iterable<string, array{string, string}> */
    public static function times(): iterable
    {
        yield 'the source\'s own second-precision form' => ['2026-09-21T02:09:00Z', '2026-09-21 02:09:00.000000 UTC'];
        yield 'python isoformat with microseconds' => ['2026-09-21T02:10:00.123456+00:00', '2026-09-21 02:10:00.123456 UTC'];
        yield 'an offset east of UTC' => ['2026-09-21T12:09:00+10:00', '2026-09-21 02:09:00.000000 UTC'];
        yield 'an offset without a colon' => ['2026-09-21T12:09:00-0530', '2026-09-21 17:39:00.000000 UTC'];
        yield 'no zone is read as UTC, as the source always wrote' => ['2026-09-21 02:09:00', '2026-09-21 02:09:00.000000 UTC'];
        yield 'a fraction longer than six digits is cut' => ['2026-09-21T02:09:00.1234567Z', '2026-09-21 02:09:00.123456 UTC'];
        yield 'a date alone is midnight' => ['2026-09-21', '2026-09-21 00:00:00.000000 UTC'];
        yield 'a crossing of the day' => ['2026-09-21T01:00:00+10:00', '2026-09-20 15:00:00.000000 UTC'];
    }

    #[DataProvider('times')]
    public function test_timestamps_become_utc(string $source, string $expected): void
    {
        self::assertSame($expected, $this->emailDate(['email_date' => $source]));
    }

    /** @return iterable<string, array{string}> */
    public static function badTimes(): iterable
    {
        yield 'words' => ['yesterday'];
        yield 'a day that is not' => ['2026-02-30T00:00:00Z'];
        yield 'an hour that is not' => ['2026-02-20T24:00:00Z'];
        yield 'a month that is not' => ['2026-13-01T00:00:00Z'];
        yield 'a zone that is not' => ['2026-02-20T00:00:00 Melbourne'];
    }

    #[DataProvider('badTimes')]
    public function test_a_timestamp_that_is_not_one_is_a_bad_value_named_by_column_and_not_by_content(string $source): void
    {
        try {
            $this->emailDate(['email_date' => $source]);
            self::fail('Expected a bad value.');
        } catch (ArMigrationBadValue $bad) {
            self::assertSame('email_date_invalid', $bad->kind);
            self::assertStringNotContainsString($source, $bad->getMessage());
        }
    }

    public function test_a_reminder_mark_that_cannot_be_read_is_a_problem_not_a_silent_null(): void
    {
        $this->expectException(ArMigrationBadValue::class);
        $this->mapper->record(['email_date' => '2026-09-21T02:09:00Z', 'created_date' => '2026-09-21T02:09:00Z', 'reminder_sent_at' => 'sent on a Tuesday'], str_repeat('a', 32), null, true);
    }

    public function test_file_names_and_types_are_made_fit_for_the_file_store_and_counted(): void
    {
        $record = $this->data->record();
        $this->data->file($record . '/a.pdf', 'x');

        $plain = $this->attachmentWith($record);
        $control = $this->attachmentWith($record, ['file_name' => "bad\x00name\x1f.pdf"]);
        $long = $this->attachmentWith($record, ['file_name' => str_repeat('n', 400) . '.pdf']);
        $nameless = $this->attachmentWith($record, ['file_name' => '  ']);
        $noType = $this->attachmentWith($record, ['content_type' => null]);
        $badType = $this->attachmentWith($record, ['content_type' => "text/\x01html"]);

        self::assertSame(['a.pdf', 'application/pdf'], [$plain->fileName, $plain->contentType]);
        self::assertSame('bad_name_.pdf', $control->fileName);
        self::assertSame(255, mb_strlen($long->fileName));
        self::assertSame('a.pdf', $nameless->fileName, 'the stored name stands in');
        self::assertSame('application/pdf', $noType->contentType, 'the extension stands in');
        self::assertSame('application/pdf', $badType->contentType);
        self::assertSame(3, $this->report->count('findings', 'file_name_adjusted'));
        self::assertSame(2, $this->report->count('findings', 'content_type_adjusted'));
    }

    /** @param array<string, mixed> $over */
    private function attachmentWith(string $record, array $over = []): ArMigratedAttachment
    {
        $row = $over + ['created_date' => '2026-09-21T02:11:00Z', 'file_name' => 'a.pdf', 'stored_name' => $record . '/a.pdf', 'content_type' => 'application/pdf'];

        return $this->mapper->attachment($row, str_repeat('b', 32), $record);
    }

    public function test_an_attachment_row_with_an_unlisted_extraction_status_is_refused(): void
    {
        $record = $this->data->record();
        $this->data->file($record . '/a.pdf', 'x');

        $this->expectException(ArMigrationBadValue::class);
        $this->mapper->attachment([
            'created_date' => '2026-09-21T02:11:00Z', 'file_name' => 'a.pdf', 'stored_name' => $record . '/a.pdf', 'extraction_status' => 'half-read',
        ], str_repeat('b', 32), $record);
    }

    public function test_the_host_guard_follows_the_other_finance_tools(): void
    {
        foreach (['localhost', 'LOCALHOST', '127.0.0.1', '::1', '(local)', 'localhost,1433', ' localhost '] as $host) {
            self::assertTrue(ArMigrationGuard::isLocal($host), $host);
            self::assertNull(ArMigrationGuard::hostRefusal($host, ''));
        }
        foreach (['tricab-mel.database.windows.net', 'tricab-mel', 'some.database.windows.net,1433', '', '10.1.2.3', 'localhost.example.test'] as $host) {
            self::assertFalse(ArMigrationGuard::isLocal($host), $host);
            self::assertNotNull(ArMigrationGuard::hostRefusal($host, ''), $host);
            self::assertNotNull(ArMigrationGuard::hostRefusal($host, '   '), 'a blank approval is no approval');
            self::assertNull(ArMigrationGuard::hostRefusal($host, 'Manusha'));
        }
        self::assertNotNull(ArMigrationGuard::blobRefusal(false));
        self::assertNull(ArMigrationGuard::blobRefusal(true));
    }

    public function test_users_resolve_by_portal_id_or_a_recorded_address_and_never_by_name(): void
    {
        $at = new DateTimeImmutable('2026-10-01', new DateTimeZone('UTC'));
        $users = new ArMigrationUsers([
            '7' => new FinancePortalUserMapping('7', 'Sam.Rowe@example.test', 301, $at),
            '8' => new FinancePortalUserMapping('8', '', 302, $at),
        ]);

        self::assertSame(301, $users->resolve('sam.rowe@EXAMPLE.test'));
        self::assertSame(301, $users->resolve(' 7 '));
        self::assertSame(302, $users->resolve('8'));
        self::assertNull($users->resolve('Sam Rowe'));
        self::assertNull($users->resolve(''));
        self::assertNull($users->resolve('nobody@example.test'));
    }

    public function test_the_report_never_takes_an_id_that_is_not_an_id(): void
    {
        $report = new ArMigrationReport();

        $report->problem('email_records', 'fb3a0c', 'x');
        $report->problem('email_records', 'Alex Accounts <accounts@acme.example.test>', 'x');
        $report->conflict('email_records', str_repeat('a', 65));

        self::assertSame(['fb3a0c', '(not shown)'], array_column($report->problems(), 'id'));
        self::assertSame('(not shown)', $report->conflicts()[0]['id']);
    }
}
