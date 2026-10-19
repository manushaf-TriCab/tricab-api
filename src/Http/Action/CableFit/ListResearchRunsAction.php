<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\CableFit;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\CableFit\Application\ResearchService;
use TriCab\Core\CableFit\Domain\CandidateNotFound;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * GET /api/v1/tsd/cablefit/candidates/{candidatePublicId}/research - the candidate's runs, newest first.
 * Gated on TSD.CABLEFIT.USE.
 */
final class ListResearchRunsAction
{
    public function __construct(
        private readonly ResearchService $research,
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

        try {
            $runs = $this->research->listRuns($user, $args['candidatePublicId'] ?? '');
        } catch (CandidateNotFound) {
            return $this->responder->error('CABLEFIT_CANDIDATE_NOT_FOUND', 'Candidate not found.', 404);
        }

        return $this->responder->success(['runs' => array_map(ResearchSerializer::run(...), $runs)]);
    }
}
