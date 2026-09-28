<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Infrastructure;

use TriCab\Core\Rfq\Application\DTO\RfqDetail;
use TriCab\Core\Rfq\Application\DTO\RfqQueueQuery;
use TriCab\Core\Rfq\Application\DTO\RfqRecord;
use TriCab\Core\Rfq\Application\DTO\RfqSummary;
use TriCab\Core\Rfq\Contract\RfqRepository;
use TriCab\Core\Rfq\Domain\Service\RfqAccessPolicy;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;

/**
 * The RFQ store until the Phase 2 schema lands — and, after it, the store the
 * tests use.
 *
 * Empty unless a test seeds it, so the endpoints answer an empty queue rather
 * than inventing one. Its filters are the rules `AzureSqlRfqRepository`
 * reproduces in SQL.
 */
final class InMemoryRfqRepository implements RfqRepository
{
    /** @var array<string, RfqRecord> */
    private array $records = [];

    public function withRecord(RfqRecord $record): self
    {
        $this->records[$record->publicId] = $record;

        return $this;
    }

    public function pageVisibleTo(RfqActor $actor, RfqQueueQuery $query): array
    {
        $visible = array_values(array_filter(
            $this->records,
            fn (RfqRecord $r): bool => RfqAccessPolicy::canSee($actor, $r->ownership) && $this->matches($actor, $r, $query),
        ));

        usort($visible, static fn (RfqRecord $a, RfqRecord $b): int => [$a->priority->rank(), $b->receivedAtUtc] <=> [$b->priority->rank(), $a->receivedAtUtc]);

        return [
            'items' => array_slice($visible, ($query->page - 1) * $query->pageSize, $query->pageSize),
            'total' => count($visible),
        ];
    }

    public function findRecord(string $publicId): ?RfqRecord
    {
        return $this->records[$publicId] ?? null;
    }

    public function branches(): array
    {
        $branches = [];
        foreach ($this->records as $record) {
            $branch = strtoupper(trim((string) $record->ownership->branchCode));
            if ($branch !== '') {
                $branches[$branch] = true;
            }
        }
        $branches = array_keys($branches);
        sort($branches);

        return array_map(strval(...), $branches);
    }

    public function findDetail(RfqRecord $record, array $allowedActions): RfqDetail
    {
        return new RfqDetail(new RfqSummary($record, $allowedActions), null, [], [], []);
    }

    private function matches(RfqActor $actor, RfqRecord $r, RfqQueueQuery $q): bool
    {
        $archived = $r->ownership->archived;
        if ($q->archived === RfqQueueQuery::ARCHIVED_EXCLUDE && $archived) {
            return false;
        }
        if ($q->archived === RfqQueueQuery::ARCHIVED_ONLY && !$archived) {
            return false;
        }
        if ($q->mineOnly && !RfqAccessPolicy::isMine($actor, $r->ownership)) {
            return false;
        }
        if ($q->branches !== []) {
            $branch = strtoupper(trim((string) $r->ownership->branchCode));
            if (!in_array($branch === '' ? RfqQueueQuery::BRANCH_NONE : $branch, $q->branches, true)) {
                return false;
            }
        }
        if ($q->statuses !== [] && !$this->inList($r->workflowStatus->toString(), $q->statuses)) {
            return false;
        }
        if ($q->priorities !== [] && !$this->inList($r->priority->toString(), $q->priorities)) {
            return false;
        }
        if ($q->search !== '') {
            $haystack = mb_strtolower(implode(' ', array_filter([
                $r->reference,
                $r->title,
                $r->customerCode,
                $r->customerName,
                $r->quoteNumber,
            ])));
            if (!str_contains($haystack, mb_strtolower($q->search))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<\TriCab\Core\Rfq\Domain\ValueObject\Vocabulary> $values
     */
    private function inList(string $value, array $values): bool
    {
        foreach ($values as $candidate) {
            if ($candidate->is($value)) {
                return true;
            }
        }

        return false;
    }
}
