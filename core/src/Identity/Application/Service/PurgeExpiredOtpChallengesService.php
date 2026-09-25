<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use TriCab\Core\Identity\Contract\ExpiredEmailOtpChallengePurger;
use TriCab\Core\Shared\Time\Clock;

/**
 * Clears email sign-in challenges more than {@see RETENTION_DAYS} old (5.3).
 *
 * Ported from the half of the source's nightly job that was never switched
 * off: `DELETE FROM otp_challenges WHERE created_at < now() - interval '7 days'`.
 * Seven days keeps a week of rows for anyone investigating a sign-in complaint;
 * a challenge itself lives for minutes. A row must also have expired, so no
 * clock skew can remove a code somebody is about to type.
 *
 * Dry run unless told otherwise. Deletes in batches so one run on a table that
 * has never been cleared does not hold a long lock on the sign-in path.
 */
final readonly class PurgeExpiredOtpChallengesService
{
    public const RETENTION_DAYS = 7;
    public const BATCH_SIZE = 500;
    private const MAX_BATCHES = 200;

    public function __construct(
        private ExpiredEmailOtpChallengePurger $purger,
        private Clock $clock,
    ) {
    }

    /**
     * @return array{dryRun: bool, retentionDays: int, cutoffUtc: string, eligible: int, deleted: int}
     */
    public function run(bool $dryRun = true): array
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $cutoff = $now->modify(sprintf('-%d days', self::RETENTION_DAYS));
        $eligible = $this->purger->countPurgeable($cutoff, $now);

        $deleted = 0;
        if (!$dryRun) {
            for ($batch = 0; $batch < self::MAX_BATCHES; ++$batch) {
                $removed = $this->purger->purge($cutoff, $now, self::BATCH_SIZE);
                $deleted += $removed;
                if ($removed < self::BATCH_SIZE) {
                    break;
                }
            }
        }

        return [
            'dryRun' => $dryRun,
            'retentionDays' => self::RETENTION_DAYS,
            'cutoffUtc' => $cutoff->format('Y-m-d\TH:i:s\Z'),
            'eligible' => $eligible,
            'deleted' => $deleted,
        ];
    }
}
