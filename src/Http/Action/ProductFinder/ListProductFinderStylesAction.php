<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\ProductFinder;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\ProductFinder\Contract\ProductFinderClient;

/**
 * GET /api/v1/product-finder/styles — the Style field's autocomplete list.
 *
 * Read from the finder itself rather than the admin product-styles table: the
 * finder validates a stated style against its own register, so offering any
 * other list would let people pick a style the finder then rejects.
 */
final class ListProductFinderStylesAction
{
    public function __construct(
        private readonly ProductFinderClient $finder,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($request, $response);

        return $this->responder->success(['styles' => $this->finder->styles()]);
    }
}
