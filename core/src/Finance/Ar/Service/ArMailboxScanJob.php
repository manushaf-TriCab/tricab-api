<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Service;

use DateTimeImmutable;
use TriCab\Core\Finance\Ar\Domain\ArConflict;
use TriCab\Core\Finance\Shared\Jobs\FinanceJob;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobSchedule;

/**
 * Tri-AR's 08:00 Melbourne scan (the source's timer, decision D44), as a registered job: the watermark scan with no
 * person. If an operator's scan holds the mailbox the pass is skipped, not failed, and the next day's catches up from
 * the watermark. Safe to repeat: known mail is skipped.
 */
final class ArMailboxScanJob implements FinanceJob
{
    public const NAME = 'ar.mailbox-scan';

    public function __construct(private readonly ArScanService $scan)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function schedule(): FinanceJobSchedule
    {
        return FinanceJobSchedule::dailyAt('08:00');
    }

    public function run(DateTimeImmutable $now): array
    {
        try {
            $answer = $this->scan->scanFromWatermark(null);
        } catch (ArConflict $busy) {
            return ['skipped' => 'another scan is running'];
        }

        return [
            'fetched' => $answer['total_fetched'],
            'new' => $answer['new_records'],
            'skipped' => $answer['skipped_records'],
            'failedEnrichments' => $answer['failed_enrichments'],
            'attachmentsKept' => $answer['attachments_kept'],
            'attachmentsExtracted' => $answer['attachments_extracted'],
            'attachmentFailures' => $answer['attachment_failures'],
        ];
    }
}
