<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Migration;

use TriCab\Core\Product\Migration\Contract\LegacyWordPressRepository;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;
use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Read-only WordPress product repository. SELECT/SHOW/DESCRIBE only via guarded connection.
 */
final class MysqlLegacyWordPressRepository implements LegacyWordPressRepository
{
    private const REPO = 'MysqlLegacyWordPressRepository';

    public function __construct(
        private readonly LegacyReadOnlyConnection $connection,
        private readonly string $tablePrefix = 'wp_',
    ) {
    }

    public function findProducts(array $filter = []): array
    {
        $posts = $this->t('posts');
        $meta = $this->t('postmeta');
        $limit = max(1, min(500, (int) ($filter['maxRecords'] ?? 50)));
        $style = isset($filter['styleCode']) ? strtoupper(MigrationPayload::string($filter['styleCode'])) : null;
        $types = $filter['postTypes'] ?? ['cable', 'accessory'];
        if (!is_array($types) || $types === []) {
            $types = ['cable', 'accessory'];
        }
        $types = array_values(array_map(static fn ($t) => MigrationPayload::string($t), $types));
        $namedIn = [];
        $params = [];
        foreach ($types as $i => $type) {
            $namedIn[] = ':type' . $i;
            $params['type' . $i] = $type;
        }
        $in = implode(',', $namedIn);
        $sql = <<<SQL
SELECT
    p.ID,
    p.post_title,
    p.post_name,
    p.post_status,
    p.post_type,
    p.post_content,
    (
        SELECT m.meta_value FROM {$meta} m
        WHERE m.post_id = p.ID AND m.meta_key = 'style_code'
        LIMIT 1
    ) AS style_code,
    (
        SELECT m.meta_value FROM {$meta} m
        WHERE m.post_id = p.ID AND m.meta_key = 'small_desc'
        LIMIT 1
    ) AS small_desc
FROM {$posts} p
WHERE p.post_type IN ({$in})
  AND p.post_status IN ('publish', 'draft', 'private', 'pending')
SQL;
        if ($style !== null && $style !== '') {
            $sql .= " AND EXISTS (SELECT 1 FROM {$meta} sm WHERE sm.post_id = p.ID AND sm.meta_key = 'style_code' AND UPPER(sm.meta_value) = :styleCode)";
            $params['styleCode'] = $style;
        }
        $sql .= ' ORDER BY p.ID ASC LIMIT ' . $limit;

        return $this->connection->query($sql, $params, self::REPO, 'findProducts');
    }

    public function findAttachments(array $postIds): array
    {
        $ids = $this->intIds($postIds);
        if ($ids === []) {
            return [];
        }
        $posts = $this->t('posts');
        $meta = $this->t('postmeta');
        $params = [];
        $in = $this->namedIn($ids, 'pid', $params);
        $sql = <<<SQL
SELECT
    a.ID,
    a.post_parent,
    a.guid,
    a.post_mime_type AS mime,
    a.post_title,
    (
        SELECT m.meta_value FROM {$meta} m
        WHERE m.post_id = a.ID AND m.meta_key = '_wp_attached_file'
        LIMIT 1
    ) AS file,
    a.post_parent AS linked_post_id
FROM {$posts} a
WHERE a.post_type = 'attachment'
  AND a.post_parent IN ({$in})
ORDER BY a.ID ASC
LIMIT 1000
SQL;

        return $this->connection->query($sql, $params, self::REPO, 'findAttachments');
    }

    public function findCategories(array $postIds): array
    {
        $ids = $this->intIds($postIds);
        if ($ids === []) {
            return [];
        }
        $tr = $this->t('term_relationships');
        $tt = $this->t('term_taxonomy');
        $terms = $this->t('terms');
        $params = [];
        $in = $this->namedIn($ids, 'pid', $params);
        $sql = <<<SQL
SELECT
    tr.object_id AS post_id,
    t.term_id,
    t.slug,
    t.name,
    tt.taxonomy
FROM {$tr} tr
INNER JOIN {$tt} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
INNER JOIN {$terms} t ON t.term_id = tt.term_id
WHERE tr.object_id IN ({$in})
ORDER BY tr.object_id ASC, t.slug ASC
LIMIT 2000
SQL;

        return $this->connection->query($sql, $params, self::REPO, 'findCategories');
    }

