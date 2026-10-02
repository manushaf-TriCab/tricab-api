<?php

declare(strict_types=1);

namespace TriCab\Api\Rfq\Persistence;

use Closure;
use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Rfq\Contract\RfqCorrectionMemory;
use TriCab\Core\Rfq\Parser\ParseRequest;
use TriCab\Core\Rfq\Storage\DraftChangeLog;
use TriCab\Core\Rfq\Storage\PythonJson;
use TriCab\Core\Rfq\Storage\PythonText;
use TriCab\Core\Rfq\Storage\StoreFormat;
use TriCab\Core\Shared\Time\Clock;

/**
 * Correction memory and the review log on Azure SQL:
 * `[rfq].[rfq_correction_memory]`, `[rfq].[rfq_sessions]` and
 * `[rfq].[rfq_entries]` (`create-rfq-schema.sql`), written as the source's
 * `log_store.save_review` and `learn_approved_corrections` write them.
 *
 * The source's upsert (`confirmations + 1`, `updated_at`) is an `UPDATE`
 * then, when it touched nothing, an `INSERT`, under `UPDLOCK, HOLDLOCK` so two
 * reviews learning the same key cannot both insert it.
 *
 * Entries go in chunks: 23 values a line, under SQL Server's 2,100 parameters.
 */
final class AzureSqlRfqCorrectionMemory implements RfqCorrectionMemory
{
    use AzureSqlTransaction;

    private const ENTRY_CHUNK = 80;

    /** @var Closure(): string */
    private readonly Closure $ids;

    /**
     * @param (callable(): string)|null $ids new 32-hex ids; `StoreFormat::newId` unless a test fixes them
     */
    public function __construct(
        private readonly SqlConnection $connection,
        private readonly Clock $clock,
        ?callable $ids = null,
    ) {
        $this->ids = Closure::fromCallable($ids ?? StoreFormat::newId(...));
    }

    public function resolved(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT [normalized_text], [product_code], [confirmations]
             FROM [rfq].[rfq_correction_memory]
             ORDER BY [normalized_text], [confirmations] DESC, [updated_at] DESC',
        );
        $out = [];
        $tied = [];
        $previous = null;
        foreach ($rows as $row) {
            $key = AzureSqlValueMapper::stringValue($row['normalized_text']);
            $count = AzureSqlValueMapper::intValue($row['confirmations']);
            if ($key === $previous) {
                // Only the runner-up matters: a tie with the leader declines the key.
                if (isset($out[$key]) && $out[$key]['confirmations'] === $count) {
                    $tied[$key] = true;
                }
                continue;
            }
            $previous = $key;
            $out[$key] = ['product_code' => AzureSqlValueMapper::stringValue($row['product_code']), 'confirmations' => $count];
        }
        $out = array_diff_key($out, $tied);
        if (count($out) > ParseRequest::MAX_CORRECTIONS) {
            // The most confirmed first, if the table ever outgrows what a parse may carry.
            uasort($out, static fn (array $a, array $b): int => $b['confirmations'] <=> $a['confirmations']);
            $out = array_slice($out, 0, ParseRequest::MAX_CORRECTIONS, true);
        }

