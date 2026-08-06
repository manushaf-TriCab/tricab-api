<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Contract\ProductSeoRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class GetProductSeoAction
{
    public function __construct(
        private readonly ProductSeoRepository $seo,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $publicId = RouteParams::require($request, 'productPublicId');
            $query = QueryParams::normalize($request->getQueryParams());
            $regionPublicId = QueryParams::stringOrNull($query, 'regionPublicId');
            $productPublicId = ProductPublicId::fromString($publicId);
            $payload = $this->seo->findSeo($productPublicId, $regionPublicId);
            $slug = $this->seo->findPrimarySlug($productPublicId);

            return $this->responder->success([
                'primarySlug' => $slug?->toString(),
                'seo' => ProductManagementDtoSerializer::seoPayload($payload),
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
