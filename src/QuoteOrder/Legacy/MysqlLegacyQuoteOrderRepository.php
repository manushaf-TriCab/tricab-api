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
            . 'h.ptm AS payment_terms_id, '
            . 'c.code AS customer_code, c.name AS customer_name, '
            . 'c.ln1 AS customer_ln1, c.ln2 AS customer_ln2, c.ln3 AS customer_ln3, c.ln4 AS customer_ln4, '
            . 'c.suburb AS customer_suburb, c.pcode AS customer_pcode, c.country AS customer_country, '
            . 'c.phone AS customer_phone, c.territory AS customer_territory, '
            . 'ct.name AS contact_name, ct.email AS contact_email, ct.phone AS contact_phone, '
            . 'ct.mobile AS contact_mobile, ct.position AS contact_position, '
            . 'd.desc_name AS representative, '
            . 'pt.desc_name AS payment_terms_name, pt.desc_code AS payment_terms_code, '
            . 'o.po_id AS order_id, o.customer_po_no AS customer_po_no, o.reference AS order_reference '
            . 'FROM tc.tqoth h '
            . 'INNER JOIN tc.cust c ON h.cus = c.id '
            . 'LEFT JOIN tc.contacts ct ON h.cus_op = ct.contact_id '
            . 'LEFT JOIN tc.descript d ON h.sa_op = d.desc_id '
            . 'LEFT JOIN tc.descript pt ON h.ptm = pt.desc_id '
            . 'LEFT JOIN tc.customer_orders o ON o.quote_id = h.id '
            . 'WHERE h.id = :id' . $visibilitySql
            . ' LIMIT 1';

        $this->guard->assertReadOnly($sql);
        $rows = $this->safeQuery($sql, $params, 'findQuoteById');
        if ($rows === []) {
            return null;
        }

        $items = $this->loadItems(LegacyRowValue::string($rows[0]['quote_id'] ?? null));
        $document = $this->buildDocument($rows[0]);

        return $this->mapper->mapQuoteDetails($rows[0], $items, $document);
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
        if ($quote === null && !$scope->isUnrestricted()) {
            return null;
        }

        return $this->mapper->mapOrderDetails($rows[0], $quote);
    }

    /**
     * @return list<QuoteItemDetails>
     */
    private function loadItems(string $quoteId): array
    {
        $sql = 'SELECT i.id AS item_id, i.itm AS item_no, i.cata AS product_code, i.catdt AS category_type, '
            . 'i.qotqty AS quantity, i.qty AS pack_count, i.nprc AS unit_price, i.net AS line_total, '
            . 'i.ref AS item_reference, col.desc_name AS colour_name, acc.nom_conductor AS nom_conductor, '
            . 'acc.weight AS weight_kg, '
            . 'sty.desc_name AS style_description, tpro.desc2 AS product_description_2 '
            . 'FROM tc.tqoti i '
            . 'LEFT JOIN tc.descript col ON i.col = col.desc_id '
            . 'LEFT JOIN tc.accessory_specs acc ON acc.product_code = i.cata '
            . 'LEFT JOIN tc.descript sty ON sty.desc_type = 24 '
            . 'AND sty.desc_code = SUBSTRING(i.cata, 1, NULLIF(LOCATE(\'-\', i.cata), 0) - 1) '
            . 'LEFT JOIN tc.tpro_ext tpro ON tpro.product_code = i.cata '
            . 'WHERE i.qot = :qot AND i.cata NOT LIKE :zzdel ORDER BY i.itm ASC, i.id ASC';
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
     * @param array<string, mixed> $header
     */
    private function buildDocument(array $header): \TriCab\Core\QuoteOrder\Application\DTO\QuoteDocumentDetails
    {
        $cusGen = LegacyRowValue::int($header['cus_gen'] ?? null);
        $contactEmail = LegacyRowValue::stringOrNull($header['contact_email'] ?? null);
        $contactPhone = LegacyRowValue::stringOrNull($header['contact_phone'] ?? $header['contact_mobile'] ?? null);

        return $this->mapper->mapDocumentDetails(
            $header,
            $cusGen === 1 ? $contactEmail : null,
            $cusGen === 1 ? $contactPhone : null,
            $this->loadConductorComparison(),
            $this->loadMetalPrices(),
        );
    }

    /**
     * Legacy quotation PDF conductor matrix.
     *
     * Authoritative call sites always pass minsize=50 for quotations:
     * - tc-web templates/template-quote-1.php → stranding(NULL, 50, 0)
     * - tc-web email/quote.php → stranding($tcdb, 50, 0)
     *
     * Quote item CSA is NOT used to vary $minsize. Accessory-only quotes still
     * receive the same matrix when the stranding table is shown ($showStrandTable).
     *
     * SQL mirrors stranding(): csa >= 50 AND csa <= 630 AND unit = 'M',
     * wire-count = left side of "N x D".
     *
     * @return list<array{sizeMm2: string, tricab: string|null, class5: string|null, class6: string|null}>
     */
    private function loadConductorComparison(): array
    {
        // Prefer unit='M' (legacy SQL). Fall back without unit if the column is absent.
        $queries = [
            "SELECT csa, tricab, class_5, class_6 FROM tc.conductor_data"
            . " WHERE CAST(csa AS DECIMAL(10,2)) >= 50 AND CAST(csa AS DECIMAL(10,2)) <= 630"
            . " AND unit = 'M'"
            . " ORDER BY CAST(csa AS DECIMAL(10,2)) ASC",
            "SELECT csa, tricab, class_5, class_6 FROM tc.conductor_data"
            . " WHERE CAST(csa AS DECIMAL(10,2)) >= 50 AND CAST(csa AS DECIMAL(10,2)) <= 630"
            . " AND tricab IS NOT NULL AND LTRIM(RTRIM(tricab)) <> ''"
            . " ORDER BY CAST(csa AS DECIMAL(10,2)) ASC",
        ];
        $rows = [];
        foreach ($queries as $sql) {
            $this->guard->assertReadOnly($sql);
            try {
                $rows = $this->safeQuery($sql, [], 'loadConductorComparison');
                if ($rows !== []) {
                    break;
                }
            } catch (\Throwable) {
                $rows = [];
            }
        }

        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $sizeRaw = LegacyRowValue::stringOrNull($row['csa'] ?? null);
            if ($sizeRaw === null || $sizeRaw === '') {
                continue;
            }
            if (!is_numeric($sizeRaw)) {
                continue;
            }
            $sizeNum = (float) $sizeRaw;
            if ($sizeNum < 50.0 || $sizeNum > 630.0) {
                continue;
            }
            // Legacy PDF casts CSA to int for column headers (50, not 50.00).
            $sizeKey = (string) (int) $sizeNum;
            if (isset($seen[$sizeKey])) {
                continue;
            }
            $tricab = $this->conductorWireCount(LegacyRowValue::stringOrNull($row['tricab'] ?? null));
            if ($tricab === null) {
                continue;
            }
            $seen[$sizeKey] = true;
            $out[] = [
                'sizeMm2' => $sizeKey,
                'tricab' => $tricab,
                'class5' => $this->conductorWireCount(LegacyRowValue::stringOrNull($row['class_5'] ?? null)),
                'class6' => $this->conductorWireCount(LegacyRowValue::stringOrNull($row['class_6'] ?? null)),
            ];
        }

        return $out;
    }

    /**
     * Legacy stranding() takes the left side of "N x D" (approximate number of wires).
     */
    private function conductorWireCount(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        $parts = explode(' x ', $trimmed, 2);

        return trim($parts[0]) !== '' ? trim($parts[0]) : null;
    }

    /**
     * @return array{copperUsd: string|null, copperAud: string|null, aluminiumUsd: string|null, aluminiumAud: string|null, asAt: string|null}|null
     */
    private function loadMetalPrices(): ?array
    {
        $sql = 'SELECT de, cuprcus, cuprcaus, alprcus, alprcaus FROM tc.lme ORDER BY de DESC, id DESC LIMIT 1';
        $this->guard->assertReadOnly($sql);
        try {
            $rows = $this->safeQuery($sql, [], 'loadMetalPrices');
        } catch (\Throwable) {
            return null;
        }
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];

        return [
            'copperUsd' => LegacyRowValue::stringOrNull($row['cuprcus'] ?? null),
            'copperAud' => LegacyRowValue::stringOrNull($row['cuprcaus'] ?? null),
            'aluminiumUsd' => LegacyRowValue::stringOrNull($row['alprcus'] ?? null),
            'aluminiumAud' => LegacyRowValue::stringOrNull($row['alprcaus'] ?? null),
            'asAt' => LegacyRowValue::stringOrNull($row['de'] ?? null),
        ];
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
        if ($scope->isUnrestricted()) {
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