    public function findSpecifications(array $postIds): array
    {
        $ids = $this->intIds($postIds);
        if ($ids === []) {
            return [];
        }
        $meta = $this->t('postmeta');
        $params = [];
        $in = $this->namedIn($ids, 'pid', $params);
        $sql = <<<SQL
SELECT
    post_id,
    meta_key,
    meta_value,
    meta_key AS field,
    meta_value AS value
FROM {$meta}
WHERE post_id IN ({$in})
  AND meta_key NOT LIKE '\_%'
  AND meta_key NOT LIKE 'field_%'
ORDER BY post_id ASC, meta_key ASC
LIMIT 5000
SQL;

        return $this->connection->query($sql, $params, self::REPO, 'findSpecifications');
    }

    public function findRelationships(array $postIds): array
    {
        $ids = $this->intIds($postIds);
        if ($ids === []) {
            return [];
        }
        $meta = $this->t('postmeta');
        $params = [];
        $in = $this->namedIn($ids, 'pid', $params);
        $sql = <<<SQL
SELECT
    post_id,
    meta_key,
    meta_value,
    CONCAT(post_id, ':', meta_key) AS sourceIdentifier,
    CONCAT('wordpress:wp_posts:', post_id) AS `from`,
    meta_value AS `to`,
    meta_key AS relationshipType
FROM {$meta}
WHERE post_id IN ({$in})
  AND (
        meta_key LIKE '%accessory%'
     OR meta_key LIKE '%related%'
     OR meta_key = 'styles'
  )
ORDER BY post_id ASC
LIMIT 2000
SQL;

        return $this->connection->query($sql, $params, self::REPO, 'findRelationships');
    }

    public function inspectSchema(): array
    {
        $prefix = $this->tablePrefix;
        $tablesWanted = [
            $prefix . 'posts',
            $prefix . 'postmeta',
            $prefix . 'terms',
            $prefix . 'term_taxonomy',
            $prefix . 'term_relationships',
            $prefix . 'options',
        ];
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%';
        // Prefer INFORMATION_SCHEMA: MySQL native prepares reject placeholders in SHOW … LIKE.
        $tableRows = $this->connection->query(
            'SELECT TABLE_NAME AS name FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE :likePattern',
            ['likePattern' => $like],
            self::REPO,
            'inspectSchema.tables',
        );
        $tables = [];
        foreach ($tableRows as $row) {
            $name = MigrationPayload::string($row['name'] ?? '');
            if ($name !== '' && in_array($name, $tablesWanted, true)) {
                $tables[] = $name;
            }
        }

        $columns = [];
        foreach ($tables as $table) {
            // DESCRIBE table — table name comes from SHOW TABLES result (allowlisted).
            if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                continue;
            }
            $desc = $this->connection->query('DESCRIBE `' . $table . '`', [], self::REPO, 'inspectSchema.describe');
            $columns[$table] = array_values(array_filter(array_map(
                static fn (array $r): string => MigrationPayload::string($r['Field'] ?? ''),
                $desc,
            )));
        }

        $posts = $this->t('posts');
        $counts = [];
        foreach (['cable', 'accessory', 'certificate', 'attachment'] as $type) {
            $rows = $this->connection->query(
                "SELECT COUNT(*) AS cnt FROM {$posts} WHERE post_type = :postType",
                ['postType' => $type],
                self::REPO,
                'inspectSchema.count',
            );
            $counts[$type] = (int) ($rows[0]['cnt'] ?? 0);
        }

