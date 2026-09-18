<?php

declare(strict_types=1);

namespace TriCab\Api\QuoteOrder\Legacy;

use Throwable;
use TriCab\Api\QuoteOrder\Mapping\LegacyQuoteOrderMapper;
use TriCab\Api\QuoteOrder\Support\LegacyRowValue;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;
use TriCab\Core\QuoteOrder\Application\DTO\OrderDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteItemDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderSearchCriteria;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderSearchResult;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderVisibilityScope;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteOrderSourceUnavailable;
use TriCab\Core\QuoteOrder\Contract\QuoteOrderRepository;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteOrderType;

/**
 * READ-ONLY adapter over production tc.tqoth / tqoti / tqote / customer_orders.
 * Parameterized SELECT only; uses LegacyReadOnlySqlGuard.
 */
final class MysqlLegacyQuoteOrderRepository implements QuoteOrderRepository
{
    public function __construct(
        private readonly LegacyReadOnlyConnection $connection,
        private readonly LegacyReadOnlySqlGuard $guard,
        private readonly LegacyQuoteOrderMapper $mapper = new LegacyQuoteOrderMapper(),
    ) {
    }

    public function search(
        QuoteOrderSearchCriteria $criteria,
        QuoteOrderVisibilityScope $scope,
    ): QuoteOrderSearchResult {
        if ($scope->forcesEmptyResults()) {
            return new QuoteOrderSearchResult([], $criteria->page, $criteria->pageSize, 0);
        }

        [$whereSql, $params] = $this->buildSearchWhere($criteria, $scope);
        $type = $criteria->typeFilter();

        $from = $this->searchFromSql($type);

        $countSql = 'SELECT COUNT(*) AS cnt FROM ' . $from . $whereSql;
        $this->guard->assertReadOnly($countSql);
        $countRows = $this->safeQuery($countSql, $params, 'searchCount');
        $total = LegacyRowValue::int($countRows[0]['cnt'] ?? null);

        $offset = ($criteria->page - 1) * $criteria->pageSize;
        $limit = $criteria->pageSize;
        $listSql = 'SELECT '
            . 'h.id AS quote_id, '
            . 'h.de AS quote_date, '
            . 'h.tnet AS total_net, '
            . 'h.cy AS currency_id, '
            . 'h.st AS status_code, '
            . 'h.cref AS quote_reference, '
            . 'h.cus_gen AS cus_gen, '
            . 'c.code AS customer_code, '
            . 'c.name AS customer_name, '
            . 'ct.name AS contact_name, '
            . 'd.desc_name AS representative, '
            . 'o.po_id AS order_id, '
            . 'o.customer_po_no AS customer_po_no, '
            . 'o.reference AS order_reference, '
            . 'o.po_issue_date AS po_issue_date '
            . 'FROM ' . $from . $whereSql
            . ' ORDER BY h.de DESC, h.id DESC'
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;

        $this->guard->assertReadOnly($listSql);
        $rows = $this->safeQuery($listSql, $params, 'search');

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->mapper->mapSummary($row);
        }

