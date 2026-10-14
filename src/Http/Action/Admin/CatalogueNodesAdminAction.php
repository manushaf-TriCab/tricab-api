<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\Product\AssignCatalogueNodeProductsRequest;
use TriCab\Api\Http\Request\Admin\Product\MoveCatalogueNodeRequest;
use TriCab\Api\Http\Request\Admin\Product\ReorderCatalogueRequest;
use TriCab\Api\Http\Request\Admin\Product\UpsertCatalogueNodeRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Product\Catalogue\CatalogueNavigationImageSigner;
use TriCab\Core\Product\Application\Command\AssignCatalogueNodeProductStyleCommand;
use TriCab\Core\Product\Application\Command\AssignCatalogueNodeProductsCommand;
use TriCab\Core\Product\Application\Command\RemoveCatalogueNodeProductStyleCommand;
use TriCab\Core\Product\Application\Command\CatalogueStatusCommand;
use TriCab\Core\Product\Application\Command\MoveCatalogueNodeCommand;
use TriCab\Core\Product\Application\Command\ReorderCatalogueCommand;
use TriCab\Core\Product\Application\Command\UpsertCatalogueNodeCommand;
use TriCab\Core\Product\Application\Service\ManageCatalogueNavigationService;

final class CatalogueNodesAdminAction
{
    public function __construct(
        private readonly ManageCatalogueNavigationService $service,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
        private readonly CatalogueNavigationImageSigner $images,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        try {
            $op = self::resolveOp($request, 'nodePublicId');
            $actor = AdminActorFactory::fromRequest($request);
            $filters = self::filters($request);

            if ($op === 'list') {
                return $this->responder->success([
                    'items' => CatalogueDtoSerializer::nodeList($this->service->listNodes($filters)),
                ]);
            }
            if ($op === 'tree') {
                $tabPublicId = self::requireQueryString($request, 'tabPublicId');
                $includeDeleted = (($request->getQueryParams()['includeDeleted'] ?? '') === 'true');

                $tree = CatalogueDtoSerializer::nodeTreeList(
                    $this->service->getTabTree($tabPublicId, $includeDeleted),
                );

                return $this->responder->success([
                    'items' => array_map(
                        fn (array $node): array => $this->images->signAdminNode($node),
                        $tree,
                    ),
                ], 200, [
                    'Cache-Control' => $this->images->cacheControl(false),
                ]);
            }
            if ($op === 'get') {
                $id = RouteParams::require($request, 'nodePublicId');

                return $this->responder->success(CatalogueDtoSerializer::nodeRecord($this->service->getNode($id)));
            }
            if ($op === 'usage') {
                $id = RouteParams::require($request, 'nodePublicId');

                return $this->responder->success(CatalogueDtoSerializer::nodeUsage($this->service->getNodeUsage($id)));
            }
            if ($op === 'products-get') {
                $id = RouteParams::require($request, 'nodePublicId');

                return $this->responder->success([
                    'items' => CatalogueDtoSerializer::nodeProductList($this->service->listNodeProducts($id)),
                ]);
            }
            if ($op === 'products-put') {
                $id = RouteParams::require($request, 'nodePublicId');
                $input = AssignCatalogueNodeProductsRequest::fromArray(JsonRequestBody::requireObject($request));
                $this->transaction->run(fn () => $this->service->assignNodeProducts(
                    $actor,
                    new AssignCatalogueNodeProductsCommand($id, $input->placements),
                ));

                return $this->responder->success(['acknowledged' => true]);
            }
            if ($op === 'product-styles-get') {
                $id = RouteParams::require($request, 'nodePublicId');

                return $this->responder->success([
                    'items' => CatalogueDtoSerializer::nodeProductStyleList($this->service->listNodeProductStyles($id)),
                ]);
            }
            if ($op === 'product-styles-post') {
                $id = RouteParams::require($request, 'nodePublicId');
                $body = JsonRequestBody::requireObject($request);
                $stylePublicId = is_string($body['productStylePublicId'] ?? null) ? $body['productStylePublicId'] : '';
                $displayOrder = is_int($body['displayOrder'] ?? null) ? $body['displayOrder'] : 10;
                $this->transaction->run(fn () => $this->service->assignNodeProductStyle(
                    $actor,
                    new AssignCatalogueNodeProductStyleCommand($id, $stylePublicId, $displayOrder),
                ));

                return $this->responder->success(['acknowledged' => true]);
            }
            if ($op === 'product-styles-delete') {
                $id = RouteParams::require($request, 'nodePublicId');
                $stylePublicId = RouteParams::require($request, 'stylePublicId');
                $this->transaction->run(fn () => $this->service->removeNodeProductStyle(
                    $actor,
                    new RemoveCatalogueNodeProductStyleCommand($id, $stylePublicId),
                ));

                return $this->responder->success(['acknowledged' => true]);
            }
            if ($op === 'resolved-products-get') {
                $id = RouteParams::require($request, 'nodePublicId');
                $regionCode = isset($request->getQueryParams()['regionCode'])
                    && is_string($request->getQueryParams()['regionCode'])
                    ? $request->getQueryParams()['regionCode']
                    : null;

                return $this->responder->success([
                    'items' => CatalogueDtoSerializer::resolvedNodeProductList(
                        $this->service->listResolvedNodeProducts($id, $regionCode),
                    ),
                ]);
            }
            if ($op === 'create') {
                $input = UpsertCatalogueNodeRequest::fromArray(JsonRequestBody::requireObject($request));
                $created = $this->transaction->run(fn () => $this->service->createNode($actor, new UpsertCatalogueNodeCommand(
                    tabPublicId: $input->tabPublicId,
                    nodeName: $input->nodeName,
                    nodeCode: $input->nodeCode,
                    slug: $input->slug,
                    parentPublicId: $input->parentPublicId,
                    description: $input->description,
                    nodeTypeCode: $input->nodeTypeCode,
                    imageUrl: $this->images->canonicalStoredUrl($input->imageUrl),
                    imageAltText: $input->imageAltText,
                    imageMediaPublicId: $input->imageMediaPublicId,
                    displayOrder: $input->displayOrder,
                    isActive: $input->isActive,
                    isPublic: $input->isPublic,
                    seoTitle: $input->seoTitle,
                    seoDescription: $input->seoDescription,
                    regionCodes: $input->regionCodes,
                )));

                return $this->responder->success(CatalogueDtoSerializer::nodeRecord($created), 201);
            }
            if ($op === 'update') {
                $id = RouteParams::require($request, 'nodePublicId');
                $input = UpsertCatalogueNodeRequest::fromArray(JsonRequestBody::requireObject($request));
                $existing = $this->service->getNode($id);
                $updated = $this->transaction->run(fn () => $this->service->updateNode($actor, new UpsertCatalogueNodeCommand(
                    tabPublicId: $existing->tabPublicId,
                    nodeName: $input->nodeName,
                    nodeCode: $input->nodeCode,
                    slug: $input->slug,
                    parentPublicId: $input->parentPublicId,
                    description: $input->description,
                    nodeTypeCode: $input->nodeTypeCode,
                    imageUrl: $this->images->canonicalStoredUrl($input->imageUrl),
                    imageAltText: $input->imageAltText,
                    imageMediaPublicId: $input->imageMediaPublicId,
                    displayOrder: $input->displayOrder,
                    isActive: $input->isActive,
                    isPublic: $input->isPublic,
                    seoTitle: $input->seoTitle,
                    seoDescription: $input->seoDescription,
                    regionCodes: $input->regionCodes,
                    expectedRowVersion: $input->expectedRowVersion,
                    nodePublicId: $id,
                )));

                return $this->responder->success(CatalogueDtoSerializer::nodeRecord($updated));
            }
            if ($op === 'move') {
                $id = RouteParams::require($request, 'nodePublicId');
                $input = MoveCatalogueNodeRequest::fromArray(JsonRequestBody::requireObject($request));
                $updated = $this->transaction->run(fn () => $this->service->moveNode($actor, new MoveCatalogueNodeCommand(
                    nodePublicId: $id,
                    newParentPublicId: $input->newParentPublicId,
                    expectedRowVersion: $input->expectedRowVersion,
                    displayOrder: $input->displayOrder,
                )));

                return $this->responder->success(CatalogueDtoSerializer::nodeRecord($updated));
            }
            if ($op === 'reorder') {
                $input = ReorderCatalogueRequest::fromArray(JsonRequestBody::requireObject($request));
                $this->transaction->run(fn () => $this->service->reorderNodes(
                    $actor,
                    new ReorderCatalogueCommand(
                        orderedPublicIds: $input->orderedPublicIds,
                        parentPublicId: $input->parentPublicId,
                        tabPublicId: $input->tabPublicId,
                    ),
                ));

                return $this->responder->success(['acknowledged' => true]);
            }

            $id = RouteParams::require($request, 'nodePublicId');
            $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $rowVersion = is_string($body['expectedRowVersion'] ?? null) ? $body['expectedRowVersion'] : '';
            $cmd = new CatalogueStatusCommand($id, $rowVersion);
            $result = $this->transaction->run(function () use ($actor, $cmd, $op) {
                return match ($op) {
                    'activate' => $this->service->activateNode($actor, $cmd),
                    'deactivate' => $this->service->deactivateNode($actor, $cmd),
                    'archive' => $this->service->archiveNode($actor, $cmd),
                    'restore' => $this->service->restoreNode($actor, $cmd),
                    default => throw new \InvalidArgumentException('Unknown catalogue node operation.'),
                };
            });

            return $this->responder->success(CatalogueDtoSerializer::nodeRecord($result));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }

    private static function resolveOp(ServerRequestInterface $request, string $idParam): string
    {
        $path = rtrim($request->getUri()->getPath(), '/');
        $method = strtoupper($request->getMethod());
        if (str_ends_with($path, '/resolved-products') && $method === 'GET') {
            return 'resolved-products-get';
        }
        if (preg_match('#/product-styles/[^/]+$#', $path) === 1 && $method === 'DELETE') {
            return 'product-styles-delete';
        }
        if (str_ends_with($path, '/product-styles') && $method === 'GET') {
            return 'product-styles-get';
        }
        if (str_ends_with($path, '/product-styles') && $method === 'POST') {
            return 'product-styles-post';
        }
        if (str_ends_with($path, '/products') && $method === 'GET') {
            return 'products-get';
        }
        if (str_ends_with($path, '/products') && ($method === 'PUT' || $method === 'PATCH')) {
            return 'products-put';
        }
        foreach (['tree', 'reorder', 'usage', 'move', 'activate', 'deactivate', 'archive', 'restore'] as $suffix) {
            if (str_ends_with($path, '/' . $suffix)) {
                return $suffix;
            }
        }
        if ($method === 'POST') {
            return 'create';
        }
        if ($method === 'PUT' || $method === 'PATCH') {
            return 'update';
        }
        $args = $request->getAttributes();
        if (isset($args[$idParam]) && is_string($args[$idParam]) && $args[$idParam] !== '') {
            return 'get';
        }

        return 'list';
    }

    /** @return array<string, mixed> */
    private static function filters(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();
        $filters = [
            'search' => isset($query['search']) && is_string($query['search']) ? $query['search'] : null,
            'includeDeleted' => (($query['includeDeleted'] ?? '') === 'true' || ($query['includeDeleted'] ?? '') === '1'),
            'tabPublicId' => isset($query['tabPublicId']) && is_string($query['tabPublicId']) ? $query['tabPublicId'] : null,
        ];
        if (array_key_exists('parentPublicId', $query)) {
            $filters['parentPublicId'] = is_string($query['parentPublicId']) && $query['parentPublicId'] !== ''
                ? $query['parentPublicId']
                : null;
        }
        if (array_key_exists('isActive', $query)) {
            $filters['activeOnly'] = ($query['isActive'] === 'true' || $query['isActive'] === '1');
            if ($query['isActive'] === 'false' || $query['isActive'] === '0') {
                $filters['activeOnly'] = false;
            }
        }

        return $filters;
    }

    private static function requireQueryString(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getQueryParams()[$name] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('%s query parameter is required.', $name));
        }

        return trim($value);
    }
}
