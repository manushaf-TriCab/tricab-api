<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\CableFit;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\CableFit\Application\ResearchService;
use TriCab\Core\CableFit\Domain\ResearchInputInvalid;
use TriCab\Core\CableFit\Domain\ResearchSourceNotFound;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * PUT /api/v1/tsd/cablefit/research-sources/{publicId} - body {name, category, enabled, notes?}. 200.
 * The domain cannot change. Gated on TSD.CABLEFIT.RESEARCH.
 */
final class UpdateResearchSourceAction
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
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->responder->error('CABLEFIT_INPUT_INVALID', 'Send the source as a JSON object.', 422);
        }

        try {
            $this->research->updateSource(
                $user,
                $args['publicId'] ?? '',
                $body['name'] ?? null,
                $body['category'] ?? null,
                $body['enabled'] ?? null,
                $body['notes'] ?? null,
            );
        } catch (ResearchInputInvalid $invalid) {
            return $this->responder->error('CABLEFIT_INPUT_INVALID', $invalid->getMessage(), 422);
        } catch (ResearchSourceNotFound) {
            return $this->responder->error('CABLEFIT_SOURCE_NOT_FOUND', 'Source not found.', 404);
        }

        return $this->responder->success(['updated' => true]);
    }
}
