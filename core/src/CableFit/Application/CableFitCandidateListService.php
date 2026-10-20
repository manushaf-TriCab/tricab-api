<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Application;

use TriCab\Core\CableFit\Contract\CableFitCandidateRepository;
use TriCab\Core\CableFit\Contract\ResearchRunRepository;
use TriCab\Core\CableFit\Domain\CandidateSearch;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Npr\NprRequestReader;

/**
 * The design list: every candidate, whoever made it (TSD.CABLEFIT.USE is checked by the route).
 *
 * NPR numbers and customers come only through {@see NprRequestReader}, so a design whose NPR
 * request the user may not read shows no number and is not found by searching for it.
 */
final readonly class CableFitCandidateListService
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    public function __construct(
        private CableFitCandidateRepository $candidates,
        private ResearchRunRepository $runs,
        private NprRequestReader $nprReader,
        private CableFitHandoffService $handoff = new CableFitHandoffService(),
    ) {
    }

    /**
     * @param string $query matches the design name, or the NPR number or customer of its request
     */
    public function list(AuthenticatedUser $user, string $query, int $limit, int $offset): CandidateList
    {
        $query = trim($query);
        $matchingRequests = [];
        if ($query !== '') {
            $needle = mb_strtolower($query);
            foreach ($this->nprReader->findManyForTsd($user, $this->candidates->linkedNprRequestPublicIds()) as $publicId => $snapshot) {
                if (str_contains(mb_strtolower($snapshot->requestNumber), $needle)
                    || str_contains(mb_strtolower($snapshot->customerName), $needle)) {
                    $matchingRequests[] = $publicId;
                }
            }
        }

        $page = $this->candidates->search(new CandidateSearch(
            $query === '' ? null : $query,
            $matchingRequests,
            max(1, min(self::MAX_LIMIT, $limit)),
            max(0, $offset),
        ));

        $requestIds = [];
        $candidateIds = [];
        foreach ($page->rows as $row) {
            $candidateIds[] = $row->candidate->publicId;
            if ($row->candidate->nprRequestPublicId !== null) {
                $requestIds[] = $row->candidate->nprRequestPublicId;
            }
        }
        $snapshots = $requestIds === [] ? [] : $this->nprReader->findManyForTsd($user, array_values(array_unique($requestIds)));
        $research = $candidateIds === [] ? [] : $this->runs->latestForCandidates($candidateIds);

        $items = [];
        foreach ($page->rows as $row) {
            $requestId = $row->candidate->nprRequestPublicId;
            $snapshot = $requestId === null ? null : ($snapshots[$requestId] ?? null);
            $items[] = new CandidateListItem(
                $row,
                $snapshot?->requestNumber,
                // The stored bit is refreshed when the design is opened; the live NPR revision is better when readable.
                $snapshot === null ? $row->storedStale : $this->handoff->isStale($row->sourceNprRevision, $snapshot->currentRevision),
                $research[$row->candidate->publicId] ?? null,
            );
        }

        return new CandidateList($items, $page->total);
    }
}
