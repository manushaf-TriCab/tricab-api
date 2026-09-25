<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Infrastructure;

use DateTimeImmutable;
use TriCab\Core\Onboarding\Application\DTO\Report\ItemComplianceRow;
use TriCab\Core\Onboarding\Application\DTO\Report\OnboardingReportSummary;
use TriCab\Core\Onboarding\Application\DTO\Report\OverdueWorkRow;
use TriCab\Core\Onboarding\Contract\OnboardingReportRepository;
use TriCab\Core\Onboarding\Domain\Service\LeaverPolicy;
use TriCab\Core\Onboarding\Domain\Service\OverduePolicy;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

/**
 * Test double and the container default, as the other Onboarding repositories
 * are.
 *
 * Holds raw facts — people, items, live assignments — and works the reports out
 * with {@see LeaverPolicy} and {@see OverduePolicy}, the same rules the SQL
 * mirrors. A double that stored finished report rows would test nothing.
 * Withdrawn assignments are simply never added: the SQL's
 * `[WithdrawnAtUtc] IS NULL` has nothing to filter here.
 */
final class InMemoryOnboardingReportRepository implements OnboardingReportRepository
{
    /** @var array<int, array{personPublicId: string, displayName: ?string, invited: bool, departedAtUtc: ?DateTimeImmutable}> */
    private array $people = [];

    /** @var array<string, array{itemType: 'training'|'policy'|'form', itemPublicId: string, title: string, offered: bool}> */
    private array $items = [];

    /** @var list<array{assignmentPublicId: string, userId: int, itemKey: string, status: AssignmentStatus, dueDate: ?string}> */
    private array $assignments = [];

    public function withPerson(int $userId, string $personPublicId, ?string $displayName, bool $invited = true, ?DateTimeImmutable $departedAtUtc = null): void
    {
        $this->people[$userId] = [
            'personPublicId' => $personPublicId,
            'displayName' => $displayName,
            'invited' => $invited,
            'departedAtUtc' => $departedAtUtc,
        ];
    }

    /**
     * @param 'training'|'policy'|'form' $itemType
     */
    public function withItem(string $itemType, string $itemPublicId, string $title, bool $offered = true): void
    {
        $this->items[$itemType . ':' . $itemPublicId] = [
            'itemType' => $itemType,
            'itemPublicId' => $itemPublicId,
            'title' => $title,
            'offered' => $offered,
        ];
    }

    /**
     * @param 'training'|'policy'|'form' $itemType
     */
    public function withAssignment(string $assignmentPublicId, int $userId, string $itemType, string $itemPublicId, AssignmentStatus $status, ?string $dueDate = null): void
    {
        $this->assignments[] = [
            'assignmentPublicId' => $assignmentPublicId,
            'userId' => $userId,
            'itemKey' => $itemType . ':' . $itemPublicId,
            'status' => $status,
            'dueDate' => $dueDate,
        ];
    }

    public function summary(DateTimeImmutable $nowUtc, string $today): OnboardingReportSummary
    {
        $current = $this->currentUserIds($nowUtc);
        $byStatus = [];
        $overdue = 0;
        $liveByUser = [];
        $doneByUser = [];
        $waiting = 0;
        $help = 0;

        foreach ($this->assignments as $a) {
            // The queue counts are everybody's, as the reviewer queue lists them.
            $waiting += $a['status']->equals(AssignmentStatus::submitted()) ? 1 : 0;
            $help += $a['status']->equals(AssignmentStatus::needHelp()) ? 1 : 0;

            if (!isset($current[$a['userId']])) {
                continue;
            }
            $code = $a['status']->toString();
            $byStatus[$code] = ($byStatus[$code] ?? 0) + 1;
            $overdue += OverduePolicy::isOverdue($a['status'], $a['dueDate'], $today) ? 1 : 0;
            $liveByUser[$a['userId']] = ($liveByUser[$a['userId']] ?? 0) + 1;
            $doneByUser[$a['userId']] = ($doneByUser[$a['userId']] ?? 0) + ($a['status']->isFinal() ? 1 : 0);
        }

        $notInvited = 0;
        $nothing = 0;
        $finished = 0;
        foreach (array_keys($current) as $userId) {
            $notInvited += $this->people[$userId]['invited'] ? 0 : 1;
            $live = $liveByUser[$userId] ?? 0;
            $nothing += $live === 0 ? 1 : 0;
            $finished += $live > 0 && $live === ($doneByUser[$userId] ?? 0) ? 1 : 0;
        }

        return new OnboardingReportSummary(
            $today,
            count($current),
            count($this->people) - count($current),
            $notInvited,
            $nothing,
            $finished,
            $byStatus,
            $overdue,
            $waiting,
            $help,
        );
    }

