<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Contract\ProductSpecificationRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class GetProductSpecificationsAction
{
    public function __construct(
        private readonly ProductSpecificationRepository $specifications,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $publicId = RouteParams::require($request, 'productPublicId');
            $values = $this->specifications->listProductValues(ProductPublicId::fromString($publicId));

            return $this->responder->success([
                'values' => array_map(
                    ProductManagementDtoSerializer::specificationValue(...),
                    $values,
                ),
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
