<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Service\GetProductAuditHistoryService;

final class GetProductAuditAction
{
    public function __construct(
        private readonly GetProductAuditHistoryService $audit,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $publicId = RouteParams::require($request, 'productPublicId');
            $entries = $this->audit->execute($publicId);

            return $this->responder->success(['items' => ProductManagementDtoSerializer::auditList($entries)]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
