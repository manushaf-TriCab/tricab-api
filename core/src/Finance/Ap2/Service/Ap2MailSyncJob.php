<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap2\Domain\Ap2Conflict;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Domain\Ap2MailCutoffUnset;
use TriCab\Core\Finance\Shared\Jobs\FinanceJob;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobSchedule;

/**
 * The scheduled mail sync: every 60 minutes, with no person. Registered only when FINANCE_AP2_MAIL_SYNC_ENABLED is true
 * (default false: Finance has not agreed to a schedule yet and the cost per email is not measured).
 */
final class Ap2MailSyncJob implements FinanceJob
{
    public const NAME = 'ap2.mail-sync';

    public function __construct(private readonly Ap2MailSyncService $sync)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function schedule(): FinanceJobSchedule
    {
        return FinanceJobSchedule::everyMinutes(60);
    }

    public function run(DateTimeImmutable $now): array
    {
        try {
            $answer = $this->sync->sync(null);
        } catch (Ap2MailCutoffUnset) {
            return ['skipped' => 'the mail cutoff date is not set'];
        } catch (Ap2Conflict $failure) {
            if (isset($failure->extra['runId'])) {
                // A person's sync is running: this tick has nothing to do.
                $running = $failure->extra['runId'];

                return ['skipped' => 'a sync is already running', 'runId' => is_string($running) ? $running : null];
            }

            throw new \RuntimeException($failure->getMessage(), 0, $failure);
        } catch (Ap2Failure $failure) {
            // The run is already recorded as failed; let the runner record the job's failure too.
            throw new \RuntimeException($failure->getMessage(), 0, $failure);
        }

        return $answer;
    }
}
