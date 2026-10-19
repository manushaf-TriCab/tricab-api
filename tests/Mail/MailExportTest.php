<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail;

use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Mail\Application\DTO\NewAssessment;
use TriCab\Core\Mail\Module;

/** `GET /api/v1/mail/export`: the labelled set, as a CSV that carries no personal data beyond a sender's domain. */
final class MailExportTest extends MailAppTestCase
{
    private const PATH = '/api/v1/mail/export';

    /** @return list<array<string, string>> the rows, keyed by the header */
    private function rows(string $query = ''): array
    {
        $response = $this->call('GET', self::PATH . $query);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $lines = array_map(static fn (string $l): array => str_getcsv($l, ',', '"', ''), preg_split('/\r\n/', trim((string) $response->getBody())) ?: []);
        $header = array_shift($lines);
        self::assertIsArray($header);

        return array_map(static fn (array $cells): array => array_combine(array_map('strval', $header), array_map('strval', $cells)), $lines);
    }

    private function seed(): void
    {
        MailFixtures::classified($this->recorder, 'a1', 'rfq', 0.95, at: '2026-10-10 09:00:00', subject: 'Secret subject', fromName: 'Pat Private');
        MailFixtures::classified($this->recorder, 'a2', 'uncertain', 0.4, at: '2026-10-12 09:00:00', injection: true, fromAddress: 'Lee@Other.Example');
        MailFixtures::skipped($this->recorder, 'a3', '2026-10-14 09:00:00');
        // A message with no assessment yet is not in the set.
        $this->recorder->recordMessage(MailFixtures::message('a4', at: '2026-10-15 09:00:00'));
    }

    public function test_the_export_is_403_without_review_and_without_access(): void
    {
        foreach ([[Module::PERMISSION_ACCESS], [Module::PERMISSION_REVIEW], []] as $held) {
            $this->boot($held);
            $response = $this->call('GET', self::PATH);
            self::assertSame(403, $response->getStatusCode(), implode(',', $held));
            self::assertFalse(TestAppFactory::json($response)['success']);
        }
    }

