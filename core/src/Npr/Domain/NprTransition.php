<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Domain;

/**
 * One committed change to a request's workflow, as the repository applies it:
 * under a lock, with the version and the allowed starting statuses re-checked,
 * the header, any answer patch, any endorsement record and exactly one Activity
 * row written together.
 */
final readonly class NprTransition
{
    /**
     * @param list<RequestStatus>  $from          statuses this may start from
     * @param array<string, mixed> $payload       kept on the Activity row (e.g. the originating stage)
     * @param array<string, mixed> $answerPatch   TSD / signatory / system fields merged into the current revision
     * @param array{action: string, declaration: bool, comment: ?string}|null $endorsement
     */
    public function __construct(
        public string $action,
        public string $actorRole,
        public NprPerson $actor,
        public array $from,
        public ?RequestStatus $to,
        public string $summary,
        public array $payload = [],
        public ?NprPerson $assignManager = null,
        public ?NprPerson $assignTsdOwner = null,
        public ?NprPerson $assignSignatory = null,
        public bool $markSubmitted = false,
        public bool $markEndorsed = false,
        public bool $markAcknowledged = false,
        public bool $signRevision = false,
        public ?array $endorsement = null,
        public ?NprScreeningDecision $decision = null,
        public array $answerPatch = [],
    ) {
    }
}
