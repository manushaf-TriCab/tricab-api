<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\Product\ReorderCatalogueRequest;
use TriCab\Api\Http\Request\Admin\Product\UpsertCatalogueTabRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\CatalogueStatusCommand;
use TriCab\Core\Product\Application\Command\ReorderCatalogueCommand;
use TriCab\Core\Product\Application\Command\UpsertCatalogueTabCommand;
use TriCab\Core\Product\Application\Service\ManageCatalogueNavigationService;

final class CatalogueTabsAdminAction
{
    public function __construct(
        private readonly ManageCatalogueNavigationService $service,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        try {
            $op = self::resolveOp($request, 'tabPublicId');
            $actor = AdminActorFactory::fromRequest($request);
            $filters = self::filters($request);

            if ($op === 'list') {
                return $this->responder->success([
                    'items' => CatalogueDtoSerializer::tabList($this->service->listTabs($filters)),
                ]);
            }
            if ($op === 'get') {
                $id = RouteParams::require($request, 'tabPublicId');

                return $this->responder->success(CatalogueDtoSerializer::tabRecord($this->service->getTab($id)));
            }
            if ($op === 'create') {
                $input = UpsertCatalogueTabRequest::fromArray(JsonRequestBody::requireObject($request));
                $created = $this->transaction->run(fn () => $this->service->createTab($actor, new UpsertCatalogueTabCommand(
                    tabName: $input->tabName,
                    tabCode: $input->tabCode,
                    slug: $input->slug,
                    description: $input->description,
                    displayOrder: $input->displayOrder,
                    isActive: $input->isActive,
                    isPublic: $input->isPublic,
                )));

                return $this->responder->success(CatalogueDtoSerializer::tabRecord($created), 201);
            }
            if ($op === 'update') {
                $id = RouteParams::require($request, 'tabPublicId');
                $input = UpsertCatalogueTabRequest::fromArray(JsonRequestBody::requireObject($request));
                $updated = $this->transaction->run(fn () => $this->service->updateTab($actor, new UpsertCatalogueTabCommand(
                    tabName: $input->tabName,
                    tabCode: $input->tabCode,
                    slug: $input->slug,
                    description: $input->description,
                    displayOrder: $input->displayOrder,
                    isActive: $input->isActive,
                    isPublic: $input->isPublic,
                    expectedRowVersion: $input->expectedRowVersion,
                    tabPublicId: $id,
                )));

                return $this->responder->success(CatalogueDtoSerializer::tabRecord($updated));
            }
            if ($op === 'reorder') {
                $input = ReorderCatalogueRequest::fromArray(JsonRequestBody::requireObject($request));
                $this->transaction->run(fn () => $this->service->reorderTabs(
                    $actor,
                    new ReorderCatalogueCommand($input->orderedPublicIds),
                ));

                return $this->responder->success(['acknowledged' => true]);
            }

            $id = RouteParams::require($request, 'tabPublicId');
            $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $rowVersion = is_string($body['expectedRowVersion'] ?? null) ? $body['expectedRowVersion'] : '';
            $cmd = new CatalogueStatusCommand($id, $rowVersion);
            $result = $this->transaction->run(function () use ($actor, $cmd, $op) {
                return match ($op) {
                    'activate' => $this->service->activateTab($actor, $cmd),
                    'deactivate' => $this->service->deactivateTab($actor, $cmd),
                    'archive' => $this->service->archiveTab($actor, $cmd),
                    'restore' => $this->service->restoreTab($actor, $cmd),
                    default => throw new \InvalidArgumentException('Unknown catalogue tab operation.'),
                };
            });

            return $this->responder->success(CatalogueDtoSerializer::tabRecord($result));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }

    private static function resolveOp(ServerRequestInterface $request, string $idParam): string
    {
        $path = rtrim($request->getUri()->getPath(), '/');
        $method = strtoupper($request->getMethod());
        foreach (['reorder', 'activate', 'deactivate', 'archive', 'restore'] as $suffix) {
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
        ];
        if (array_key_exists('isActive', $query)) {
            $filters['activeOnly'] = ($query['isActive'] === 'true' || $query['isActive'] === '1');
            if ($query['isActive'] === 'false' || $query['isActive'] === '0') {
                $filters['activeOnly'] = false;
            }
        }

        return $filters;
    }
}
