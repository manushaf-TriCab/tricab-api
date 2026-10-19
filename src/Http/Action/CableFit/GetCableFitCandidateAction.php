<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\CableFit;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\CableFit\Application\CableFitCandidateService;
use TriCab\Core\CableFit\Domain\CandidateNotFound;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * GET /api/v1/tsd/cablefit/candidates/{publicId} - the latest design, with its version.
 * Gated on TSD.CABLEFIT.USE.
 */
final class GetCableFitCandidateAction
{
    public function __construct(
        private readonly CableFitCandidateService $candidates,
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
            $detail = $this->candidates->get($user, $args['publicId'] ?? '');
        } catch (CandidateNotFound) {
            return $this->responder->error('CABLEFIT_CANDIDATE_NOT_FOUND', 'Candidate not found.', 404);
        }

        return $this->responder->success(CableFitCandidateSerializer::detail($detail));
    }
}
