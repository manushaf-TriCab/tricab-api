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
 * POST /api/v1/tsd/cablefit/candidates - body {nprRequestPublicId?, variantId?, name}.
 * 201 {candidatePublicId}. Gated on TSD.CABLEFIT.USE.
 */
final class CreateCableFitCandidateAction
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

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->responder->error('CABLEFIT_INPUT_INVALID', 'Send the candidate as a JSON object.', 422);
        }
        $name = $body['name'] ?? null;
        $name = is_string($name) ? trim($name) : '';
        if ($name === '' || mb_strlen($name) > 255) {
            return $this->responder->error('CABLEFIT_INPUT_INVALID', 'name is required and at most 255 characters.', 422);
        }
        $nprRequest = $body['nprRequestPublicId'] ?? null;
        $variant = $body['variantId'] ?? null;
        if (($nprRequest !== null && !is_string($nprRequest)) || ($variant !== null && !is_string($variant))) {
            return $this->responder->error('CABLEFIT_INPUT_INVALID', 'nprRequestPublicId and variantId must be strings.', 422);
        }

        try {
            $id = $this->candidates->create(
                $user,
                $nprRequest === null || trim($nprRequest) === '' ? null : trim($nprRequest),
                $variant === null || trim($variant) === '' ? null : trim($variant),
                $name,
            );
        } catch (NprRequestNotAccessible) {
            return $this->responder->error('NPR_REQUEST_NOT_FOUND', 'NPR request not found.', 404);
        }

        return $this->responder->success(['candidatePublicId' => $id], 201);
    }
}
