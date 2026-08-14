<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Product\Storage\ProductDevMediaUrlResolver;
use TriCab\Core\Product\Contract\ProductDocumentRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class ListProductDocumentsAction
{
    public function __construct(
        private readonly ProductDocumentRepository $documents,
        private readonly ProductDevMediaUrlResolver $devUrls,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $publicId = RouteParams::require($request, 'productPublicId');
            $items = $this->documents->listByProduct(ProductPublicId::fromString($publicId));

            return $this->responder->success(['items' => ProductManagementDtoSerializer::documentList($items, $this->devUrls)]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
