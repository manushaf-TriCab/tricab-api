<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PublicProduct;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Action\Admin\CatalogueDtoSerializer;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Product\Region\RegionPreferenceResolver;
use TriCab\Core\Product\Application\Service\GetPublicCatalogueService;

final class GetPublicCatalogueNodeAction
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
            $nodeSlugOrPublicId = RouteParams::require($request, 'nodeSlugOrPublicId');
            $query = QueryParams::normalize($request->getQueryParams());
            $tabSlug = QueryParams::stringOrNull($query, 'tabSlug');
            $regionCode = $this->regions->resolveCode($request, QueryParams::stringOrNull($query, 'regionCode'));

            if (self::looksLikeUuid($nodeSlugOrPublicId)) {
                $node = $this->catalogue->getNodeByPublicId($nodeSlugOrPublicId, $regionCode);
            } else {
                $node = $this->catalogue->getNodeBySlug($nodeSlugOrPublicId, $tabSlug, $regionCode);
            }

            return $this->responder->success(CatalogueDtoSerializer::publicTreeNode($node));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }

    private static function looksLikeUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value,
        ) === 1;
    }
}
