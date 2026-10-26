<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Hr\Employees;

use PDO;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Hr\Employees\ReadOnlyLegacyQuery;
use TriCab\Core\Product\Migration\Application\Exception\LegacyDatabaseWriteAttemptException;

/** The tc reader's guard, against an in-memory SQLite standing in for MySQL. */
final class ReadOnlyLegacyQueryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec("CREATE TABLE employee (employee_code TEXT, mobile TEXT); INSERT INTO employee VALUES ('A', '1')");
    }

    public function testASelectRuns(): void
    {
        $query = new ReadOnlyLegacyQuery($this->pdo);

        self::assertSame([['employee_code' => 'A']], $query->select('SELECT employee_code FROM employee WHERE employee_code > ?', ['']));
        self::assertSame(1, $query->selectCount());
    }

    /** @return iterable<string, array{string}> */
    public static function writes(): iterable
    {
        yield 'update' => ["UPDATE employee SET mobile = '2'"];
        yield 'delete' => ['DELETE FROM employee'];
        yield 'insert' => ["INSERT INTO employee VALUES ('B', '2')"];
        yield 'drop' => ['DROP TABLE employee'];
        yield 'replace' => ["REPLACE INTO employee VALUES ('A', '2')"];
        yield 'commented update' => ["/* x */ UPDATE employee SET mobile = '2'"];
        yield 'select into' => ['SELECT * INTO copy FROM employee'];
        yield 'for update' => ['SELECT * FROM employee FOR UPDATE'];
        yield 'session set' => ['SET autocommit = 0'];
        yield 'empty' => [''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('writes')]
    public function testAnythingButASelectFailsAndReachesNothing(string $sql): void
    {
        $query = new ReadOnlyLegacyQuery($this->pdo);

        try {
            $query->select($sql);
            self::fail('expected the guard to refuse');
        } catch (LegacyDatabaseWriteAttemptException) {
            self::assertSame(0, $query->selectCount());
        }
        self::assertSame([['employee_code' => 'A', 'mobile' => '1']], (new ReadOnlyLegacyQuery($this->pdo))->select('SELECT * FROM employee'));
    }
}
