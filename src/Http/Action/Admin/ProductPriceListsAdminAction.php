<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\Product\UpsertPriceListRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\PriceListStatusCommand;
use TriCab\Core\Product\Application\Command\UpsertPriceListCommand;
use TriCab\Core\Product\Application\Service\ManagePriceListService;
use TriCab\Core\Product\Application\Service\VerifyLegacyPriceContextService;

final class ProductPriceListsAdminAction
{
    public function __construct(
        private readonly ManagePriceListService $service,
        private readonly VerifyLegacyPriceContextService $verifyLegacy,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        try {
            $op = self::resolveOp($request, 'priceListPublicId');
            $actor = AdminActorFactory::fromRequest($request);
            $filters = self::filters($request);

            if ($op === 'list') {
                return $this->responder->success([
                    'items' => array_map(
                        ProductManagementDtoSerializer::priceListRecord(...),
                        $this->service->list($filters),
                    ),
                ]);
            }
            if ($op === 'get') {
                $id = RouteParams::require($request, 'priceListPublicId');

                return $this->responder->success(
                    ProductManagementDtoSerializer::priceListRecord($this->service->get($id)),
                );
            }
            if ($op === 'usage') {
                $id = RouteParams::require($request, 'priceListPublicId');

                return $this->responder->success(
                    ProductManagementDtoSerializer::priceListUsage($this->service->getUsage($id)),
                );
            }
            if ($op === 'verify-legacy') {
                $id = RouteParams::require($request, 'priceListPublicId');
                $record = $this->service->get($id);
                if ($record->legacySourceCode === null || $record->legacyContextType === null || $record->legacyContextCode === null) {
                    throw new \InvalidArgumentException('This price list has no legacy context mapping.');
                }
                $result = $this->verifyLegacy->verify(
                    $record->legacySourceCode,
                    $record->legacyContextType,
                    $record->legacyContextCode,
                );

                return $this->responder->success(ProductManagementDtoSerializer::legacyPriceContextVerification($result));
            }
            if ($op === 'create') {
                $input = UpsertPriceListRequest::fromArray(JsonRequestBody::requireObject($request));
                $created = $this->transaction->run(fn () => $this->service->create($actor, self::commandFromRequest($input)));

                return $this->responder->success(ProductManagementDtoSerializer::priceListRecord($created), 201);
            }
            if ($op === 'update') {
                $id = RouteParams::require($request, 'priceListPublicId');
                $input = UpsertPriceListRequest::fromArray(JsonRequestBody::requireObject($request));
                $updated = $this->transaction->run(fn () => $this->service->update($actor, self::commandFromRequest($input, $id)));

                return $this->responder->success(ProductManagementDtoSerializer::priceListRecord($updated));
            }

            $id = RouteParams::require($request, 'priceListPublicId');
            $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $rowVersion = is_string($body['expectedRowVersion'] ?? null) ? $body['expectedRowVersion'] : '';
            $cmd = new PriceListStatusCommand($id, $rowVersion);
            $result = $this->transaction->run(function () use ($actor, $cmd, $op) {
                return match ($op) {
                    'activate' => $this->service->activate($actor, $cmd),
                    'deactivate' => $this->service->deactivate($actor, $cmd),
                    'archive' => $this->service->archive($actor, $cmd),
                    'restore' => $this->service->restore($actor, $cmd),
                    default => throw new \InvalidArgumentException('Unknown price list operation.'),
                };
            });

            return $this->responder->success(ProductManagementDtoSerializer::priceListRecord($result));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }

    private static function commandFromRequest(UpsertPriceListRequest $input, ?string $publicId = null): UpsertPriceListCommand
    {
        return new UpsertPriceListCommand(
            name: $input->name,
            code: $input->code,
            currencyPublicId: $input->currencyPublicId,
            regionPublicId: $input->regionPublicId,
            marketPublicId: $input->marketPublicId,
            legacySourceCode: $input->legacySourceCode,
            legacyContextType: $input->legacyContextType,
            legacyContextCode: $input->legacyContextCode,
            effectiveFromUtc: $input->effectiveFromUtc,
            effectiveToUtc: $input->effectiveToUtc,
            isActive: $input->isActive,
            expectedRowVersion: $input->expectedRowVersion,
            priceListPublicId: $publicId,
        );
    }

    private static function resolveOp(ServerRequestInterface $request, string $idParam): string
    {
        $path = rtrim($request->getUri()->getPath(), '/');
        $method = strtoupper($request->getMethod());
        foreach (['verify-legacy', 'usage', 'activate', 'deactivate', 'archive', 'restore'] as $suffix) {
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
