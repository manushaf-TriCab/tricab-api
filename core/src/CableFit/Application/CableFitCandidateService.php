<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Application;

use Closure;
use DateTimeImmutable;
use TriCab\Core\CableFit\Contract\CableFitCandidateRepository;
use TriCab\Core\CableFit\Domain\CableFitCandidate;
use TriCab\Core\CableFit\Domain\CandidateNotFound;
use TriCab\Core\CableFit\Domain\CandidateRevision;
use TriCab\Core\CableFit\Domain\CandidateVersionConflict;
use TriCab\Core\CableFit\Domain\NprRequestNotAccessible;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Npr\NprRequestReader;
use TriCab\Core\Npr\NprRequestSnapshot;

/**
 * Use cases behind the CableFit candidate endpoints. Permission checks
 * (TSD.CABLEFIT.*) belong to the route; this service enforces NPR visibility
 * through {@see NprRequestReader}, where null means "not found or forbidden".
 */
final readonly class CableFitCandidateService
{
    /** @var Closure(): DateTimeImmutable */
    private Closure $clock;

    /** @var Closure(): string */
    private Closure $idGenerator;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock
     * @param (Closure(): string)|null            $idGenerator
     */
    public function __construct(
        private CableFitCandidateRepository $candidates,
        private NprRequestReader $nprReader,
        private CableFitHandoffService $handoff = new CableFitHandoffService(),
        ?Closure $clock = null,
        ?Closure $idGenerator = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
        $this->idGenerator = $idGenerator ?? static fn (): string => self::newGuid();
    }

    /**
     * @return list<CandidateSummary>
     *
     * @throws NprRequestNotAccessible when the request is missing or not readable
     */
    public function listForNprRequest(AuthenticatedUser $user, string $nprRequestPublicId): array
    {
        $snapshot = $this->requireSnapshot($user, $nprRequestPublicId);

        $out = [];
        foreach ($this->candidates->listByNprRequest($nprRequestPublicId) as $candidate) {
            $revision = $this->candidates->latestRevision($candidate->publicId);
            $source = $revision?->sourceNprRevision;
            $stale = $this->handoff->isStale($source, $snapshot->currentRevision);
            $out[] = new CandidateSummary(
                $candidate,
                $source,
                $stale,
                $stale && $source !== null ? $this->handoff->staleReason($source, $snapshot->currentRevision) : null,
            );
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $chosenAlternatives
     *
     * @return string the new candidate public id
     *
     * @throws NprRequestNotAccessible
     */
    public function create(
        AuthenticatedUser $user,
        ?string $nprRequestPublicId,
        ?string $variantId,
        string $name,
        ?array $chosenAlternatives = null,
    ): string {
        $now = ($this->clock)();
        $publicId = ($this->idGenerator)();

        if ($nprRequestPublicId !== null && $nprRequestPublicId !== '') {
            $snapshot = $this->requireSnapshot($user, $nprRequestPublicId);
            $result = $this->handoff->buildHandoff($snapshot, $variantId, $chosenAlternatives);
            $design = $result->toDesign();
            $mappingVersion = $result->mappingVersion;
            $sourceRevision = $snapshot->currentRevision;
            $linkedRequest = $nprRequestPublicId;
            $linkedVariant = $result->variantId;
        } else {
            $design = [
                'construction' => [],
                'provenance' => [],
                'unresolvedRequirements' => [],
                'chosenAlternatives' => [],
            ];
            $mappingVersion = CableFitHandoffService::MAPPING_VERSION;
            $sourceRevision = null;
            $linkedRequest = null;
            $linkedVariant = null;
        }

        $this->candidates->create(
            new CableFitCandidate($publicId, $linkedRequest, $linkedVariant, $name, 1, $now, $now, $user->userId, $user->userId),
            new CandidateRevision($publicId, 1, $design, $mappingVersion, false, $sourceRevision, $now, $user->userId),
        );

        return $publicId;
    }

    /**
     * @throws CandidateNotFound
     */
    public function get(AuthenticatedUser $user, string $candidatePublicId): CandidateDetail
    {
        [$candidate, $revision, $snapshot] = $this->load($user, $candidatePublicId);

        $current = $snapshot?->currentRevision;
        $source = $revision->sourceNprRevision;
        $stale = $current !== null && $this->handoff->isStale($source, $current);
        if ($stale !== $revision->stale) {
            $this->candidates->markRevisionStale($candidatePublicId, $revision->revisionNumber, $stale);
        }

        return $this->detail($candidate, $revision, $current, $stale);
    }

    /**
     * Save a new design as the next revision.
     *
     * @param array<string, mixed> $construction the edited design parameters
     *
     * @return int the new revision number (also the new candidate version)
     *
     * @throws CandidateNotFound
     * @throws CandidateVersionConflict when $expectedVersion is not the current version
     */
    public function save(AuthenticatedUser $user, string $candidatePublicId, int $expectedVersion, array $construction): int
    {
        [$candidate, $revision, $snapshot] = $this->load($user, $candidatePublicId);
        if ($candidate->version !== $expectedVersion) {
            throw new CandidateVersionConflict($candidatePublicId, $expectedVersion, $candidate->version);
        }

        $design = $revision->design;
        $design['construction'] = $construction;
        $source = $revision->sourceNprRevision;
        $stale = $snapshot !== null && $this->handoff->isStale($source, $snapshot->currentRevision);

        $next = new CandidateRevision(
            $candidatePublicId,
            $expectedVersion + 1,
            $design,
            $revision->mappingVersion,
            $stale,
            $source,
            ($this->clock)(),
            $user->userId,
        );

        return $this->candidates->appendRevision($candidatePublicId, $expectedVersion, $next, $user->userId)->version;
    }

    /** A lowercase RFC 4122 version 4 GUID, the format of the UNIQUEIDENTIFIER public_id. */
    private static function newGuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @return array{0: CableFitCandidate, 1: CandidateRevision, 2: NprRequestSnapshot|null}
     */
    private function load(AuthenticatedUser $user, string $candidatePublicId): array
    {
        $candidate = $this->candidates->findByPublicId($candidatePublicId);
        $revision = $candidate === null ? null : $this->candidates->latestRevision($candidatePublicId);
        if ($candidate === null || $revision === null) {
            throw new CandidateNotFound('Candidate not found.');
        }

        $snapshot = null;
        if ($candidate->nprRequestPublicId !== null) {
            $snapshot = $this->nprReader->findForTsd($user, $candidate->nprRequestPublicId);
            if ($snapshot === null) {
                // Indistinguishable from a missing candidate on purpose.
                throw new CandidateNotFound('Candidate not found.');
            }
        }

        return [$candidate, $revision, $snapshot];
    }

    private function detail(CableFitCandidate $candidate, CandidateRevision $revision, ?int $current, bool $stale): CandidateDetail
    {
        $design = $revision->design;
        $source = $revision->sourceNprRevision;

        return new CandidateDetail(
            $candidate,
            $revision->revisionNumber,
            $revision->mappingVersion,
            $source,
            $current,
            $stale,
            $stale && $source !== null && $current !== null ? $this->handoff->staleReason($source, $current) : null,
            $this->arr($design['construction'] ?? null),
            $this->arr($design['provenance'] ?? null),
            $this->listOfArrays($design['unresolvedRequirements'] ?? null),
            $this->arr($design['chosenAlternatives'] ?? null),
        );
    }

    private function requireSnapshot(AuthenticatedUser $user, string $nprRequestPublicId): NprRequestSnapshot
    {
        return $this->nprReader->findForTsd($user, $nprRequestPublicId)
            ?? throw new NprRequestNotAccessible('NPR request not found.');
    }

    /**
     * @return array<string, mixed>
     */
    private function arr(mixed $v): array
    {
        /** @var array<string, mixed> $out */
        $out = is_array($v) ? $v : [];

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOfArrays(mixed $v): array
    {
        $out = [];
        if (is_array($v)) {
            foreach ($v as $item) {
                if (is_array($item)) {
                    /** @var array<string, mixed> $item */
                    $out[] = $item;
                }
            }
        }

        return $out;
    }
}
