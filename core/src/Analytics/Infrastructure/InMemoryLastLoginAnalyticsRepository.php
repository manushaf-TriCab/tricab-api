<?php

declare(strict_types=1);

namespace TriCab\Core\Analytics\Infrastructure;

use DateTimeImmutable;
use TriCab\Core\Analytics\Contract\LastLoginAnalyticsRepository;
use TriCab\Core\Analytics\Domain\ReportingCalendar;

final class InMemoryLastLoginAnalyticsRepository implements LastLoginAnalyticsRepository
{
    /** @var array<int, array{at: DateTimeImmutable, isStaff: bool}> */
    private array $logins = [];

    public function record(int $userId, DateTimeImmutable $at, bool $isStaff): void
    {
        $existing = $this->logins[$userId] ?? null;
        if ($existing !== null && $existing['at'] >= $at) {
            return;
        }
        $this->logins[$userId] = ['at' => $at, 'isStaff' => $isStaff];
    }

    public function firstLoginDate(): ?string
    {
        if ($this->logins === []) {
            return null;
        }
        $dates = array_map(
            static fn (array $row): string => ReportingCalendar::localDate($row['at']),
            $this->logins,
        );

        return min($dates);
    }

    public function liveTotals(DateTimeImmutable $sinceUtc): array
    {
        return $this->counts(array_filter(
            $this->logins,
            static fn (array $row): bool => $row['at'] >= $sinceUtc,
        ));
    }

    public function dailyLastLogins(string $fromDate, string $toDate): array
    {
        $grouped = [];
        foreach ($this->logins as $row) {
            $date = ReportingCalendar::localDate($row['at']);
            if ($date >= $fromDate && $date <= $toDate) {
                $grouped[$date][] = $row;
            }
        }
        ksort($grouped);
        $rows = [];
        foreach ($grouped as $date => $members) {
            $rows[] = ['bucket' => (string) $date] + $this->counts($members);
        }

        return $rows;
    }

    public function hourlyLastLogins(DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array
    {
        $grouped = [];
        foreach ($this->logins as $row) {
            $hour = ReportingCalendar::hourStartUtc($row['at']);
            if ($hour >= $fromUtc && $hour <= $toUtc) {
                $grouped[$hour->format('Y-m-d\TH:i:s')][] = $row;
            }
        }
        ksort($grouped);
        $rows = [];
        foreach ($grouped as $bucket => $members) {
            $rows[] = ['bucket' => (string) $bucket] + $this->counts($members);
        }

        return $rows;
    }

    /**
     * @param array<int, array{at: DateTimeImmutable, isStaff: bool}> $members
     * @return array{total: int, web: null, mobile: null, staff: int, customer: int}
     */
    private function counts(array $members): array
    {
        $staff = 0;
        foreach ($members as $row) {
            if ($row['isStaff']) {
                $staff++;
            }
        }

        return [
            'total' => count($members),
            'web' => null,
            'mobile' => null,
            'staff' => $staff,
            'customer' => count($members) - $staff,
        ];
    }
}