        return $out;
    }

    public function recordReview(
        string $draftId,
        int $revision,
        array $quoteDetails,
        array $rows,
        array $systemRows,
        string $actorEmail,
        ?string $actorBranch,
        array $learned,
    ): string {
        $ids = $this->ids;
        $sessionId = $ids();
        $createdAt = StoreFormat::isoTime($this->clock->now());
        $fileNames = [];
        foreach ($rows as $row) {
            $file = PythonText::str(DraftChangeLog::object($row['source'] ?? null)['file_name'] ?? '');
            if ($file !== '') {
                $fileNames[$file] = true;
            }
        }
        $fileNames = array_keys($fileNames);
        sort($fileNames);

        $this->runInTransaction($this->connection, function () use ($sessionId, $createdAt, $draftId, $revision, $quoteDetails, $rows, $systemRows, $actorEmail, $actorBranch, $fileNames, $learned): void {
            $this->connection->execute(
                'INSERT INTO [rfq].[rfq_sessions]
                    ([id], [created_at], [created_by], [file_names], [quote_details], [line_count],
                     [draft_id], [draft_revision], [actor_email], [actor_branch], [outcome], [legacy_unlinked])
                 VALUES (:id, :created_at, :created_by, :file_names, :quote_details, :line_count,
                     :draft_id, :draft_revision, :actor_email, :actor_branch, N\'saved\', 0)',
                [
                    'id' => $sessionId,
                    'created_at' => $createdAt,
                    'created_by' => $actorEmail !== '' ? $actorEmail : 'local',
                    'file_names' => PythonJson::encode($fileNames),
                    'quote_details' => PythonJson::encode($quoteDetails === [] ? new \stdClass() : $quoteDetails),
                    'line_count' => count($rows),
                    'draft_id' => $draftId,
                    'draft_revision' => $revision,
                    'actor_email' => $actorEmail !== '' ? $actorEmail : null,
                    'actor_branch' => $actorBranch !== null && $actorBranch !== '' ? $actorBranch : null,
                ],
            );
            $entries = [];
            foreach ($rows as $index => $row) {
                $system = $systemRows[$index] ?? [];
                $entries[] = $this->entry($sessionId, $createdAt, $row, $system !== [] ? $system : $row);
            }
            foreach (array_chunk($entries, self::ENTRY_CHUNK) as $chunk) {
                $this->insertEntries($chunk);
            }
            foreach ($learned as $item) {
                $this->learn($item['key'], $item['productCode'], $createdAt);
            }
        });

        return $sessionId;
    }

    /**
     * One `rfq_entries` row, as `save_review` builds it.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $system
     *
     * @return array<string, scalar|null>
     */
    private function entry(string $sessionId, string $createdAt, array $row, array $system): array
    {
        $ids = $this->ids;
        $source = DraftChangeLog::object($row['source'] ?? null);
        unset($row['_draft_row_id'], $row['_system_snapshot_valid']);

        return [
            'id' => $ids(),
            'session_id' => $sessionId,
            'created_at' => $createdAt,
            'source_file' => self::text($source['file_name'] ?? null),
            'sheet' => self::text($source['sheet'] ?? null),
            'source_row_number' => is_numeric($source['row_number'] ?? null) ? (int) $source['row_number'] : null,
            'source_original_text' => self::text($source['original_text'] ?? null) ?? self::text($row['raw_text'] ?? null),
            'entry_index' => is_int($source['entry_index'] ?? null) ? $source['entry_index'] : null,
            'split_reason' => self::text($source['split_reason'] ?? null),
            'system_raw_text' => self::text($system['raw_text'] ?? null) ?? self::text($row['raw_text'] ?? null),
            'system_status' => self::text($system['status'] ?? null) ?? self::text($system['final_status'] ?? null),
            'system_product_code' => self::text($system['selected_product_code'] ?? null) ?? self::text($system['final_product_code'] ?? null),
            'system_confidence' => is_numeric($system['confidence'] ?? null) && is_finite((float) $system['confidence']) ? (float) $system['confidence'] : null,
            'system_fields' => PythonJson::encode(self::orEmpty($system['fields'] ?? null, true)),
            'system_candidates' => PythonJson::encode(self::orEmpty($system['candidates'] ?? null, false)),
            'system_warnings' => PythonJson::encode(self::orEmpty($system['warnings'] ?? null, false)),
            'final_product_code' => self::text($row['final_product_code'] ?? null) ?? self::text($row['selected_product_code'] ?? null),
            'final_status' => self::text($row['final_status'] ?? null) ?? self::text($row['status'] ?? null),
            'final_length' => PythonJson::encode(self::orEmpty($row['length'] ?? null, true)),
            'reviewer_note' => PythonText::str($row['reviewer_note'] ?? ''),
            'correction_reason' => PythonText::str($row['correction_reason'] ?? ''),
            'full_payload' => PythonJson::encode($row),
            'parse_tier' => self::text($row['parse_tier'] ?? null) ?? 'regex',
        ];
    }

    /**
     * @param list<array<string, scalar|null>> $entries
     */
    private function insertEntries(array $entries): void
    {
        if ($entries === []) {
            return;
        }
        $columns = array_keys($entries[0]);
        $values = [];
        $params = [];
        foreach ($entries as $i => $entry) {
            $marks = [];
            foreach ($columns as $column) {
                $name = $column . '_' . $i;
                $marks[] = ':' . $name;
                $params[$name] = $entry[$column];
            }
            $values[] = '(' . implode(', ', $marks) . ')';
        }
        $this->connection->execute(
            'INSERT INTO [rfq].[rfq_entries] ([' . implode('], [', $columns) . '])
             VALUES ' . implode(",\n", $values),
            $params,
        );
    }

    private function learn(string $key, string $productCode, string $now): void
    {
        $this->connection->execute(
            'UPDATE [rfq].[rfq_correction_memory] WITH (UPDLOCK, HOLDLOCK)
                SET [confirmations] = [confirmations] + 1, [updated_at] = :updated_at
              WHERE [normalized_text] = :normalized_text AND [product_code] = :product_code;
             IF @@ROWCOUNT = 0
                INSERT INTO [rfq].[rfq_correction_memory]
                    ([normalized_text], [product_code], [confirmations], [created_at], [updated_at])
                VALUES (:insert_text, :insert_code, 1, :created_at, :insert_updated_at);',
            [
                'updated_at' => $now,
                'normalized_text' => $key,
                'product_code' => $productCode,
                'insert_text' => $key,
                'insert_code' => $productCode,
                'created_at' => $now,
                'insert_updated_at' => $now,
            ],
        );
    }

    /** `json.dumps(x or {})` / `or []`: a missing or empty value is written empty, never `null`. */
    private static function orEmpty(mixed $value, bool $object): mixed
    {
        if (is_array($value) && $value !== []) {
            return $value;
        }

        return $object ? new \stdClass() : [];
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }
        $text = (string) $value;

        return $text !== '' ? $text : null;
    }
}
