<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\CableFit;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\CableFit\Application\CableFitCandidateService;
use TriCab\Core\CableFit\Domain\CandidateNotFound;
use TriCab\Core\CableFit\Domain\CandidateVersionConflict;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * PUT /api/v1/tsd/cablefit/candidates/{publicId} - body {version, construction}.
 * Answers {version}, or 409 with error.currentVersion when the version is stale.
 * Gated on TSD.CABLEFIT.USE.
 */
final class SaveCableFitCandidateAction
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
            return $this->responder->error('CABLEFIT_INPUT_INVALID', 'Send the design as a JSON object.', 422);
        }
        $version = $body['version'] ?? null;
        $construction = $body['construction'] ?? null;
        if (!is_int($version) || $version < 1 || !is_array($construction) || ($construction !== [] && array_is_list($construction))) {
            return $this->responder->error('CABLEFIT_INPUT_INVALID', 'version (integer) and construction (object) are required.', 422);
        }
        /** @var array<string, mixed> $construction */

        try {
            $saved = $this->candidates->save($user, $args['publicId'] ?? '', $version, $construction);
        } catch (CandidateNotFound) {
            return $this->responder->error('CABLEFIT_CANDIDATE_NOT_FOUND', 'Candidate not found.', 404);
        } catch (CandidateVersionConflict $conflict) {
            return $this->responder->payload([
                'success' => false,
                'error' => [
                    'code' => 'CABLEFIT_VERSION_CONFLICT',
                    'message' => 'The candidate was changed since you loaded it.',
                    'currentVersion' => $conflict->currentVersion,
                ],
            ], 409);
        }

        return $this->responder->success(['version' => $saved]);
    }
}
