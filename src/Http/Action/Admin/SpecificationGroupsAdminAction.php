<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\Product\ReorderClassificationRequest;
use TriCab\Api\Http\Request\Admin\Product\UpsertSpecificationGroupRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\ReorderSpecificationCommand;
use TriCab\Core\Product\Application\Command\SpecificationStatusCommand;
use TriCab\Core\Product\Application\Command\UpsertSpecificationGroupCommand;
use TriCab\Core\Product\Application\Service\SpecificationGroupService;

final class SpecificationGroupsAdminAction
{
    public function __construct(
        private readonly SpecificationGroupService $service,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        try {
            $op = self::resolveOp($request, 'groupPublicId');
            $actor = AdminActorFactory::fromRequest($request);
            $filters = self::filters($request);
            if ($op === 'list') {
                return $this->responder->success(['items' => ProductManagementDtoSerializer::specificationGroupList($this->service->list($filters))]);
            }
            if ($op === 'get') {
                return $this->responder->success(ProductManagementDtoSerializer::specificationGroup($this->service->get(RouteParams::require($request, 'groupPublicId'))));
            }
            if ($op === 'usage') {
                return $this->responder->success(ProductManagementDtoSerializer::specificationUsage($this->service->usage(RouteParams::require($request, 'groupPublicId'))));
            }
            if ($op === 'create') {
                $input = UpsertSpecificationGroupRequest::fromArray(JsonRequestBody::requireObject($request));
                $created = $this->transaction->run(fn () => $this->service->create($actor, new UpsertSpecificationGroupCommand(
                    name: $input->name,
                    code: $input->code,
                    description: $input->description,
                    displayOrder: $input->displayOrder,
                    isActive: $input->isActive,
                )));
                return $this->responder->success(ProductManagementDtoSerializer::specificationGroup($created), 201);
            }
            if ($op === 'update') {
                $id = RouteParams::require($request, 'groupPublicId');
                $input = UpsertSpecificationGroupRequest::fromArray(JsonRequestBody::requireObject($request));
                $updated = $this->transaction->run(fn () => $this->service->update($actor, new UpsertSpecificationGroupCommand(
                    name: $input->name,
                    code: $input->code,
                    description: $input->description,
                    displayOrder: $input->displayOrder,
                    isActive: $input->isActive,
                    expectedRowVersion: $input->expectedRowVersion,
                    groupPublicId: $id,
                )));
                return $this->responder->success(ProductManagementDtoSerializer::specificationGroup($updated));
            }
            if ($op === 'reorder') {
                $input = ReorderClassificationRequest::fromArray(JsonRequestBody::requireObject($request));
                $this->transaction->run(fn () => $this->service->reorder($actor, new ReorderSpecificationCommand($input->orderedPublicIds)));
                return $this->responder->success(['acknowledged' => true]);
            }
            $id = RouteParams::require($request, 'groupPublicId');
            $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $rowVersion = is_string($body['expectedRowVersion'] ?? null) ? $body['expectedRowVersion'] : '';
            $cmd = new SpecificationStatusCommand($id, $rowVersion);
            $result = $this->transaction->run(fn () => match ($op) {
                'activate' => $this->service->activate($actor, $cmd),
                'deactivate' => $this->service->deactivate($actor, $cmd),
                'archive' => $this->service->archive($actor, $cmd),
                'restore' => $this->service->restore($actor, $cmd),
                default => throw new \InvalidArgumentException('Unknown specification group operation.'),
            });
            return $this->responder->success(ProductManagementDtoSerializer::specificationGroup($result));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }

    private static function resolveOp(ServerRequestInterface $request, string $idParam): string
    {
        $path = rtrim($request->getUri()->getPath(), '/');
        $method = strtoupper($request->getMethod());
        foreach (['reorder', 'usage', 'activate', 'deactivate', 'archive', 'restore'] as $suffix) {
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
