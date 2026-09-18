<?php

declare(strict_types=1);

namespace TriCab\Api\QuoteOrder\Legacy;

use TriCab\Api\QuoteOrder\Support\LegacyRowValue;
use TriCab\Core\QuoteOrder\Contract\LegacyQuoteWriteConnection;
use TriCab\Core\QuoteOrder\Contract\QuoteWriteCorrelationRepository;
use TriCab\Core\QuoteOrder\Live\LegacyQuoteWriteConfig;

/**
 * Writes PlatformOperationId ↔ quoteId inside the active MySQL write connection TX.
 */
final class PdoQuoteWriteCorrelationRepository implements QuoteWriteCorrelationRepository
{
    public function __construct(
        private readonly LegacyQuoteWriteConnection $connection,
        private readonly LegacyQuoteWriteConfig $config,
    ) {
    }

    public function insert(string $platformOperationId, string $quoteId): void
    {
        $this->connection->execute(
            'INSERT INTO ' . $this->config->correlationTable()
            . ' (platform_operation_id, quote_id) VALUES (:op, :qid)',
            [
                'op' => $platformOperationId,
                'qid' => (int) $quoteId,
            ],
        );
    }

    public function findQuoteIdByPlatformOperationId(string $platformOperationId): ?string
    {
        $rows = $this->connection->query(
            'SELECT quote_id FROM ' . $this->config->correlationTable()
            . ' WHERE platform_operation_id = :op LIMIT 1',
            ['op' => $platformOperationId],
        );
        if ($rows === []) {
            return null;
        }

        return LegacyRowValue::string($rows[0]['quote_id'] ?? null);
    }
}
