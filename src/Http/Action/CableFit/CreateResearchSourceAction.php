<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\CableFit;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\CableFit\Application\ResearchService;
use TriCab\Core\CableFit\Domain\ResearchInputInvalid;
use TriCab\Core\CableFit\Domain\ResearchSourceExists;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * POST /api/v1/tsd/cablefit/research-sources - body {domain, name, category, notes?}. 201 {publicId};
 * 409 CABLEFIT_SOURCE_EXISTS for a duplicate domain. Gated on TSD.CABLEFIT.RESEARCH.
 */
final class CreateResearchSourceAction
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
        unset($response, $args);

        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser) {
            return $this->responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->responder->error('CABLEFIT_INPUT_INVALID', 'Send the source as a JSON object.', 422);
        }

        try {
            $id = $this->research->createSource($user, $body['domain'] ?? null, $body['name'] ?? null, $body['category'] ?? null, $body['notes'] ?? null);
        } catch (ResearchInputInvalid $invalid) {
            return $this->responder->error('CABLEFIT_INPUT_INVALID', $invalid->getMessage(), 422);
        } catch (ResearchSourceExists $exists) {
            return $this->responder->error('CABLEFIT_SOURCE_EXISTS', $exists->getMessage(), 409);
        }

        return $this->responder->success(['publicId' => $id], 201);
    }
}
