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
use TriCab\Api\Http\Request\Admin\Product\UpsertSpecificationDefinitionRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\ImportSpecificationDefinitionsCommand;
use TriCab\Core\Product\Application\Command\ReorderSpecificationCommand;
use TriCab\Core\Product\Application\Command\SpecificationStatusCommand;
use TriCab\Core\Product\Application\Command\UpsertSpecificationDefinitionCommand;
use TriCab\Core\Product\Application\Service\SpecificationDefinitionService;

final class SpecificationDefinitionsAdminAction
{
    public function __construct(
        private readonly SpecificationDefinitionService $service,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        try {
            $op = self::resolveOp($request);
            $actor = AdminActorFactory::fromRequest($request);
            $filters = SpecificationAdminFilters::fromRequest($request);
            if ($op === 'list') {
                return $this->responder->success(['items' => ProductManagementDtoSerializer::specificationDefinitionList($this->service->list($filters))]);
            }
            if ($op === 'export') {
                return $this->responder->success(['definitions' => $this->service->export()]);
            }
            if ($op === 'import') {
                $body = JsonRequestBody::requireObject($request);
                $definitions = $body['definitions'] ?? null;
                if (!is_array($definitions)) {
                    throw new \InvalidArgumentException('definitions array is required.');
                }
                /** @var list<array<string, mixed>> $definitions */
                $result = $this->transaction->run(fn () => $this->service->import(
                    $actor,
                    new ImportSpecificationDefinitionsCommand(array_values(array_filter($definitions, 'is_array'))),
                ));
                return $this->responder->success($result->toArray());
            }
            if ($op === 'get') {
                return $this->responder->success(ProductManagementDtoSerializer::specificationDefinition($this->service->get(RouteParams::require($request, 'definitionPublicId'))));
            }
            if ($op === 'usage') {
                return $this->responder->success(ProductManagementDtoSerializer::specificationUsage($this->service->usage(RouteParams::require($request, 'definitionPublicId'))));
            }
            if ($op === 'create') {
                $input = UpsertSpecificationDefinitionRequest::fromArray(JsonRequestBody::requireObject($request));
                $created = $this->transaction->run(fn () => $this->service->create($actor, self::toCommand($input)));
                return $this->responder->success(ProductManagementDtoSerializer::specificationDefinition($created), 201);
            }
            if ($op === 'update') {
                $id = RouteParams::require($request, 'definitionPublicId');
                $input = UpsertSpecificationDefinitionRequest::fromArray(JsonRequestBody::requireObject($request));
                $updated = $this->transaction->run(fn () => $this->service->update($actor, self::toCommand($input, $id)));
                return $this->responder->success(ProductManagementDtoSerializer::specificationDefinition($updated));
            }
            if ($op === 'reorder') {
                $input = ReorderClassificationRequest::fromArray(JsonRequestBody::requireObject($request));
                $body = JsonRequestBody::requireObject($request);
                $parent = isset($body['groupPublicId']) && is_string($body['groupPublicId']) ? $body['groupPublicId'] : null;
                $this->transaction->run(fn () => $this->service->reorder($actor, new ReorderSpecificationCommand($input->orderedPublicIds, $parent)));
                return $this->responder->success(['acknowledged' => true]);
            }
            $id = RouteParams::require($request, 'definitionPublicId');
            $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $rowVersion = is_string($body['expectedRowVersion'] ?? null) ? $body['expectedRowVersion'] : '';
            $cmd = new SpecificationStatusCommand($id, $rowVersion);
            $result = $this->transaction->run(fn () => match ($op) {
                'activate' => $this->service->activate($actor, $cmd),
                'deactivate' => $this->service->deactivate($actor, $cmd),
                'archive' => $this->service->archive($actor, $cmd),
                'restore' => $this->service->restore($actor, $cmd),
                default => throw new \InvalidArgumentException('Unknown specification definition operation.'),
            });
            return $this->responder->success(ProductManagementDtoSerializer::specificationDefinition($result));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }

    private static function toCommand(UpsertSpecificationDefinitionRequest $input, ?string $definitionPublicId = null): UpsertSpecificationDefinitionCommand
    {
        return new UpsertSpecificationDefinitionCommand(
            name: $input->name,
            code: $input->code,
            dataTypeCode: $input->dataTypeCode,
            scopeCode: $input->scopeCode,
            groupPublicId: $input->groupPublicId,
            description: $input->description,
            unit: $input->unit,
            unitPublicId: $input->unitPublicId,
            displayOrder: $input->displayOrder,
            isRequired: $input->isRequired,
            isFilterable: $input->isFilterable,
            isSearchable: $input->isSearchable,
            isComparable: $input->isComparable,
            minimumValue: $input->minimumValue,
            maximumValue: $input->maximumValue,
            decimalPrecision: $input->decimalPrecision,
            validationRules: $input->validationRules,
            isActive: $input->isActive,
            expectedRowVersion: $input->expectedRowVersion,
            definitionPublicId: $definitionPublicId,
        );
    }

    private static function resolveOp(ServerRequestInterface $request): string
    {
        $path = rtrim($request->getUri()->getPath(), '/');
        $method = strtoupper($request->getMethod());
        foreach (['import', 'export', 'reorder', 'usage', 'activate', 'deactivate', 'archive', 'restore'] as $suffix) {
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
        if (isset($args['definitionPublicId']) && is_string($args['definitionPublicId']) && $args['definitionPublicId'] !== '') {
            return 'get';
        }

        return 'list';
    }
}
