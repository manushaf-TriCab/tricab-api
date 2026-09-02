<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\ProductFinder;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\ProductFinder\Contract\ProductFinderClient;

/**
 * POST /api/v1/product-finder — a stated requirement in, a sized code out.
 */
final class FindProductAction
{
    public function __construct(
        private readonly ProductFinderClient $finder,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $body = $request->getParsedBody();
            if (!is_array($body)) {
                return $this->responder->error('INVALID_JSON', 'Invalid JSON body.', 400);
            }

            $query = ProductFinderRequestMapper::fromHttpBody($body);
            if ($query->isEmpty()) {
                return $this->responder->error(
                    'VALIDATION_FAILED',
                    'Give either a free-text requirement, a cable type or a style.',
                    422,
                );
            }

            // No try/catch around this on purpose: `ProductFinderClient` is
            // contractually non-throwing, and an unreachable finder is already
            // a successful response carrying `sizing: unavailable`. Catching
            // here would hide a genuine bug in the adapter behind the same
            // degraded answer.
            return $this->responder->success($this->finder->find($query)->toArray());
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
