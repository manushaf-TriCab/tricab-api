<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

use DateTimeImmutable;
use TriCab\Core\Onboarding\Application\DTO\Report\ItemComplianceRow;
use TriCab\Core\Onboarding\Application\DTO\Report\OnboardingReportSummary;
use TriCab\Core\Onboarding\Application\DTO\Report\OverdueWorkRow;

/**
 * Operational reports (4.5). Read-only, and aggregate or name-only by
 * construction: nothing here returns an email address, a score, a note, an
 * answer, or anything from `[onboarding_hr]`.
 *
 * "Current" means has an induction record and has not yet left
 * ({@see \TriCab\Core\Onboarding\Domain\Service\LeaverPolicy::hasDeparted()}
 * at `$nowUtc`). "Overdue" is
 * {@see \TriCab\Core\Onboarding\Domain\Service\OverduePolicy::isOverdue()} at
 * `$today`, a Melbourne date. Both are passed in rather than read from the
 * database clock, so a test can pin them.
 */
interface OnboardingReportRepository
{
    public function summary(DateTimeImmutable $nowUtc, string $today): OnboardingReportSummary;

    /**
     * Every item a current person holds, plus every item HR could allocate
     * today (active, with a published live version) even if nobody holds it.
     * Ordered by item type, title, then item public id.
     *
     * @return array{items: list<ItemComplianceRow>, total: int}
     */
    public function itemCompliance(DateTimeImmutable $nowUtc, string $today, int $page, int $pageSize): array;

    /**
     * Current people's overdue work, longest overdue first, then by name, then
     * by assignment — a total order, so a page boundary never moves.
     *
     * @return array{items: list<OverdueWorkRow>, total: int}
     */
    public function overdueWork(DateTimeImmutable $nowUtc, string $today, int $page, int $pageSize): array;
}
