<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Migration;

use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;
use TriCab\Core\Product\Migration\Live\LegacySqlQueryLogEntry;
use TriCab\Core\Product\Migration\Live\LegacySqlStatementLogger;

/**
 * Test double: validates SQL through the guard and returns scripted rows. Never opens MySQL.
 */
final class RecordingLegacyReadOnlyConnection implements LegacyReadOnlyConnection
{
    /**
     * @param array<string, list<array<string, mixed>>> $responses keyed by SQL normalize prefix or exact
     */
    public function __construct(
        private readonly LegacyReadOnlySqlGuard $guard,
        private readonly LegacySqlStatementLogger $logger,
        private array $responses = [],
        private readonly string $label = 'recording',
    ) {
    }

    public function sourceLabel(): string
    {
        return $this->label;
    }

    public function query(
        string $sql,
        array $params = [],
        string $repository = 'unknown',
        string $method = 'unknown',
    ): array {
        $this->guard->assertReadOnly($sql);
        $started = hrtime(true);
        $rows = $this->resolve($sql);
        $this->logger->log(new LegacySqlQueryLogEntry(
            timestampUtc: gmdate('Y-m-d\TH:i:s\Z'),
            repository: $repository,
            method: $method,
            sql: $sql,
            executionTimeMs: round((hrtime(true) - $started) / 1_000_000, 3),
            rowsReturned: count($rows),
        ));

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function resolve(string $sql): array
    {
        $normalized = $this->guard->normalize($sql);
        $bestKey = null;
        $bestLen = -1;
        foreach ($this->responses as $key => $rows) {
            $upper = strtoupper((string) $key);
            if ($key === $sql || str_contains($normalized, $upper)) {
                if (strlen($upper) > $bestLen) {
                    $bestKey = (string) $key;
                    $bestLen = strlen($upper);
                }
            }
        }

        return $bestKey === null ? [] : $this->responses[$bestKey];
    }
}
