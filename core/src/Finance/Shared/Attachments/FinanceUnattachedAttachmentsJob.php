<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Attachments;

use DateTimeImmutable;
use TriCab\Core\Finance\Shared\Jobs\FinanceJob;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobSchedule;

/**
 * Removes uploads that were never put on a record (a form abandoned after the file
 * went up). Written and tested in Phase 1 but **not registered**: the job registry
 * stays empty until an app stores files, and that app's phase adds this line to it
 * (`ContainerFactory.php`, the `FinanceJobRegistry` definition).
 */
final class FinanceUnattachedAttachmentsJob implements FinanceJob
{
    public const NAME = 'finance.attachments.sweep-unattached';

    public function __construct(
        private readonly FinanceAttachmentStore $store,
        private readonly int $olderThanHours = 24,
        private readonly int $limit = 500,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function schedule(): FinanceJobSchedule
    {
        return FinanceJobSchedule::dailyAt('03:30');
    }

    public function run(DateTimeImmutable $now): array
    {
        $deleted = $this->store->deleteUnattachedBefore($now->modify('-' . $this->olderThanHours . ' hours'), $this->limit);

        return ['deleted' => $deleted];
    }
}
