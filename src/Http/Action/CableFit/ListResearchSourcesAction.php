<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\CableFit;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\CableFit\Application\ResearchService;

/**
 * GET /api/v1/tsd/cablefit/research-sources - the sources the research agent may search and cite.
 * Gated on TSD.CABLEFIT.USE.
 */
final class ListResearchSourcesAction
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
        unset($request, $response, $args);

        return $this->responder->success([
            'sources' => array_map(ResearchSerializer::source(...), $this->research->listSources()),
        ]);
    }
}
