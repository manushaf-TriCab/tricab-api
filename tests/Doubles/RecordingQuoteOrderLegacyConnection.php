<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Doubles;

use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;

/**
 * Records every legacy quote/order query. The order lookup answers with a
 * synthetic order whose quote is never returned; every other query is empty.
 */
final class RecordingQuoteOrderLegacyConnection implements LegacyReadOnlyConnection
{
    /** @var list<array{sql: string, params: array<string, scalar|null>, method: string}> */
    public array $queries = [];

    private readonly LegacyReadOnlySqlGuard $guard;

    public function __construct()
    {
        $this->guard = new LegacyReadOnlySqlGuard();
    }

    /**
     * @param array<string, scalar|null> $params
     * @return list<array<string, mixed>>
     */
    public function query(
        string $sql,
        array $params = [],
        string $repository = 'unknown',
        string $method = 'unknown',
    ): array {
        $this->guard->assertReadOnly($sql);
        $this->queries[] = ['sql' => $sql, 'params' => $params, 'method' => $method];

        return match ($method) {
            'searchCount' => [['cnt' => 0]],
            'findOrderById' => [[
                'po_id' => '9002',
                'customer_po_no' => 'PO-9002',
                'customer_id' => '2',
                'quote_id' => '3002',
                'reference' => null,
                'comments' => null,
                'po_issue_date' => '2026-10-02',
            ]],
            default => [],
        };
    }

    public function sourceLabel(): string
    {
        return 'synthetic';
    }

    /**
     * @param list<string> $methods
     * @return list<array{sql: string, params: array<string, scalar|null>, method: string}>
     */
    public function queriesFor(array $methods): array
    {
        return array_values(array_filter(
            $this->queries,
            static fn (array $query): bool => in_array($query['method'], $methods, true),
        ));
    }
}
