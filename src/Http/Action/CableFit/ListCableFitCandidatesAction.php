<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\CableFit;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\CableFit\Application\CableFitCandidateService;
use TriCab\Core\CableFit\Domain\NprRequestNotAccessible;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * GET /api/v1/tsd/cablefit/candidates?nprRequest={publicId} - candidates for an NPR request.
 * 400 when nprRequest is missing; 404 when the request is not readable. Gated on TSD.CABLEFIT.USE.
 */
final class ListCableFitCandidatesAction
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

        $nprRequest = $request->getQueryParams()['nprRequest'] ?? null;
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
}
