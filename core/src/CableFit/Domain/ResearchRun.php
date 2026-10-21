<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Domain;

use DateTimeImmutable;

/**
 * Row of `[cablefit].research_runs`. A run is queued by a request, claimed by the worker (running) and then
 * succeeded or failed. The cost fields are null until the model has answered.
 *
 * A run started from Evaluate (C4) also records the saved revision, the application code and the standard it
 * researched, and the browser rule check sent with it; it ends with the agent's advisory verdict and assessment.
 * Runs from before C4, and runs started with only a free-text application, leave these null.
 */
final readonly class ResearchRun
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';

    public const STATUSES = [self::QUEUED, self::RUNNING, self::SUCCEEDED, self::FAILED];

    /**
     * @param array<string, mixed>      $parameters  the design parameters the run researches
     * @param list<string>              $sourcesUsed enabled domains the run searched
     * @param array<string, mixed>|null $ruleCheck   the browser rule check sent with an Evaluate request
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
        public ?int $candidateRevision = null,
        public ?string $applicationCode = null,
        public ?string $standardCode = null,
        public ?array $ruleCheck = null,
        public ?string $verdict = null,
        public ?ResearchAssessment $assessment = null,
    ) {
    }

    /**
     * A fresh queued run.
     *
     * @param array<string, mixed>      $parameters
     * @param list<string>              $sourcesUsed
     * @param array<string, mixed>|null $ruleCheck
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
        ?int $candidateRevision = null,
        ?string $applicationCode = null,
        ?string $standardCode = null,
        ?array $ruleCheck = null,
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
            0,
            $candidateRevision,
            $applicationCode,
            $standardCode,
            $ruleCheck,
        );
    }
}
