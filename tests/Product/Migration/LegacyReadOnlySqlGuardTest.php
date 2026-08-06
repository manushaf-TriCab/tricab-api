<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Application\Exception\LegacyDatabaseWriteAttemptException;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;

final class LegacyReadOnlySqlGuardTest extends TestCase
{
    private LegacyReadOnlySqlGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new LegacyReadOnlySqlGuard();
    }

    /** @dataProvider allowedSql */
    public function test_allows_read_only_sql(string $sql): void
    {
        $this->guard->assertReadOnly($sql);
        self::assertTrue($this->guard->isAllowed($sql));
    }

    /** @return list<array{0: string}> */
    public static function allowedSql(): array
    {
        return [
            ['SELECT ID FROM wp_posts WHERE post_type = :t'],
            ['SHOW TABLES'],
            ['SHOW TABLES LIKE \'wp\\_%\''],
            ['DESCRIBE wp_posts'],
            ['DESC `tpro`'],
            ['EXPLAIN SELECT * FROM tpro'],
            ['SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()'],
            ["  /* comment */\nSELECT 1"],
        ];
    }

    /** @dataProvider forbiddenSql */
    public function test_rejects_writes(string $sql): void
    {
        $this->expectException(LegacyDatabaseWriteAttemptException::class);
        $this->guard->assertReadOnly($sql);
    }

    /** @return list<array{0: string}> */
    public static function forbiddenSql(): array
    {
        return [
            ['INSERT INTO wp_posts (post_title) VALUES (\'x\')'],
            ['UPDATE wp_posts SET post_title = \'x\''],
            ['DELETE FROM wp_posts'],
            ['ALTER TABLE wp_posts ADD COLUMN x INT'],
            ['DROP TABLE wp_posts'],
            ['CREATE TABLE tmp (id INT)'],
            ['TRUNCATE tpro'],
            ['REPLACE INTO tpro (ida) VALUES (\'x\')'],
            ['SET @x = 1'],
            ['LOCK TABLES wp_posts READ'],
            ['CALL some_proc()'],
            ['SELECT * FROM wp_posts FOR UPDATE'],
        ];
    }
}
