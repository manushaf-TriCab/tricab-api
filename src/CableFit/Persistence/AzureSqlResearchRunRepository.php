<?php

declare(strict_types=1);

namespace TriCab\Api\CableFit\Persistence;

use DateTimeImmutable;
use RuntimeException;
use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\CableFit\Contract\ResearchRunRepository;
use TriCab\Core\CableFit\Domain\ResearchCost;
use TriCab\Core\CableFit\Domain\ResearchFinding;
use TriCab\Core\CableFit\Domain\ResearchRun;

/**
 * `[cablefit].[research_runs]` and `[cablefit].[research_findings]` on SQL Server (`create-cablefit-schema.sql`).
 *
 * A claim is one statement: the oldest queued run (or a running one whose `started_at` is older than the
 * stale cut-off) is read with `UPDLOCK, READPAST` and moved to running, so two workers never get the same
 * run and neither waits for the other. Saving the findings and moving the run to succeeded share one
 * transaction. Public ids go out lowercase.
 */
final class AzureSqlResearchRunRepository implements ResearchRunRepository
{
    use AzureSqlTransaction;

    private const SELECT = <<<'SQL'
        SELECT
            CONVERT(NVARCHAR(36), r.[public_id]) AS [public_id],
            CONVERT(NVARCHAR(36), c.[public_id]) AS [candidate_public_id],
            r.[status], r.[application], r.[parameters_json], r.[sources_used_json], r.[model],
            r.[input_tokens], r.[output_tokens], r.[search_requests], r.[dropped_findings], r.[error],
            r.[requested_by_user_id],
            COALESCE(NULLIF(u.[DisplayName], N''), u.[EmailAddress]) AS [requested_by_name],
            r.[requested_at], r.[started_at], r.[finished_at],
            (SELECT COUNT(*) FROM [cablefit].[research_findings] f WHERE f.[run_id] = r.[run_id]) AS [finding_count]
        FROM [cablefit].[research_runs] r
        JOIN [cablefit].[candidates] c ON c.[candidate_id] = r.[candidate_id]
        LEFT JOIN [identity].[Users] u ON u.[UserId] = r.[requested_by_user_id]
SQL;

    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function add(ResearchRun $run): void
    {
        $inserted = $this->connection->fetchAll(
            'INSERT INTO [cablefit].[research_runs] (
                [public_id], [candidate_id], [status], [application], [parameters_json], [sources_used_json],
                [requested_by_user_id], [requested_at])
             OUTPUT inserted.[run_id]
             SELECT :publicId, c.[candidate_id], N\'queued\', :application, :parameters, :sources, :userId, :at
             FROM [cablefit].[candidates] c
             WHERE c.[public_id] = :candidate',
            [
                'publicId' => $run->publicId,
                'application' => $run->application,
                'parameters' => self::json($run->parameters === [] ? new \stdClass() : $run->parameters),
                'sources' => self::json($run->sourcesUsed),
                'userId' => $run->requestedByUserId,
                'at' => AzureSqlValueMapper::formatDateTime($run->requestedAt),
                'candidate' => $run->candidatePublicId,
            ],
        );
        if ($inserted === []) {
            throw new RuntimeException('Unknown candidate ' . $run->candidatePublicId);
        }
    }

    public function listByCandidate(string $candidatePublicId): array
    {
        if (preg_match(self::GUID, $candidatePublicId) !== 1) {
            return [];
        }
        $rows = $this->connection->fetchAll(
            self::SELECT . ' WHERE c.[public_id] = :candidate ORDER BY r.[requested_at] DESC, r.[run_id] DESC',
            ['candidate' => $candidatePublicId],
        );

        return array_map(fn (array $row): ResearchRun => $this->run($row), $rows);
    }

    public function find(string $runPublicId): ?ResearchRun
    {
        if (preg_match(self::GUID, $runPublicId) !== 1) {
            return null;
        }
        $row = $this->connection->fetchAll(
            self::SELECT . ' WHERE r.[public_id] = :id',
            ['id' => $runPublicId],
        )[0] ?? null;

        return $row === null ? null : $this->run($row);
    }

    public function findings(string $runPublicId): array
    {
        if (preg_match(self::GUID, $runPublicId) !== 1) {
            return [];
        }
        $rows = $this->connection->fetchAll(
            'SELECT CONVERT(NVARCHAR(36), f.[public_id]) AS [public_id],
                    f.[parameter], f.[claim], f.[relevance], f.[url], f.[title], f.[publisher],
                    f.[published_on], f.[date_basis], f.[confidence], f.[sort_order]
             FROM [cablefit].[research_findings] f
             JOIN [cablefit].[research_runs] r ON r.[run_id] = f.[run_id]
             WHERE r.[public_id] = :id
             ORDER BY f.[sort_order], f.[finding_id]',
            ['id' => $runPublicId],
        );

        return array_map(
            static fn (array $row): ResearchFinding => new ResearchFinding(
                strtolower(AzureSqlValueMapper::stringValue($row['public_id'] ?? null)),
                AzureSqlValueMapper::stringValue($row['parameter'] ?? null),
                AzureSqlValueMapper::stringValue($row['claim'] ?? null),
                AzureSqlValueMapper::stringValue($row['relevance'] ?? null),
                AzureSqlValueMapper::stringValue($row['url'] ?? null),
                AzureSqlValueMapper::stringValue($row['title'] ?? null),
                AzureSqlValueMapper::nullableString($row['publisher'] ?? null),
                AzureSqlValueMapper::nullableString($row['published_on'] ?? null),
                AzureSqlValueMapper::stringValue($row['date_basis'] ?? null),
                AzureSqlValueMapper::stringValue($row['confidence'] ?? null),
                AzureSqlValueMapper::intValue($row['sort_order'] ?? null),
            ),
            $rows,
        );
    }

    public function claimNext(DateTimeImmutable $now, DateTimeImmutable $staleBefore): ?ResearchRun
    {
        $claimed = $this->connection->fetchAll(
            ';WITH next_run AS (
                SELECT TOP (1) r.[public_id], r.[status], r.[started_at], r.[finished_at], r.[error]
                FROM [cablefit].[research_runs] r WITH (UPDLOCK, READPAST, ROWLOCK)
                WHERE r.[status] = N\'queued\' OR (r.[status] = N\'running\' AND r.[started_at] < :stale)
                ORDER BY r.[requested_at], r.[run_id]
             )
             UPDATE next_run
             SET [status] = N\'running\', [started_at] = :now, [finished_at] = NULL, [error] = NULL
             OUTPUT CONVERT(NVARCHAR(36), inserted.[public_id]) AS [public_id]',
            [
                'stale' => AzureSqlValueMapper::formatDateTime($staleBefore),
                'now' => AzureSqlValueMapper::formatDateTime($now),
            ],
        );
        if ($claimed === []) {
            return null;
        }

        return $this->find(strtolower(AzureSqlValueMapper::stringValue($claimed[0]['public_id'] ?? null)));
    }

    public function outstanding(): int
    {
        $row = $this->connection->fetchAll(
            'SELECT COUNT(*) AS [n] FROM [cablefit].[research_runs] WHERE [status] IN (N\'queued\', N\'running\')',
        )[0] ?? [];

        return AzureSqlValueMapper::intValue($row['n'] ?? 0);
    }

    public function markSucceeded(
        string $runPublicId,
        array $findings,
        array $sourcesUsed,
        ResearchCost $cost,
        int $droppedFindings,
        DateTimeImmutable $at,
    ): void {
        $this->runInTransaction($this->connection, function () use ($runPublicId, $findings, $sourcesUsed, $cost, $droppedFindings, $at): void {
            // A run taken again after a crash may have saved findings once already.
            $this->connection->execute(
                'DELETE f FROM [cablefit].[research_findings] f
                 JOIN [cablefit].[research_runs] r ON r.[run_id] = f.[run_id]
                 WHERE r.[public_id] = :id',
                ['id' => $runPublicId],
            );
            foreach ($findings as $finding) {
                $this->connection->execute(
                    'INSERT INTO [cablefit].[research_findings] (
                        [public_id], [run_id], [parameter], [claim], [relevance], [url], [title], [publisher],
                        [published_on], [date_basis], [confidence], [sort_order])
                     SELECT :publicId, r.[run_id], :parameter, :claim, :relevance, :url, :title, :publisher,
                            :publishedOn, :dateBasis, :confidence, :sortOrder
                     FROM [cablefit].[research_runs] r
                     WHERE r.[public_id] = :run',
                    [
                        'publicId' => $finding->publicId,
                        'parameter' => $finding->parameter,
                        'claim' => $finding->claim,
                        'relevance' => $finding->relevance,
                        'url' => $finding->url,
                        'title' => $finding->title,
                        'publisher' => $finding->publisher,
                        'publishedOn' => $finding->publishedOn,
                        'dateBasis' => $finding->dateBasis,
                        'confidence' => $finding->confidence,
                        'sortOrder' => $finding->sortOrder,
                        'run' => $runPublicId,
                    ],
                );
            }
            $this->connection->execute(
                'UPDATE [cablefit].[research_runs]
                 SET [status] = N\'succeeded\', [sources_used_json] = :sources, [model] = :model,
                     [input_tokens] = :input, [output_tokens] = :output, [search_requests] = :searches,
                     [dropped_findings] = :dropped, [error] = NULL, [finished_at] = :at
                 WHERE [public_id] = :id',
                [
                    'sources' => self::json($sourcesUsed),
                    'model' => $cost->model === '' ? null : $cost->model,
                    'input' => $cost->inputTokens,
                    'output' => $cost->outputTokens,
                    'searches' => $cost->searchRequests,
                    'dropped' => $droppedFindings,
                    'at' => AzureSqlValueMapper::formatDateTime($at),
                    'id' => $runPublicId,
                ],
            );
        });
    }

    public function markFailed(string $runPublicId, string $error, ?ResearchCost $cost, DateTimeImmutable $at): void
    {
        $this->connection->execute(
            'UPDATE [cablefit].[research_runs]
             SET [status] = N\'failed\', [error] = :error, [finished_at] = :at,
                 [model] = COALESCE(:model, [model]),
                 [input_tokens] = COALESCE(:input, [input_tokens]),
                 [output_tokens] = COALESCE(:output, [output_tokens]),
                 [search_requests] = COALESCE(:searches, [search_requests])
             WHERE [public_id] = :id',
            [
                'error' => mb_substr($error, 0, 2000),
                'at' => AzureSqlValueMapper::formatDateTime($at),
                'model' => $cost === null || $cost->model === '' ? null : $cost->model,
                'input' => $cost?->inputTokens,
                'output' => $cost?->outputTokens,
                'searches' => $cost?->searchRequests,
                'id' => $runPublicId,
            ],
        );
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function run(array $row): ResearchRun
    {
        $parameters = json_decode(AzureSqlValueMapper::stringValue($row['parameters_json'] ?? null), true);
        $sources = json_decode(AzureSqlValueMapper::stringValue($row['sources_used_json'] ?? null), true);
        $domains = [];
        foreach (is_array($sources) ? $sources : [] as $domain) {
            if (is_string($domain)) {
                $domains[] = $domain;
            }
        }
        /** @var array<string, mixed> $parameters */
        $parameters = is_array($parameters) ? $parameters : [];

        return new ResearchRun(
            strtolower(AzureSqlValueMapper::stringValue($row['public_id'] ?? null)),
            strtolower(AzureSqlValueMapper::stringValue($row['candidate_public_id'] ?? null)),
            AzureSqlValueMapper::stringValue($row['status'] ?? null),
            AzureSqlValueMapper::stringValue($row['application'] ?? null),
            $parameters,
            $domains,
            AzureSqlValueMapper::nullableString($row['model'] ?? null),
            AzureSqlValueMapper::nullableInt($row['input_tokens'] ?? null),
            AzureSqlValueMapper::nullableInt($row['output_tokens'] ?? null),
            AzureSqlValueMapper::nullableInt($row['search_requests'] ?? null),
            AzureSqlValueMapper::intValue($row['dropped_findings'] ?? 0),
            AzureSqlValueMapper::nullableString($row['error'] ?? null),
            AzureSqlValueMapper::intValue($row['requested_by_user_id'] ?? null),
            AzureSqlValueMapper::nullableString($row['requested_by_name'] ?? null),
            AzureSqlValueMapper::requireDateTime($row['requested_at'] ?? null, 'requested_at'),
            AzureSqlValueMapper::nullableDateTime($row['started_at'] ?? null),
            AzureSqlValueMapper::nullableDateTime($row['finished_at'] ?? null),
            AzureSqlValueMapper::intValue($row['finding_count'] ?? 0),
        );
    }
}