        return new QuoteOrderSearchResult(
            items: $items,
            page: $criteria->page,
            pageSize: $criteria->pageSize,
            total: $total,
        );
    }

    public function findQuoteById(string $quoteId, QuoteOrderVisibilityScope $scope): ?QuoteDetails
    {
        if ($scope->forcesEmptyResults()) {
            return null;
        }

        $params = [':id' => $quoteId];
        [$visibilitySql, $visibilityParams] = $this->visibilitySql($scope, 'c');
        $params = array_merge($params, $visibilityParams);

        $sql = 'SELECT '
            . 'h.id AS quote_id, h.de AS quote_date, h.dvalid AS valid_date, h.tnet AS total_net, '
            . 'h.cy AS currency_id, h.st AS status_code, h.cref AS quote_reference, h.cus_gen AS cus_gen, '
            . 'c.code AS customer_code, c.name AS customer_name, '
            . 'ct.name AS contact_name, d.desc_name AS representative, '
            . 'o.po_id AS order_id, o.customer_po_no AS customer_po_no, o.reference AS order_reference '
            . 'FROM tc.tqoth h '
            . 'INNER JOIN tc.cust c ON h.cus = c.id '
            . 'LEFT JOIN tc.contacts ct ON h.cus_op = ct.contact_id '
            . 'LEFT JOIN tc.descript d ON h.sa_op = d.desc_id '
            . 'LEFT JOIN tc.customer_orders o ON o.quote_id = h.id '
            . 'WHERE h.id = :id' . $visibilitySql
            . ' LIMIT 1';

        $this->guard->assertReadOnly($sql);
        $rows = $this->safeQuery($sql, $params, 'findQuoteById');
        if ($rows === []) {
            return null;
        }

        $items = $this->loadItems(LegacyRowValue::string($rows[0]['quote_id'] ?? null));

        return $this->mapper->mapQuoteDetails($rows[0], $items);
    }

    public function findOrderById(string $orderId, QuoteOrderVisibilityScope $scope): ?OrderDetails
    {
        if ($scope->forcesEmptyResults()) {
            return null;
        }

        $params = [':po_id' => $orderId];
        $sql = 'SELECT po_id, customer_po_no, customer_id, quote_id, reference, comments, po_issue_date '
            . 'FROM tc.customer_orders WHERE po_id = :po_id LIMIT 1';
        $this->guard->assertReadOnly($sql);
        $rows = $this->safeQuery($sql, $params, 'findOrderById');
        if ($rows === []) {
            return null;
        }

        $quoteId = LegacyRowValue::string($rows[0]['quote_id'] ?? null);
        $quote = $quoteId !== '' ? $this->findQuoteById($quoteId, $scope) : null;
        if ($quote === null && $scope->isCustomerPortalScoped) {
            return null;
        }

        return $this->mapper->mapOrderDetails($rows[0], $quote);
    }

    /**
     * @return list<QuoteItemDetails>
     */
    private function loadItems(string $quoteId): array
    {
        $sql = 'SELECT id AS item_id, itm AS item_no, cata AS product_code, qotqty AS quantity, '
            . 'qty AS pack_count, nprc AS unit_price, ref AS item_reference '
            . 'FROM tc.tqoti WHERE qot = :qot AND cata NOT LIKE :zzdel ORDER BY itm ASC, id ASC';
        $params = [':qot' => $quoteId, ':zzdel' => '%ZZDEL%'];
        $this->guard->assertReadOnly($sql);
        $rows = $this->safeQuery($sql, $params, 'loadItems');

        $items = [];
        foreach ($rows as $row) {
            $packs = $this->loadPacks(LegacyRowValue::string($row['item_id'] ?? null));
            $items[] = $this->mapper->mapItem($row, $packs);
        }

        return $items;
    }

    /**
     * @return list<\TriCab\Core\QuoteOrder\Application\DTO\QuoteItemPackDetails>
     */
    private function loadPacks(string $itemId): array
    {
        $sql = 'SELECT id, pk, qty, comments FROM tc.tqote WHERE qoti = :qoti ORDER BY id ASC';
        $params = [':qoti' => $itemId];
        $this->guard->assertReadOnly($sql);
        $rows = $this->safeQuery($sql, $params, 'loadPacks');

        $packs = [];
        foreach ($rows as $row) {
            $packs[] = $this->mapper->mapPack($row);
        }

        return $packs;
    }

    private function searchFromSql(QuoteOrderType $type): string
    {
        $base = 'tc.tqoth h '
            . 'INNER JOIN tc.cust c ON h.cus = c.id '
            . 'LEFT JOIN tc.contacts ct ON h.cus_op = ct.contact_id '
            . 'LEFT JOIN tc.descript d ON h.sa_op = d.desc_id ';

        if ($type->isOrder()) {
            return $base . 'INNER JOIN tc.customer_orders o ON o.quote_id = h.id ';
        }

        if ($type->isQuote()) {
            return $base . 'LEFT JOIN tc.customer_orders o ON o.quote_id = h.id ';
        }

        return $base . 'LEFT JOIN tc.customer_orders o ON o.quote_id = h.id ';
    }

    /**
     * @return array{0:string,1:array<string, scalar|null>}
     */
    private function buildSearchWhere(
        QuoteOrderSearchCriteria $criteria,
        QuoteOrderVisibilityScope $scope,
    ): array {
        $clauses = [];
        $params = [];

        $type = $criteria->typeFilter();
        if ($type->isQuote()) {
            $clauses[] = 'o.po_id IS NULL';
        }

        if ($criteria->from !== null) {
            $clauses[] = 'h.de >= :from_date';
            $params[':from_date'] = $criteria->from;
        }

        if ($criteria->to !== null) {
            $clauses[] = 'h.de <= :to_date';
            $params[':to_date'] = $criteria->to;
        }

        // Intentional parity deviation: customerCode is independent of endDate.
        if ($criteria->customerCode !== null) {
            $clauses[] = 'c.code LIKE :customer_code';
            $params[':customer_code'] = $criteria->customerCode . '%';
        }

        if ($criteria->representative !== null) {
            $clauses[] = '(d.desc_name LIKE :rep_name OR c.rep LIKE :rep_code)';
            $params[':rep_name'] = $criteria->representative . '%';
            $params[':rep_code'] = $criteria->representative . '%';
        }

        if ($criteria->reference !== null) {
            if (is_numeric($criteria->reference)) {
                $clauses[] = '(CAST(h.id AS CHAR) LIKE :ref_id OR h.cref LIKE :ref_cref OR o.reference LIKE :ref_ord OR o.customer_po_no LIKE :ref_po)';
                $like = '%' . $criteria->reference . '%';
                $params[':ref_id'] = $like;
                $params[':ref_cref'] = $like;
                $params[':ref_ord'] = $like;
                $params[':ref_po'] = $like;
            } else {
                $clauses[] = '(h.cref LIKE :ref_cref OR o.reference LIKE :ref_ord OR o.customer_po_no LIKE :ref_po)';
                $like = '%' . $criteria->reference . '%';
                $params[':ref_cref'] = $like;
                $params[':ref_ord'] = $like;
                $params[':ref_po'] = $like;
            }
        }

        if ($criteria->statusCode !== null) {
            $clauses[] = 'h.st = :status_code';
            $params[':status_code'] = $criteria->statusCode;
        }

        [$visibilitySql, $visibilityParams] = $this->visibilitySql($scope, 'c');
        if ($visibilitySql !== '') {
            $clauses[] = ltrim($visibilitySql, ' AND');
            $params = array_merge($params, $visibilityParams);
        }

        $where = $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);

        return [$where, $params];
    }

    /**
     * @return array{0:string,1:array<string, scalar|null>}
     */
    private function visibilitySql(QuoteOrderVisibilityScope $scope, string $custAlias): array
    {
        if (!$scope->isCustomerPortalScoped) {
            return ['', []];
        }

        if ($scope->allowedCustomerCodes === []) {
            return [' AND 1 = 0', []];
        }

        $placeholders = [];
        $params = [];
        foreach ($scope->allowedCustomerCodes as $i => $code) {
            $name = ':vis_code_' . $i;
            $placeholders[] = $name;
            $params[$name] = $code;
        }

        return [
            ' AND ' . $custAlias . '.code IN (' . implode(',', $placeholders) . ')',
            $params,
        ];
    }

    /**
     * @param array<string, scalar|null> $params
     * @return list<array<string, mixed>>
     */
    private function safeQuery(string $sql, array $params, string $method): array
    {
        try {
            return $this->connection->query($sql, $params, 'MysqlLegacyQuoteOrderRepository', $method);
        } catch (Throwable $e) {
            throw QuoteOrderSourceUnavailable::queryFailed();
        }
    }
}
