<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Domain;

use DateTimeImmutable;

/**
 * Row of `[cablefit].research_runs`. A run is queued by a request, claimed by the worker (running) and then
 * succeeded or failed. The cost fields are null until the model has answered.
 */
final readonly class ResearchRun
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';

    public const STATUSES = [self::QUEUED, self::RUNNING, self::SUCCEEDED, self::FAILED];

    /**
     * @param array<string, mixed> $parameters  the design parameters the run researches
     * @param list<string>         $sourcesUsed enabled domains the run searched
     */
    public function __construct(
        public string $publicId,
        public string $candidatePublicId,
        public string $status,
        public string $application,
        public array $parameters,
        public array $sourcesUsed,
        public ?string $model,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public ?int $searchRequests,
        public int $droppedFindings,
        public ?string $error,
        public int $requestedByUserId,
        public ?string $requestedByName,
        public DateTimeImmutable $requestedAt,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
        public int $findingCount = 0,
    ) {
    }

    /**
     * A fresh queued run.
     *
     * @param array<string, mixed> $parameters
     * @param list<string>         $sourcesUsed
     */
    public static function queued(
        string $publicId,
        string $candidatePublicId,
        string $application,
        array $parameters,
        array $sourcesUsed,
        int $userId,
        ?string $userName,
        DateTimeImmutable $at,
    ): self {
        return new self(
            $publicId,
            $candidatePublicId,
            self::QUEUED,
            $application,
            $parameters,
            $sourcesUsed,
            null,
            null,
            null,
            null,
            0,
            null,
            $userId,
            $userName,
            $at,
            null,
            null,
        );
    }
}
