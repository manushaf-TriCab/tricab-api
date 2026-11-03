<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\Service;

use TriCab\Core\Mail\Application\DTO\ExportQuery;
use TriCab\Core\Mail\Application\DTO\ExportRow;
use TriCab\Core\Mail\Application\DTO\MailTime;
use TriCab\Core\Mail\Contract\MailStore;
use TriCab\Core\Mail\Domain\Exception\MailActionForbidden;
use TriCab\Core\Mail\Domain\MailActor;
use TriCab\Core\Mail\Domain\MailVocabulary as V;
use TriCab\Core\Mail\Domain\Service\MailAccessPolicy;

/**
 * The numbers for the RFQ mail accuracy gate (RFQ-MAIL-INTAKE-PLAN M5.3), from the labelled set only.
 *
 * One row per assessed thread, as in the export. A thread is *labelled* when a person marked its label
 * (`confirm` or `correct`) on the thread's current assessment; the human label is that decision's label.
 * A decision made before a later message in the thread was classified is stale and counts as unlabelled,
 * because it judged an answer the classifier no longer stands behind. Pre-filter skips are included:
 * a skipped thread is a `not_rfq` the system acted on, so a skipped RFQ is a missed RFQ.
 *
 * Gate numbers (`rate` is null while the denominator is 0):
 *  - `rfqRecall`: of the threads a person labelled `rfq` or `rfq_followup`, the share the classifier
 *    labelled `rfq`, `rfq_followup` or `uncertain` (anything that still reaches a person). Target at least 98%.
 *  - `rfqPrecision`: of the threads the classifier labelled `rfq` (and read, not skipped), the share a
 *    person labelled `rfq`. A follow-up is not a new RFQ, so it does not count as right. Target at least 90%.
 *  - `missedRfqRate`: of the threads the classifier labelled `not_rfq` (skips included), the share a person
 *    labelled `rfq` or `rfq_followup`. It is the complement of recall's miss, seen from the other side. No target.
 *  - `uncertainShare`: of the labelled threads, the share the classifier labelled `uncertain`. Target at
 *    most 10%. `uncertainShareAll` is the same over every assessed thread, labelled or not, so a labelling
 *    order that favours uncertain mail cannot hide it.
 *
 * A thread a person labelled `uncertain` has no answer to compare with: it is counted as labelled and
 * shown in the matrix, but left out of every rate (`humanUncertain`).
 */
final class MailAccuracyService
{
    public const TARGET_RECALL = 0.98;
    public const TARGET_PRECISION = 0.90;
    public const TARGET_UNCERTAIN_MAX = 0.10;

    public function __construct(private readonly MailStore $store)
    {
    }

    /** @return array<string, mixed> `MailAccuracy` */
    public function accuracy(MailActor $actor, ExportQuery $query): array
    {
        if (!MailAccessPolicy::canExport($actor)) {
            throw new MailActionForbidden('You do not have permission to see the accuracy numbers.');
        }

        return self::compute($this->store->exportRows(new ExportQuery(false, $query->from, $query->to)), $query);
    }

    /**
     * @param list<ExportRow> $rows
     *
     * @return array<string, mixed>
     */
    public static function compute(array $rows, ExportQuery $range): array
    {
        $matrix = [];
        foreach (V::LABELS as $predicted) {
            foreach (V::LABELS as $human) {
                $matrix[$predicted][$human] = 0;
            }
        }
        $labelled = 0;
        $skippedLabelled = 0;
        $uncertainPredicted = 0;
        $uncertainPredictedAll = 0;
        $humanUncertain = 0;
        $recallHit = $recallTotal = 0;
        $precisionHit = $precisionTotal = 0;
        $missed = $notRfqTotal = 0;
        foreach ($rows as $row) {
            if ($row->label === V::LABEL_UNCERTAIN) {
                ++$uncertainPredictedAll;
            }
            if ($row->decidedLabel === null || $row->decisionStale) {
                continue;
            }
            ++$labelled;
            $matrix[$row->label][$row->decidedLabel]++;
            if ($row->skipped) {
                ++$skippedLabelled;
            }
            if ($row->label === V::LABEL_UNCERTAIN) {
                ++$uncertainPredicted;
            }
            if ($row->decidedLabel === V::LABEL_UNCERTAIN) {
                ++$humanUncertain;
                continue;
            }
            $humanRfq = in_array($row->decidedLabel, [V::LABEL_RFQ, V::LABEL_RFQ_FOLLOWUP], true);
            if ($humanRfq) {
                ++$recallTotal;
                if ($row->label !== V::LABEL_NOT_RFQ) {
                    ++$recallHit;
                }
            }
            if ($row->label === V::LABEL_RFQ && !$row->skipped) {
                ++$precisionTotal;
                if ($row->decidedLabel === V::LABEL_RFQ) {
                    ++$precisionHit;
                }
            }
            if ($row->label === V::LABEL_NOT_RFQ) {
                ++$notRfqTotal;
                if ($humanRfq) {
                    ++$missed;
                }
            }
        }
        $total = count($rows);

        return [
            'range' => [
                'from' => $range->from === null ? null : MailTime::iso($range->from),
                'to' => $range->to === null ? null : MailTime::iso($range->to),
            ],
            'total' => $total,
            'labelled' => $labelled,
            'unlabelled' => $total - $labelled,
            'skippedLabelled' => $skippedLabelled,
            'humanUncertain' => $humanUncertain,
            'matrix' => $matrix,
            'rfqRecall' => self::metric($recallHit, $recallTotal, self::TARGET_RECALL, true),
            'rfqPrecision' => self::metric($precisionHit, $precisionTotal, self::TARGET_PRECISION, true),
            'missedRfqRate' => self::metric($missed, $notRfqTotal, null, false),
            'uncertainShare' => self::metric($uncertainPredicted, $labelled, self::TARGET_UNCERTAIN_MAX, false),
            'uncertainShareAll' => self::metric($uncertainPredictedAll, $total, self::TARGET_UNCERTAIN_MAX, false),
        ];
    }

    /**
     * @return array{count: int, of: int, rate: float|null, target: float|null, met: bool|null}
     */
    private static function metric(int $count, int $of, ?float $target, bool $atLeast): array
    {
        $rate = $of === 0 ? null : $count / $of;
        // Compared on whole counts, so 49 of 50 is not "met" at 98% through a rounding of the rate.
        $met = $target === null || $of === 0
            ? null
            : ($atLeast ? $count >= $target * $of - 1e-9 : $count <= $target * $of + 1e-9);

        return ['count' => $count, 'of' => $of, 'rate' => $rate === null ? null : round($rate, 4), 'target' => $target, 'met' => $met];
    }
}
