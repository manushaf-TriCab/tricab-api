<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Service\GetProductLookupsService;

final class GetProductLookupsAction
{
    public function __construct(
        private readonly GetProductLookupsService $lookups,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            return $this->responder->success(ProductManagementDtoSerializer::lookupsBundle($this->lookups->execute()));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
