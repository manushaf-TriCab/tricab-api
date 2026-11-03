<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail;

use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Mail\Application\DTO\ExportQuery;
use TriCab\Core\Mail\Application\DTO\ExportRow;
use TriCab\Core\Mail\Application\Service\MailAccuracyService;
use TriCab\Core\Mail\Module;

/** `GET /api/v1/mail/accuracy` and the arithmetic behind it: the M5 gate numbers. */
final class MailAccuracyTest extends MailAppTestCase
{
    private const PATH = '/api/v1/mail/accuracy';

    private static function row(string $predicted, ?string $human, bool $skipped = false, bool $stale = false): ExportRow
    {
        return new ExportRow(
            't', '<m>', new \DateTimeImmutable('2026-10-10'), 'x.example', $predicted, 0.9, 'm', 'v', $skipped, false,
            $human, $human === null ? null : 'correct', null, null, $stale,
        );
    }

    /**
     * @param list<array{0: string, 1: ?string}> $pairs
     *
     * @return array<string, mixed>
     */
    private static function compute(array $pairs): array
    {
        return MailAccuracyService::compute(array_map(static fn (array $p): ExportRow => self::row($p[0], $p[1]), $pairs), new ExportQuery());
    }

    public function test_recall_counts_uncertain_as_caught_and_follow_ups_as_rfqs(): void
    {
        $r = self::compute([
            ['rfq', 'rfq'], ['uncertain', 'rfq'], ['rfq_followup', 'rfq_followup'], ['not_rfq', 'rfq_followup'],
            ['not_rfq', 'not_rfq'], ['rfq', 'not_rfq'],
        ]);

        self::assertSame(['count' => 3, 'of' => 4, 'rate' => 0.75, 'target' => 0.98, 'met' => false], $r['rfqRecall']);
        // Of the two threads called rfq, one was an rfq; a rfq_followup prediction is not in this ratio.
        self::assertSame(['count' => 1, 'of' => 2, 'rate' => 0.5, 'target' => 0.9, 'met' => false], $r['rfqPrecision']);
        // Of the two not_rfq predictions, one was really a follow-up.
        self::assertSame(['count' => 1, 'of' => 2, 'rate' => 0.5, 'target' => null, 'met' => null], $r['missedRfqRate']);
        self::assertSame(1, self::at($r, 'matrix', 'not_rfq', 'rfq_followup'));
        self::assertSame(1, self::at($r, 'matrix', 'uncertain', 'rfq'));
    }

    public function test_labelled_and_unlabelled_counts_and_the_uncertain_share(): void
    {
        $r = self::compute([
            ['rfq', 'rfq'], ['uncertain', 'rfq'], ['not_rfq', 'not_rfq'], ['not_rfq', null], ['uncertain', null], ['rfq', null],
        ]);

        self::assertSame(6, $r['total']);
        self::assertSame(3, $r['labelled']);
        self::assertSame(3, $r['unlabelled']);
        self::assertSame(['count' => 1, 'of' => 3, 'rate' => 0.3333, 'target' => 0.1, 'met' => false], $r['uncertainShare']);
        self::assertSame(['count' => 2, 'of' => 6, 'rate' => 0.3333, 'target' => 0.1, 'met' => false], $r['uncertainShareAll']);
    }

    public function test_exactly_at_the_target_is_met(): void
    {
        $r = self::compute([...array_fill(0, 49, ['rfq', 'rfq']), ['not_rfq', 'rfq']]);
        self::assertTrue(self::at($r, 'rfqRecall', 'met'));
        self::assertSame(0.98, self::at($r, 'rfqRecall', 'rate'));
        $ten = self::compute([...array_fill(0, 9, ['rfq', 'rfq']), ['uncertain', 'rfq']]);
        self::assertTrue(self::at($ten, 'uncertainShare', 'met'), '1 of 10 is 10%, the most allowed');
    }

    public function test_nothing_to_measure_gives_null_rates_not_a_pass(): void
    {
        $r = self::compute([['rfq', null]]);

        self::assertSame(0, $r['labelled']);
        foreach (['rfqRecall', 'rfqPrecision', 'missedRfqRate', 'uncertainShare'] as $metric) {
            self::assertNull(self::at($r, $metric, 'rate'), $metric);
            self::assertNull(self::at($r, $metric, 'met'), $metric);
        }
    }

