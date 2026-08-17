<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PublicProduct;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Action\Admin\CatalogueDtoSerializer;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Product\Region\RegionPreferenceResolver;
use TriCab\Core\Product\Application\Service\GetPublicCatalogueService;

final class GetPublicCatalogueTabsAction
{
    public function __construct(
        private readonly GetPublicCatalogueService $catalogue,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
        private readonly RegionPreferenceResolver $regions,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        try {
            $query = QueryParams::normalize($request->getQueryParams());
            $regionCode = $this->regions->resolveCode($request, QueryParams::stringOrNull($query, 'regionCode'));

            return $this->responder->success([
                'items' => CatalogueDtoSerializer::publicTabList($this->catalogue->listTabs($regionCode)),
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
