<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\CableFit;

use DateTimeInterface;
use stdClass;
use TriCab\Core\CableFit\Application\CandidateDetail;
use TriCab\Core\CableFit\Application\CandidateListItem;
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
     * A row of the design list. `verdict` is null until the latest run has an assessment.
     *
     * @return array<string, mixed>
     */
    public static function listItem(CandidateListItem $item): array
    {
        $row = $item->row;
        $run = $item->latestResearch;

        return self::candidate($row->candidate) + [
            'currentRevision' => $row->candidate->version,
            'createdBy' => self::person($row->createdByName, $row->createdByEmail),
            'updatedBy' => self::person($row->updatedByName, $row->updatedByEmail),
            'stale' => $item->stale,
            'nprRequestNumber' => $item->nprRequestNumber,
            'latestResearch' => $run === null ? null : [
                'runPublicId' => $run->publicId,
                'status' => $run->status,
                'application' => $run->application,
                'verdict' => $run->verdict,
                'finishedAt' => $run->finishedAt?->format(DateTimeInterface::ATOM),
            ],
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

    /**
     * @return array{name: string|null, email: string|null}|null
     */
    private static function person(?string $name, ?string $email): ?array
    {
        return $name === null && $email === null ? null : ['name' => $name, 'email' => $email];
    }
}