    public function test_it_downloads_one_row_per_assessed_thread_with_skips_flagged_and_no_personal_data(): void
    {
        $this->boot(self::reviewer());
        $this->seed();

        $response = $this->call('GET', self::PATH);
        self::assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('attachment; filename="mail-labelled-set.csv"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('no-store, private', $response->getHeaderLine('Cache-Control'));
        $body = (string) $response->getBody();
        self::assertStringStartsWith(
            "thread_id,internet_message_id,received_at,sender_domain,classifier_label,confidence,model,classifier_version,skipped,injection_suspected,decided_label,decision_kind,decided_at,decided_by\r\n",
            $body,
        );
        foreach (['Secret subject', 'Pat Private', 'jo@', 'Lee@', 'Quote for 100m', 'Hi, could you'] as $private) {
            self::assertStringNotContainsString($private, $body);
        }

        $rows = $this->rows();
        self::assertCount(3, $rows);
        self::assertSame(['<a1@customer.example>', '<a2@customer.example>', '<a3@customer.example>'], array_column($rows, 'internet_message_id'));
        self::assertSame('2026-10-10T09:00:00.000Z', $rows[0]['received_at']);
        self::assertSame(['customer.example', 'other.example', 'customer.example'], array_column($rows, 'sender_domain'));
        self::assertSame(['rfq', 'uncertain', 'not_rfq'], array_column($rows, 'classifier_label'));
        self::assertSame(['0.95', '0.4', '1'], array_column($rows, 'confidence'));
        self::assertSame(['claude-test', 'claude-test', 'pre-filter'], array_column($rows, 'model'));
        self::assertSame(['false', 'false', 'true'], array_column($rows, 'skipped'));
        self::assertSame(['false', 'true', 'false'], array_column($rows, 'injection_suspected'));
        self::assertSame(['', '', ''], array_column($rows, 'decided_label'));
    }

    public function test_a_decision_fills_the_decided_columns_and_the_latest_decision_wins(): void
    {
        $this->boot(self::reviewer());
        $this->seed();
        $thread = MailFixtures::classified($this->recorder, 'b1', 'uncertain', 0.5, at: '2026-10-16 09:00:00')->threadId;
        $this->json('POST', '/api/v1/mail/threads/' . $thread . '/review', ['action' => 'correct_label', 'label' => 'rfq']);

        $row = $this->rows('?decidedOnly=true');
        self::assertCount(1, $row);
        self::assertSame('uncertain', $row[0]['classifier_label']);
        self::assertSame('rfq', $row[0]['decided_label']);
        self::assertSame('correct', $row[0]['decision_kind']);
        self::assertSame('2026-10-19T09:00:00.000Z', $row[0]['decided_at']);
        self::assertNotSame('', $row[0]['decided_by']);
        self::assertCount(4, $this->rows('?decidedOnly=false'));
        self::assertCount(4, $this->rows());
    }

    public function test_the_received_date_range_is_inclusive_and_a_bare_date_is_a_whole_day(): void
    {
        $this->boot(self::reviewer());
        $this->seed();

        self::assertSame(['<a2@customer.example>'], array_column($this->rows('?from=2026-10-12&to=2026-10-12'), 'internet_message_id'));
        self::assertSame(['<a2@customer.example>', '<a3@customer.example>'], array_column($this->rows('?from=2026-10-11'), 'internet_message_id'));
        self::assertSame(['<a1@customer.example>'], array_column($this->rows('?to=2026-10-11'), 'internet_message_id'));
        self::assertSame(['<a2@customer.example>'], array_column($this->rows('?from=2026-10-12T09:00:00Z&to=2026-10-12T09:00:00Z'), 'internet_message_id'));
        self::assertSame([], $this->rows('?from=2026-11-01'));
    }

    public function test_a_bad_filter_is_422(): void
    {
        $this->boot(self::reviewer());

        foreach (['decidedOnly=maybe', 'from=yesterday', 'to=2026-13-40', 'from=2026-10-12&to=2026-10-01'] as $query) {
            [$status, $json] = $this->json('GET', self::PATH . '?' . $query);
            self::assertSame(422, $status, $query);
            self::assertSame('MAIL_INPUT_INVALID', self::at($json, 'error', 'code'));
        }
    }

    public function test_cells_that_a_spreadsheet_would_run_are_neutralised_and_commas_and_quotes_are_escaped(): void
    {
        $this->boot(self::reviewer());
        $recorded = MailFixtures::classified($this->recorder, 'f1', 'rfq', 0.9, fromAddress: 'x@=cmd|evil.example');
        $this->recorder->recordAssessment(
            MailFixtures::MAILBOX,
            '<f1@customer.example>',
            NewAssessment::classified('rfq', 0.9, 'r', '=HYPERLINK("x")', 'v,1 "beta"', MailFixtures::at('2026-10-18 10:00:00')),
        );

        $body = (string) $this->call('GET', self::PATH)->getBody();
        self::assertStringContainsString("'=HYPERLINK(\"\"x\"\")", $body);
        self::assertStringContainsString('"v,1 ""beta"""', $body);
        $row = $this->rows()[0];
        self::assertSame($recorded->threadId, $row['thread_id']);
        self::assertSame("'=HYPERLINK(\"x\")", $row['model']);
        self::assertSame('v,1 "beta"', $row['classifier_version']);
        self::assertSame("'=cmd|evil.example", $row['sender_domain']);
    }

    public function test_exporting_leaves_no_activity_line(): void
    {
        $this->boot(self::reviewer());
        $thread = MailFixtures::classified($this->recorder, 'g1', 'rfq')->threadId;
        $before = $this->data('/api/v1/mail/threads/' . $thread)['activity'];
        $this->rows();

        self::assertSame($before, $this->data('/api/v1/mail/threads/' . $thread)['activity']);
    }
}
