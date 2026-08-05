<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Service\GetProductPublishReadinessService;

final class GetProductPublicationReadinessAction
{
    public function __construct(
        private readonly GetProductPublishReadinessService $readiness,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $publicId = RouteParams::require($request, 'productPublicId');
            $report = $this->readiness->execute($publicId);

            return $this->responder->success(ProductManagementDtoSerializer::readinessReport($report));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
