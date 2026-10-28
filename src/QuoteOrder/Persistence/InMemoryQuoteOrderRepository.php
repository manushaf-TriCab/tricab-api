<?php

declare(strict_types=1);

namespace TriCab\Api\QuoteOrder\Persistence;

use TriCab\Api\QuoteOrder\Mapping\LegacyQuoteOrderMapper;
use TriCab\Core\QuoteOrder\Application\DTO\OrderDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteItemDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderSearchCriteria;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderSearchResult;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderSummary;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderVisibilityScope;
use TriCab\Core\QuoteOrder\Contract\QuoteOrderRepository;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteOrderType;

/**
 * In-memory fixture repository for unit/HTTP tests. No MySQL writes.
 */
final class InMemoryQuoteOrderRepository implements QuoteOrderRepository
{
    /** @var list<QuoteOrderSummary> */
    private array $summaries = [];

    /** @var array<string, QuoteDetails> */
    private array $quotes = [];

    /** @var array<string, OrderDetails> */
    private array $orders = [];

    public function __construct(
        private readonly LegacyQuoteOrderMapper $mapper = new LegacyQuoteOrderMapper(),
    ) {
    }

    public function seedSummary(QuoteOrderSummary $summary): void
    {
        $this->summaries[] = $summary;
    }

    public function seedQuote(QuoteDetails $quote): void
    {
        $this->quotes[$quote->quoteId] = $quote;
        // When a quote is linked to an order, seedOrder() owns the list row (type=order).
        if ($quote->orderId === null || $quote->orderId === '') {
            $this->summaries[] = $quote->toSummary();
        }
    }

    public function seedOrder(OrderDetails $order): void
    {
        $this->orders[$order->orderId] = $order;
        if ($order->quote !== null) {
            $summary = $order->quote->toSummary();
            $this->summaries[] = new QuoteOrderSummary(
                id: $order->orderId,
                type: QuoteOrderType::ORDER,
                quoteId: $summary->quoteId,
                orderId: $order->orderId,
                date: $order->issueDate ?? $summary->date,
                raisedBy: $summary->raisedBy,
                representative: $summary->representative,
                customerCode: $summary->customerCode,
                customerName: $summary->customerName,
                reference: $order->customerPoNumber ?? $order->reference ?? $summary->reference,
                status: $summary->status,
                statusCode: $summary->statusCode,
                statusLabel: $summary->statusLabel,
                total: $summary->total,
                totalStatus: $summary->totalStatus,
                currency: $summary->currency,
            );
        }
    }

    /**
     * @param array<string, mixed> $header legacy-shaped header for mapper coverage
     * @param list<array{item: array<string, mixed>, packs?: list<array<string, mixed>>}> $lines
     */
    public function seedFromLegacyRow(array $header, array $lines = []): QuoteDetails
    {
        $items = [];
        foreach ($lines as $line) {
            $packs = [];
            foreach ($line['packs'] ?? [] as $pack) {
                $packs[] = $this->mapper->mapPack($pack);
            }
            $items[] = $this->mapper->mapItem($line['item'], $packs);
        }

        $details = $this->mapper->mapQuoteDetails($header, $items);
        $this->seedQuote($details);

        return $details;
    }

    public function search(
        QuoteOrderSearchCriteria $criteria,
        QuoteOrderVisibilityScope $scope,
    ): QuoteOrderSearchResult {
        $matched = array_values(array_filter(
            $this->summaries,
            fn (QuoteOrderSummary $item): bool => $this->matches($item, $criteria, $scope),
        ));

        usort(
            $matched,
            static function (QuoteOrderSummary $a, QuoteOrderSummary $b): int {
                $dateCmp = strcmp((string) $b->date, (string) $a->date);
                if ($dateCmp !== 0) {
                    return $dateCmp;
                }

                return strcmp($b->id, $a->id);
            },
        );

        $total = count($matched);
        $offset = ($criteria->page - 1) * $criteria->pageSize;
        $pageItems = array_slice($matched, $offset, $criteria->pageSize);

        return new QuoteOrderSearchResult(
            items: $pageItems,
            page: $criteria->page,
            pageSize: $criteria->pageSize,
            total: $total,
        );
    }

    public function findQuoteById(string $quoteId, QuoteOrderVisibilityScope $scope): ?QuoteDetails
    {
        $quote = $this->quotes[$quoteId] ?? null;
        if ($quote === null) {
            return null;
        }

        if (!$this->visibleCustomer($scope, $quote->customerCode)) {
            return null;
        }

        return $quote;
    }

    public function findOrderById(string $orderId, QuoteOrderVisibilityScope $scope): ?OrderDetails
    {
        $order = $this->orders[$orderId] ?? null;
        if ($order === null) {
            return null;
        }

        $code = $order->quote?->customerCode;
        if (!$this->visibleCustomer($scope, $code)) {
            return null;
        }

        return $order;
    }

    private function matches(
        QuoteOrderSummary $item,
        QuoteOrderSearchCriteria $criteria,
        QuoteOrderVisibilityScope $scope,
    ): bool {
        if (!$this->visibleCustomer($scope, $item->customerCode)) {
            return false;
        }

        $type = $criteria->typeFilter();
        if ($type->isQuote() && $item->type !== QuoteOrderType::QUOTE) {
            return false;
        }
        if ($type->isOrder() && $item->type !== QuoteOrderType::ORDER) {
            return false;
        }

        if ($criteria->customerCode !== null
            && !str_starts_with(strtoupper((string) $item->customerCode), strtoupper($criteria->customerCode))
        ) {
            return false;
        }

        if ($criteria->reference !== null) {
            $ref = strtoupper($criteria->reference);
            $hay = strtoupper(implode(' ', array_filter([
                $item->reference,
                $item->quoteId,
                $item->orderId,
                $item->id,
            ])));
            if (!str_contains($hay, $ref)) {
                return false;
            }
        }

        if ($criteria->representative !== null) {
            $needle = strtoupper($criteria->representative);
            $hay = strtoupper((string) $item->representative);
            if (!str_starts_with($hay, $needle) && !str_contains($hay, $needle)) {
                return false;
            }
        }

        if ($criteria->from !== null && (string) $item->date < $criteria->from) {
            return false;
        }

        if ($criteria->to !== null && (string) $item->date > $criteria->to) {
            return false;
        }

        if ($criteria->statusCode !== null && $item->statusCode !== $criteria->statusCode) {
            return false;
        }

        return true;
    }

    private function visibleCustomer(QuoteOrderVisibilityScope $scope, ?string $customerCode): bool
    {
        if ($scope->isUnrestricted()) {
            return true;
        }

        if ($scope->allowedCustomerCodes === []) {
            return false;
        }

        foreach ($scope->allowedCustomerCodes as $allowed) {
            if (strcasecmp($allowed, (string) $customerCode) === 0) {
                return true;
            }
        }

        return false;
    }
}
