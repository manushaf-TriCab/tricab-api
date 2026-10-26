<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees;

use PDO;
use TriCab\Core\LegacyMigration\Live\ReadOnlySqlAudit;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;

/**
 * The one way the sync talks to tc: every statement passes the legacy
 * read-only guard and the read-only audit before it reaches the connection,
 * so anything that is not a SELECT throws and fails the run.
 */
final class ReadOnlyLegacyQuery
{
    private readonly LegacyReadOnlySqlGuard $guard;
    private readonly ReadOnlySqlAudit $audit;

    public function __construct(private readonly PDO $pdo)
    {
        $this->guard = new LegacyReadOnlySqlGuard();
        $this->audit = new ReadOnlySqlAudit();
    }

    /**
     * @param list<scalar|null> $params
     *
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        $this->guard->assertReadOnly($sql);
        $this->audit->recordLegacy($sql);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $named = [];
            foreach ($row as $column => $value) {
                $named[(string) $column] = $value;
            }
            $rows[] = $named;
        }

        return $rows;
    }

    public function selectCount(): int
    {
        $summary = $this->audit->summary();

        return (int) ($summary['legacySelectQueries'] ?? 0);
    }
}
