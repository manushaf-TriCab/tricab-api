<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\CableFit;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\CableFit\Application\CableFitCandidateService;
use TriCab\Core\CableFit\Domain\CandidateDeleteNotAllowed;
use TriCab\Core\CableFit\Domain\CandidateNotFound;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * DELETE /api/v1/tsd/cablefit/candidates/{publicId} - soft-deletes a design: it leaves every list, its revisions and
 * research runs stay on record. Gated on TSD.CABLEFIT.USE; only its creator or an Administrator (403 otherwise).
 */
final class DeleteCableFitCandidateAction
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

        $publicId = $args['publicId'] ?? '';
        try {
            $this->candidates->delete($user, $publicId);
        } catch (CandidateNotFound) {
            return $this->responder->error('CABLEFIT_CANDIDATE_NOT_FOUND', 'Candidate not found.', 404);
        } catch (CandidateDeleteNotAllowed $e) {
            return $this->responder->error('CABLEFIT_DELETE_NOT_ALLOWED', $e->getMessage(), 403);
        }

        return $this->responder->success(['candidatePublicId' => strtolower($publicId), 'deleted' => true]);
    }
}
