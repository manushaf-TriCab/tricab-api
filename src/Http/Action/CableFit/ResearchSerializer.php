<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\CableFit;

use DateTimeInterface;
use stdClass;
use TriCab\Core\CableFit\Application\ResearchRunDetail;
use TriCab\Core\CableFit\Domain\ResearchFinding;
use TriCab\Core\CableFit\Domain\ResearchRun;
use TriCab\Core\CableFit\Domain\ResearchSource;

/** JSON shapes of the CableFit research endpoints (C3 contract). */
final class ResearchSerializer
{
    /**
     * @return array<string, mixed>
     */
    public static function source(ResearchSource $s): array
    {
        return [
            'publicId' => $s->publicId,
            'domain' => $s->domain,
            'name' => $s->name,
            'category' => $s->category,
            'enabled' => $s->enabled,
            'notes' => $s->notes,
            'updatedAt' => $s->updatedAt->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function run(ResearchRun $r): array
    {
        return [
            'runPublicId' => $r->publicId,
            'status' => $r->status,
            'application' => $r->application,
            'requestedAt' => $r->requestedAt->format(DateTimeInterface::ATOM),
            'requestedByName' => $r->requestedByName,
            'startedAt' => $r->startedAt?->format(DateTimeInterface::ATOM),
            'finishedAt' => $r->finishedAt?->format(DateTimeInterface::ATOM),
            'findingCount' => $r->findingCount,
            'error' => $r->error,
            'candidateRevision' => $r->candidateRevision,
            'applicationCode' => $r->applicationCode,
            'standardCode' => $r->standardCode,
            'verdict' => $r->verdict,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(ResearchRunDetail $d): array
    {
        $r = $d->run;

        return self::run($r) + [
            'parameters' => $r->parameters === [] ? new stdClass() : $r->parameters,
            'sourcesUsed' => $r->sourcesUsed,
            'model' => $r->model,
            'cost' => [
                'inputTokens' => $r->inputTokens ?? 0,
                'outputTokens' => $r->outputTokens ?? 0,
                'searchRequests' => $r->searchRequests ?? 0,
            ],
            // Findings whose URL was not on an enabled source were dropped before saving.
            'droppedFindings' => $r->droppedFindings,
            // Null on runs from before C4, and until the run has succeeded.
            'assessment' => $r->assessment?->toArray(),
            'ruleCheck' => $r->ruleCheck === null ? null : ($r->ruleCheck === [] ? new stdClass() : $r->ruleCheck),
            'findings' => array_map(static fn (ResearchFinding $f): array => self::finding($f, $d->isRecent($f)), $d->findings),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function finding(ResearchFinding $f, bool $recent): array
    {
        return [
            'publicId' => $f->publicId,
            'parameter' => $f->parameter,
            'claim' => $f->claim,
            'relevance' => $f->relevance,
            'url' => $f->url,
            'title' => $f->title,
            'publisher' => $f->publisher,
            'publishedOn' => $f->publishedOn,
            'dateBasis' => $f->dateBasis,
            'withinRecentWindow' => $recent,
            'confidence' => $f->confidence,
            'stance' => $f->stance,
        ];
    }
}