        return [
            'tables' => $tables,
            'columns' => $columns,
            'productCounts' => $counts,
        ];
    }

    public function discoverPostTypeCounts(): array
    {
        $posts = $this->t('posts');
        $rows = $this->connection->query(
            "SELECT post_type, post_status, COUNT(*) AS total FROM {$posts} GROUP BY post_type, post_status ORDER BY post_type, post_status",
            [],
            self::REPO,
            'discoverPostTypeCounts',
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'post_type' => MigrationPayload::string($row['post_type'] ?? ''),
                'post_status' => MigrationPayload::string($row['post_status'] ?? ''),
                'total' => (int) ($row['total'] ?? 0),
            ];
        }

        return $out;
    }

    public function discoverProductTaxonomies(array $postTypes): array
    {
        $types = $this->sanitizeTypes($postTypes);
        $posts = $this->t('posts');
        $tr = $this->t('term_relationships');
        $tt = $this->t('term_taxonomy');
        $terms = $this->t('terms');
        $params = [];
        $in = $this->namedInStrings($types, 'type', $params);
        $sql = <<<SQL
SELECT
    tt.taxonomy,
    t.term_id,
    t.name,
    t.slug,
    tt.parent,
    COUNT(DISTINCT p.ID) AS usage_count,
    GROUP_CONCAT(DISTINCT p.post_type ORDER BY p.post_type SEPARATOR ',') AS related_post_types
FROM {$tr} tr
INNER JOIN {$tt} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
INNER JOIN {$terms} t ON t.term_id = tt.term_id
INNER JOIN {$posts} p ON p.ID = tr.object_id
WHERE p.post_type IN ({$in})
  AND p.post_status IN ('publish', 'draft', 'private', 'pending')
GROUP BY tt.taxonomy, t.term_id, t.name, t.slug, tt.parent
ORDER BY tt.taxonomy ASC, usage_count DESC, t.name ASC
SQL;

        return $this->connection->query($sql, $params, self::REPO, 'discoverProductTaxonomies');
    }

    public function discoverProductMetaKeys(array $postTypes): array
    {
        $types = $this->sanitizeTypes($postTypes);
        $posts = $this->t('posts');
        $meta = $this->t('postmeta');
        $params = [];
        $in = $this->namedInStrings($types, 'type', $params);
        $sql = <<<SQL
SELECT
    m.meta_key,
    COUNT(DISTINCT m.post_id) AS product_count,
    MIN(m.meta_value) AS example_min,
    MAX(m.meta_value) AS example_max
FROM {$meta} m
INNER JOIN {$posts} p ON p.ID = m.post_id
WHERE p.post_type IN ({$in})
  AND p.post_status IN ('publish', 'draft', 'private', 'pending')
  AND m.meta_key NOT LIKE '\_%'
  AND m.meta_key NOT LIKE 'field_%'
GROUP BY m.meta_key
ORDER BY product_count DESC, m.meta_key ASC
SQL;

        return $this->connection->query($sql, $params, self::REPO, 'discoverProductMetaKeys');
    }

    public function discoverStyleCodes(array $postTypes): array
    {
        $types = $this->sanitizeTypes($postTypes);
        $posts = $this->t('posts');
        $meta = $this->t('postmeta');
        $params = [];
        $in = $this->namedInStrings($types, 'type', $params);
        $sql = <<<SQL
SELECT
    m.meta_value AS style_code,
    COUNT(*) AS product_count,
    GROUP_CONCAT(DISTINCT p.post_status ORDER BY p.post_status SEPARATOR ',') AS statuses
FROM {$meta} m
INNER JOIN {$posts} p ON p.ID = m.post_id
WHERE p.post_type IN ({$in})
  AND m.meta_key = 'style_code'
  AND m.meta_value IS NOT NULL
  AND m.meta_value <> ''
GROUP BY m.meta_value
ORDER BY product_count DESC, style_code ASC
SQL;

        return $this->connection->query($sql, $params, self::REPO, 'discoverStyleCodes');
    }

    public function discoverNavMenus(): array
    {
        $tt = $this->t('term_taxonomy');
        $terms = $this->t('terms');
        $sql = <<<SQL
SELECT
    t.term_id,
    t.name,
    t.slug,
    tt.count AS item_count
FROM {$tt} tt
INNER JOIN {$terms} t ON t.term_id = tt.term_id
WHERE tt.taxonomy = 'nav_menu'
ORDER BY t.name ASC
SQL;

        return $this->connection->query($sql, [], self::REPO, 'discoverNavMenus');
    }

    public function discoverProductAttachments(array $postTypes): array
    {
        $types = $this->sanitizeTypes($postTypes);
        $posts = $this->t('posts');
        $params = [];
        $in = $this->namedInStrings($types, 'type', $params);
        $sql = <<<SQL
SELECT
    parent.post_type,
    COUNT(DISTINCT a.ID) AS attachment_count,
    COUNT(DISTINCT parent.ID) AS products_with_attachments
FROM {$posts} a
INNER JOIN {$posts} parent ON parent.ID = a.post_parent
WHERE a.post_type = 'attachment'
  AND parent.post_type IN ({$in})
GROUP BY parent.post_type
SQL;

        return $this->connection->query($sql, $params, self::REPO, 'discoverProductAttachments');
    }

    public function discoverProductIdentityRows(array $postTypes): array
    {
        $types = $this->sanitizeTypes($postTypes);
        $posts = $this->t('posts');
        $meta = $this->t('postmeta');
        $params = [];
        $in = $this->namedInStrings($types, 'type', $params);
        $sql = <<<SQL
SELECT
    p.ID AS post_id,
    p.post_type,
    p.post_status,
    p.post_title,
    p.post_name AS slug,
    (
        SELECT m.meta_value FROM {$meta} m
        WHERE m.post_id = p.ID AND m.meta_key = 'style_code'
        LIMIT 1
    ) AS style_code_raw
FROM {$posts} p
WHERE p.post_type IN ({$in})
  AND p.post_status IN ('publish', 'draft', 'private', 'pending')
ORDER BY p.ID ASC
SQL;

        return $this->connection->query($sql, $params, self::REPO, 'discoverProductIdentityRows');
    }

    public function discoverMetaValueCounts(array $postTypes, string $metaKey, int $limit = 500): array
    {
        $key = trim($metaKey);
        if ($key === '' || preg_match('/^[A-Za-z0-9_-]+$/', $key) !== 1) {
            return [];
        }
        $types = $this->sanitizeTypes($postTypes);
        $posts = $this->t('posts');
        $meta = $this->t('postmeta');
        $params = [];
        $in = $this->namedInStrings($types, 'type', $params);
        $params['metaKey'] = $key;
        $limit = max(1, min(1000, $limit));
        $sql = <<<SQL
SELECT
    CAST(m.meta_value AS CHAR) AS value,
    COUNT(*) AS usage_count
FROM {$meta} m
INNER JOIN {$posts} p ON p.ID = m.post_id
WHERE p.post_type IN ({$in})
  AND p.post_status IN ('publish', 'draft', 'private', 'pending')
  AND m.meta_key = :metaKey
  AND m.meta_value IS NOT NULL
  AND CAST(m.meta_value AS CHAR) <> ''
GROUP BY CAST(m.meta_value AS CHAR)
ORDER BY usage_count DESC
LIMIT {$limit}
SQL;

        return $this->connection->query($sql, $params, self::REPO, 'discoverMetaValueCounts');
    }

    public function discoverCertificates(int $limit = 500): array
    {
        $posts = $this->t('posts');
        $meta = $this->t('postmeta');
        $tt = $this->t('term_taxonomy');
        $tr = $this->t('term_relationships');
        $terms = $this->t('terms');
        $limit = max(1, min(2000, $limit));
        $sql = <<<SQL
SELECT
    p.ID AS post_id,
    p.post_title,
    p.post_status,
    p.post_name AS slug,
    (
        SELECT GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR '|')
        FROM {$tr} r
        INNER JOIN {$tt} tax ON tax.term_taxonomy_id = r.term_taxonomy_id
        INNER JOIN {$terms} t ON t.term_id = tax.term_id
        WHERE r.object_id = p.ID AND tax.taxonomy = 'category'
    ) AS categories,
    (
        SELECT m.meta_value FROM {$meta} m
        WHERE m.post_id = p.ID AND m.meta_key = 'style_code'
        LIMIT 1
    ) AS style_code_raw,
    (
        SELECT COUNT(*) FROM {$posts} a
        WHERE a.post_parent = p.ID AND a.post_type = 'attachment'
    ) AS attachment_count
