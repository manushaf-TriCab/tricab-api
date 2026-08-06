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
