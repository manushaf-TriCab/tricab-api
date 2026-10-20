<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\CableFit;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\CableFit\Application\CableFitCandidateListService;
use TriCab\Core\CableFit\Application\CableFitCandidateService;
use TriCab\Core\CableFit\Domain\NprRequestNotAccessible;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * GET /api/v1/tsd/cablefit/candidates - the design list. Gated on TSD.CABLEFIT.USE.
 *
 * - `?nprRequest={publicId}`: the candidates for that NPR request; 400 when empty, 404 when the
 *   request is not readable.
 * - Otherwise every candidate, newest first: `q` (name, NPR number or customer), `limit`
 *   (default 50, at most 200) and `offset`, with `total`.
 */
final class ListCableFitCandidatesAction
{
    public function __construct(
        private readonly CableFitCandidateService $candidates,
        private readonly CableFitCandidateListService $list,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);

        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser) {
            return $this->responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }

        $query = $request->getQueryParams();
        if (!array_key_exists('nprRequest', $query)) {
            return $this->listAll($user, $query);
        }

        $nprRequest = $query['nprRequest'];
        if (!is_string($nprRequest) || trim($nprRequest) === '') {
            return $this->responder->error('CABLEFIT_INPUT_INVALID', 'nprRequest is required.', 400);
        }

        try {
            $list = $this->candidates->listForNprRequest($user, trim($nprRequest));
        } catch (NprRequestNotAccessible) {
            return $this->responder->error('NPR_REQUEST_NOT_FOUND', 'NPR request not found.', 404);
        }

        return $this->responder->success([
            'candidates' => array_map(CableFitCandidateSerializer::summary(...), $list),
        ]);
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private function listAll(AuthenticatedUser $user, array $query): ResponseInterface
    {
        $q = $query['q'] ?? '';
        $limit = $this->nonNegativeInt($query['limit'] ?? null, CableFitCandidateListService::DEFAULT_LIMIT);
        $offset = $this->nonNegativeInt($query['offset'] ?? null, 0);
        if (!is_string($q) || mb_strlen($q) > 200 || $limit === null || $limit === 0 || $offset === null) {
            return $this->responder->error('CABLEFIT_INPUT_INVALID', 'q must be text of at most 200 characters; limit and offset must be whole numbers, limit at least 1.', 400);
        }
        $limit = min($limit, CableFitCandidateListService::MAX_LIMIT);

        $list = $this->list->list($user, $q, $limit, $offset);

        return $this->responder->success([
            'candidates' => array_map(CableFitCandidateSerializer::listItem(...), $list->items),
            'total' => $list->total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /** The default when absent, the number when a whole number, otherwise null. */
    private function nonNegativeInt(mixed $value, int $default): ?int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return is_string($value) && preg_match('/^\d{1,6}$/', $value) === 1 ? (int) $value : null;
    }
}