    public function itemCompliance(DateTimeImmutable $nowUtc, string $today, int $page, int $pageSize): array
    {
        $current = $this->currentUserIds($nowUtc);
        $counts = [];
        foreach ($this->assignments as $a) {
            if (!isset($current[$a['userId']])) {
                continue;
            }
            $c = $counts[$a['itemKey']] ?? ['assigned' => 0, 'completed' => 0, 'waiting' => 0, 'inProgress' => 0, 'notStarted' => 0, 'overdue' => 0];
            ++$c['assigned'];
            $status = $a['status'];
            match (true) {
                $status->isFinal() => ++$c['completed'],
                $status->equals(AssignmentStatus::submitted()) => ++$c['waiting'],
                $status->equals(AssignmentStatus::notStarted()) => ++$c['notStarted'],
                default => ++$c['inProgress'],
            };
            $c['overdue'] += OverduePolicy::isOverdue($status, $a['dueDate'], $today) ? 1 : 0;
            $counts[$a['itemKey']] = $c;
        }

        $rows = [];
        foreach ($this->items as $key => $item) {
            $c = $counts[$key] ?? null;
            if ($c === null && !$item['offered']) {
                continue;
            }
            $rows[] = new ItemComplianceRow(
                $item['itemType'],
                $item['itemPublicId'],
                $item['title'],
                $item['offered'],
                $c['assigned'] ?? 0,
                $c['completed'] ?? 0,
                $c['waiting'] ?? 0,
                $c['inProgress'] ?? 0,
                $c['notStarted'] ?? 0,
                $c['overdue'] ?? 0,
            );
        }
        usort($rows, static fn (ItemComplianceRow $x, ItemComplianceRow $y): int
            => [$x->itemType, $x->title, $x->itemPublicId] <=> [$y->itemType, $y->title, $y->itemPublicId]);

        return ['items' => array_slice($rows, ($page - 1) * $pageSize, $pageSize), 'total' => count($rows)];
    }

    public function overdueWork(DateTimeImmutable $nowUtc, string $today, int $page, int $pageSize): array
    {
        $current = $this->currentUserIds($nowUtc);
        $rows = [];
        foreach ($this->assignments as $a) {
            if (!isset($current[$a['userId']]) || $a['dueDate'] === null || !OverduePolicy::isOverdue($a['status'], $a['dueDate'], $today)) {
                continue;
            }
            $person = $this->people[$a['userId']];
            $item = $this->items[$a['itemKey']];
            $rows[] = [
                'sort' => [$a['dueDate'], $person['displayName'] ?? '', $a['assignmentPublicId']],
                'row' => new OverdueWorkRow(
                    $person['personPublicId'],
                    $person['displayName'],
                    $item['itemType'],
                    $item['itemPublicId'],
                    $item['title'],
                    $a['status'],
                    $a['dueDate'],
                    OverduePolicy::daysOverdue($a['dueDate'], $today),
                ),
            ];
        }
        usort($rows, static fn (array $x, array $y): int => $x['sort'] <=> $y['sort']);

        return [
            'items' => array_map(static fn (array $r): OverdueWorkRow => $r['row'], array_slice($rows, ($page - 1) * $pageSize, $pageSize)),
            'total' => count($rows),
        ];
    }

    /**
     * @return array<int, true>
     */
    private function currentUserIds(DateTimeImmutable $nowUtc): array
    {
        $current = [];
        foreach ($this->people as $userId => $person) {
            if (!LeaverPolicy::hasDeparted($person['departedAtUtc'], $nowUtc)) {
                $current[$userId] = true;
            }
        }

        return $current;
    }
}