    public function test_a_human_uncertain_is_labelled_but_left_out_of_the_rates_and_a_skipped_rfq_is_a_miss(): void
    {
        $rows = [
            self::row('rfq', 'uncertain'),
            self::row('not_rfq', 'rfq', skipped: true),
            self::row('rfq', 'rfq'),
            self::row('rfq', 'rfq', stale: true),
        ];
        $r = MailAccuracyService::compute($rows, new ExportQuery());

        self::assertSame(3, $r['labelled'], 'the stale decision does not count');
        self::assertSame(1, $r['unlabelled']);
        self::assertSame(1, $r['humanUncertain']);
        self::assertSame(1, $r['skippedLabelled']);
        self::assertSame(['count' => 1, 'of' => 2, 'rate' => 0.5, 'target' => 0.98, 'met' => false], $r['rfqRecall']);
        self::assertSame(['count' => 1, 'of' => 1, 'rate' => 1.0, 'target' => 0.9, 'met' => true], $r['rfqPrecision']);
    }

    public function test_the_route_needs_review_and_reads_decisions_made_through_the_review_path(): void
    {
        foreach ([[Module::PERMISSION_ACCESS], [Module::PERMISSION_REVIEW], []] as $held) {
            $this->boot($held);
            self::assertSame(403, $this->call('GET', self::PATH)->getStatusCode(), implode(',', $held));
        }

        $this->boot(self::reviewer());
        $a = MailFixtures::classified($this->recorder, 'a1', 'rfq', 0.9, at: '2026-10-10 09:00:00')->threadId;
        $b = MailFixtures::classified($this->recorder, 'a2', 'not_rfq', 0.8, at: '2026-10-12 09:00:00')->threadId;
        MailFixtures::classified($this->recorder, 'a3', 'uncertain', 0.4, at: '2026-10-14 09:00:00');
        $this->json('POST', '/api/v1/mail/threads/' . $a . '/review', ['action' => 'confirm_label']);
        $this->json('POST', '/api/v1/mail/threads/' . $b . '/review', ['action' => 'correct_label', 'label' => 'rfq']);

        $data = $this->data(self::PATH);
        self::assertSame(3, $data['total']);
        self::assertSame(2, $data['labelled']);
        self::assertSame(1, $data['unlabelled']);
        self::assertSame(1, self::at($data, 'matrix', 'not_rfq', 'rfq'));
        self::assertSame(0.5, self::at($data, 'rfqRecall', 'rate'));
        self::assertEquals(1.0, self::at($data, 'missedRfqRate', 'rate'));
        self::assertNull(self::at($data, 'range', 'from'));

        $ranged = $this->data(self::PATH . '?from=2026-10-11&to=2026-10-13');
        self::assertSame(['total' => 1, 'labelled' => 1], ['total' => $ranged['total'], 'labelled' => $ranged['labelled']]);
        self::assertSame('2026-10-11T00:00:00.000Z', self::at($ranged, 'range', 'from'));
        [$status, $json] = $this->json('GET', self::PATH . '?from=nope');
        self::assertSame(422, $status);
        self::assertFalse(TestAppFactory::json($this->call('GET', self::PATH . '?from=nope'))['success']);
        unset($json);
    }

    public function test_a_decision_on_an_earlier_assessment_counts_as_unlabelled(): void
    {
        $this->boot(self::reviewer());
        $first = MailFixtures::classified($this->recorder, 'c1', 'rfq', 0.9, conversation: 'conv', at: '2026-10-10 09:00:00')->threadId;
        $this->json('POST', '/api/v1/mail/threads/' . $first . '/review', ['action' => 'confirm_label']);
        self::assertSame(1, $this->data(self::PATH)['labelled']);

        MailFixtures::classified($this->recorder, 'c2', 'not_rfq', 0.9, conversation: 'conv', at: '2026-10-11 09:00:00');
        $data = $this->data(self::PATH);
        self::assertSame(1, $data['total']);
        self::assertSame(0, $data['labelled']);
    }
}
