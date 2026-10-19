<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\CableFit;

use DateTimeInterface;
use stdClass;
use TriCab\Core\CableFit\Application\CandidateDetail;
use TriCab\Core\CableFit\Application\CandidateSummary;
use TriCab\Core\CableFit\Domain\CableFitCandidate;

/** JSON shapes of the CableFit candidate endpoints. */
final class CableFitCandidateSerializer
{
    /**
     * @return array<string, mixed>
     */
    public static function candidate(CableFitCandidate $c): array
    {
        return [
            'candidatePublicId' => $c->publicId,
            'nprRequestPublicId' => $c->nprRequestPublicId,
            'variantId' => $c->nprVariantId,
            'name' => $c->name,
            'version' => $c->version,
            'createdAt' => $c->createdAt->format(DateTimeInterface::ATOM),
            'updatedAt' => $c->updatedAt->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(CandidateSummary $s): array
    {
        return self::candidate($s->candidate) + [
            'sourceNprRevision' => $s->sourceNprRevision,
            'stale' => $s->stale,
            'staleReason' => $s->staleReason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(CandidateDetail $d): array
    {
        return self::candidate($d->candidate) + [
            'mappingVersion' => $d->mappingVersion,
            'sourceNprRevision' => $d->sourceNprRevision,
            'currentNprRevision' => $d->currentNprRevision,
            'stale' => $d->stale,
            'staleReason' => $d->staleReason,
            'construction' => $d->construction === [] ? new stdClass() : $d->construction,
            'provenance' => $d->provenance === [] ? new stdClass() : $d->provenance,
            'unresolvedRequirements' => $d->unresolvedRequirements,
            'chosenAlternatives' => $d->chosenAlternatives === [] ? new stdClass() : $d->chosenAlternatives,
        ];
    }
}
