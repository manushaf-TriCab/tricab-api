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
use TriCab\Api\Http\Request\Admin\Product\UpsertSpecificationOptionRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\ReorderSpecificationCommand;
use TriCab\Core\Product\Application\Command\SpecificationStatusCommand;
use TriCab\Core\Product\Application\Command\UpsertSpecificationOptionCommand;
use TriCab\Core\Product\Application\Service\SpecificationOptionService;

final class SpecificationOptionsAdminAction
{
    public function __construct(
        private readonly SpecificationOptionService $service,
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
                return $this->responder->success(['items' => ProductManagementDtoSerializer::specificationOptionList($this->service->list($filters))]);
            }
            if ($op === 'get') {
                return $this->responder->success(ProductManagementDtoSerializer::specificationOption($this->service->get(RouteParams::require($request, 'optionPublicId'))));
            }
            if ($op === 'usage') {
                return $this->responder->success(ProductManagementDtoSerializer::specificationUsage($this->service->usage(RouteParams::require($request, 'optionPublicId'))));
            }
            if ($op === 'create') {
                $defId = RouteParams::optional($request, 'definitionPublicId');
                $input = UpsertSpecificationOptionRequest::fromArray(JsonRequestBody::requireObject($request), $defId);
                $created = $this->transaction->run(fn () => $this->service->create($actor, new UpsertSpecificationOptionCommand(
                    definitionPublicId: $input->definitionPublicId,
                    name: $input->name,
                    code: $input->code,
                    value: $input->value,
                    displayOrder: $input->displayOrder,
                    isActive: $input->isActive,
                )));
                return $this->responder->success(ProductManagementDtoSerializer::specificationOption($created), 201);
            }
            if ($op === 'update') {
                $id = RouteParams::require($request, 'optionPublicId');
                $input = UpsertSpecificationOptionRequest::fromArray(JsonRequestBody::requireObject($request));
                $updated = $this->transaction->run(fn () => $this->service->update($actor, new UpsertSpecificationOptionCommand(
                    definitionPublicId: $input->definitionPublicId,
                    name: $input->name,
                    code: $input->code,
                    value: $input->value,
                    displayOrder: $input->displayOrder,
                    isActive: $input->isActive,
                    expectedRowVersion: $input->expectedRowVersion,
                    optionPublicId: $id,
                )));
                return $this->responder->success(ProductManagementDtoSerializer::specificationOption($updated));
            }
            if ($op === 'reorder') {
                $input = ReorderClassificationRequest::fromArray(JsonRequestBody::requireObject($request));
                $body = JsonRequestBody::requireObject($request);
                $parent = isset($body['definitionPublicId']) && is_string($body['definitionPublicId'])
                    ? $body['definitionPublicId']
                    : RouteParams::optional($request, 'definitionPublicId');
                $this->transaction->run(fn () => $this->service->reorder($actor, new ReorderSpecificationCommand($input->orderedPublicIds, $parent)));
                return $this->responder->success(['acknowledged' => true]);
            }
            $id = RouteParams::require($request, 'optionPublicId');
            $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $rowVersion = is_string($body['expectedRowVersion'] ?? null) ? $body['expectedRowVersion'] : '';
            $cmd = new SpecificationStatusCommand($id, $rowVersion);
            $result = $this->transaction->run(fn () => match ($op) {
                'activate' => $this->service->activate($actor, $cmd),
                'deactivate' => $this->service->deactivate($actor, $cmd),
                'archive' => $this->service->archive($actor, $cmd),
                'restore' => $this->service->restore($actor, $cmd),
                default => throw new \InvalidArgumentException('Unknown specification option operation.'),
            });
            return $this->responder->success(ProductManagementDtoSerializer::specificationOption($result));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }

    private static function resolveOp(ServerRequestInterface $request): string
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
        if (isset($args['optionPublicId']) && is_string($args['optionPublicId']) && $args['optionPublicId'] !== '') {
            return 'get';
        }

        return 'list';
    }
}