FROM {$posts} p
WHERE p.post_type = 'certificate'
  AND p.post_status IN ('publish', 'draft', 'private', 'pending')
ORDER BY p.ID ASC
LIMIT {$limit}
SQL;

        return $this->connection->query($sql, [], self::REPO, 'discoverCertificates');
    }

    /**
     * @param list<string> $types
     * @return list<string>
     */
    private function sanitizeTypes(array $types): array
    {
        $out = [];
        foreach ($types as $type) {
            $normalized = strtolower(trim(MigrationPayload::string($type)));
            if ($normalized !== '' && preg_match('/^[a-z0-9_-]+$/', $normalized) === 1) {
                $out[] = $normalized;
            }
        }

        return $out !== [] ? array_values(array_unique($out)) : ['cable', 'accessory'];
    }

    /**
     * @param list<string> $values
     * @param array<string, scalar|null> $params
     */
    private function namedInStrings(array $values, string $prefix, array &$params): string
    {
        $params = [];
        $parts = [];
        foreach ($values as $i => $value) {
            $key = $prefix . $i;
            $parts[] = ':' . $key;
            $params[$key] = $value;
        }

        return implode(',', $parts);
    }

    private function t(string $suffix): string
    {
        $name = $this->tablePrefix . $suffix;
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException('Invalid WordPress table prefix/name.');
        }

        return '`' . $name . '`';
    }

    /**
     * @param list<int|string> $postIds
     * @return list<int>
     */
    private function intIds(array $postIds): array
    {
        $out = [];
        foreach ($postIds as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $out[] = $n;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<int> $ids
     * @param array<string, scalar|null> $params
     */
    private function namedIn(array $ids, string $prefix, array &$params): string
    {
        $params = [];
        $parts = [];
        foreach ($ids as $i => $id) {
            $key = $prefix . $i;
            $parts[] = ':' . $key;
            $params[$key] = $id;
        }

        return implode(',', $parts);
    }
}
