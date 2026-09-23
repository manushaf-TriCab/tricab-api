<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Onboarding\Application\DTO\Report\ItemComplianceRow;
use TriCab\Core\Onboarding\Application\DTO\Report\OnboardingReportSummary;
use TriCab\Core\Onboarding\Application\DTO\Report\OverdueWorkRow;
use TriCab\Core\Onboarding\Contract\OnboardingReportRepository;
use TriCab\Core\Onboarding\Domain\Service\OverduePolicy;
use TriCab\Core\Shared\Time\Clock;

/**
 * The HR reports (4.5), and the overview's counts.
 *
 * Reads only, so nothing is audited: the audit table records state changes
 * (4.6), and a restricted read is owed its own row through the Phase 6
 * document route. Nothing these return is restricted — see
 * {@see OnboardingReportRepository}.
 *
 * One clock reading per call, turned into both the instant (who has left) and
 * the Melbourne date (what is overdue), so the two can never disagree about
 * what "now" is across midnight.
 */
final readonly class ReadOnboardingReportsService
{
    public const MAX_PAGE_SIZE = 100;

    public function __construct(
        private OnboardingReportRepository $reports,
        private Clock $clock,
    ) {
    }

    public function summary(): OnboardingReportSummary
    {
        $now = $this->clock->now();

        return $this->reports->summary($now, OverduePolicy::todayFor($now));
    }

    /**
     * @return array{items: list<ItemComplianceRow>, total: int, page: int, pageSize: int, asOf: string}
     */
    public function itemCompliance(int $page, int $pageSize): array
    {
        [$page, $pageSize] = self::bounded($page, $pageSize);
        $now = $this->clock->now();
        $today = OverduePolicy::todayFor($now);

        return [...$this->reports->itemCompliance($now, $today, $page, $pageSize), 'page' => $page, 'pageSize' => $pageSize, 'asOf' => $today];
    }

    /**
     * @return array{items: list<OverdueWorkRow>, total: int, page: int, pageSize: int, asOf: string}
     */
    public function overdueWork(int $page, int $pageSize): array
    {
        [$page, $pageSize] = self::bounded($page, $pageSize);
        $now = $this->clock->now();
        $today = OverduePolicy::todayFor($now);

        return [...$this->reports->overdueWork($now, $today, $page, $pageSize), 'page' => $page, 'pageSize' => $pageSize, 'asOf' => $today];
    }

    /**
     * @return array{int, int}
     */
    private static function bounded(int $page, int $pageSize): array
    {
        return [max(1, $page), min(self::MAX_PAGE_SIZE, max(1, $pageSize))];
    }
}
