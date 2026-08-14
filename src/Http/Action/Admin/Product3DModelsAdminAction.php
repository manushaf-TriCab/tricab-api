<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Request\Admin\RequestFields;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Product\Storage\ProductDevMediaUrlResolver;
use TriCab\Core\Product\Application\Command\CompleteProduct3DModelUploadCommand;
use TriCab\Core\Product\Application\Command\InitiateProduct3DModelUploadCommand;
use TriCab\Core\Product\Application\Command\Product3DModelRowVersionCommand;
use TriCab\Core\Product\Application\Command\UpdateProduct3DModelCommand;
use TriCab\Core\Product\Application\Service\CompleteProduct3DModelUploadService;
use TriCab\Core\Product\Application\Service\InitiateProduct3DModelUploadService;
use TriCab\Core\Product\Application\Service\ManageProduct3DModelService;

final class Product3DModelsAdminAction
{
    public function __construct(
        private readonly ManageProduct3DModelService $manage,
        private readonly InitiateProduct3DModelUploadService $initiate,
        private readonly CompleteProduct3DModelUploadService $complete,
        private readonly Settings $settings,
        private readonly ProductDevMediaUrlResolver $devUrls,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        try {
            $method = strtoupper($request->getMethod());
            $path = $request->getUri()->getPath();

            if ($method === 'GET' && str_ends_with($path, '/3d-models')) {
                $productPublicId = RouteParams::require($request, 'productPublicId');
                $items = $this->manage->list($productPublicId);

                return $this->responder->success([
                    'items' => array_map(
                        fn ($item) => ProductManagementDtoSerializer::product3DModelSummary($item, $this->devUrls),
                        $items,
                    ),
                ]);
            }

            if ($method === 'GET' && preg_match('#/3d-models/[^/]+$#', $path) === 1) {
                $productPublicId = RouteParams::require($request, 'productPublicId');
                $modelPublicId = RouteParams::require($request, 'modelPublicId');
                $item = $this->manage->get($productPublicId, $modelPublicId);

                return $this->responder->success(ProductManagementDtoSerializer::product3DModelSummary($item, $this->devUrls));
            }

            if ($method === 'POST' && str_ends_with($path, '/3d-models/upload-session')) {
                $productPublicId = RouteParams::require($request, 'productPublicId');
                $body = JsonRequestBody::requireObject($request);
                $byteSize = RequestFields::intOrNull($body, 'byteSize');
                if ($byteSize === null) {
                    throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'byteSize is required.');
                }
                $originalFileName = RequestFields::requireString($body, 'originalFileName');
                $actor = AdminActorFactory::fromRequest($request);
                $result = $this->transaction->run(fn () => $this->initiate->execute(
                    $actor,
                    new InitiateProduct3DModelUploadCommand(
                        productPublicId: $productPublicId,
                        name: RequestFields::stringOrDefault($body, 'name', $originalFileName),
                        originalFileName: $originalFileName,
                        contentType: RequestFields::requireString($body, 'contentType'),
                        byteSize: $byteSize,
                        storageContainer: $this->settings->blobContainerProducts,
                        isPrimary: RequestFields::boolOrDefault($body, 'isPrimary', false),
                        uploadExpiresInSeconds: $this->settings->blobUploadSasTtlSeconds,
                        sourceTypeCode: RequestFields::stringOrDefault($body, 'sourceTypeCode', 'upload'),
                        generationMetadata: isset($body['generationMetadata']) && is_array($body['generationMetadata'])
                            ? $body['generationMetadata']
                            : null,
                        productVariantPublicId: RequestFields::stringOrNull($body, 'productVariantPublicId'),
                    ),
                ));

                return $this->responder->success(ProductManagementDtoSerializer::uploadSessionResult($result), 201);
            }

            if ($method === 'POST' && str_contains($path, '/product-3d-models/') && str_ends_with($path, '/complete-upload')) {
                $modelPublicId = RouteParams::require($request, 'modelPublicId');
                $rawBody = $request->getParsedBody();
                $body = is_array($rawBody) ? $rawBody : [];
                if ($body === []) {
                    try {
                        $body = JsonRequestBody::requireObject($request);
                    } catch (Throwable) {
                        $body = [];
                    }
                }
                $actor = AdminActorFactory::fromRequest($request);
                $completed = $this->transaction->run(fn () => $this->complete->execute(
                    $actor,
                    new CompleteProduct3DModelUploadCommand(
                        modelPublicId: $modelPublicId,
                        binaryBase64: isset($body['binaryBase64']) && is_string($body['binaryBase64'])
                            ? $body['binaryBase64']
                            : null,
                    ),
                ));

                return $this->responder->success(ProductManagementDtoSerializer::product3DModelSummary($completed, $this->devUrls));
            }

            $productPublicId = RouteParams::require($request, 'productPublicId');
            $modelPublicId = RouteParams::require($request, 'modelPublicId');
            $actor = AdminActorFactory::fromRequest($request);
            $body = JsonRequestBody::requireObject($request);
            $expected = RequestFields::requireString($body, 'expectedRowVersion');

            if ($method === 'PATCH') {
                $viewer = $body['viewerConfiguration'] ?? null;
                $updated = $this->transaction->run(fn () => $this->manage->update(
                    $actor,
                    new UpdateProduct3DModelCommand(
                        productPublicId: $productPublicId,
                        modelPublicId: $modelPublicId,
                        expectedRowVersion: $expected,
                        name: RequestFields::stringOrNull($body, 'name'),
                        viewerConfiguration: is_array($viewer) ? $viewer : null,
                    ),
                ));

                return $this->responder->success(ProductManagementDtoSerializer::product3DModelSummary($updated, $this->devUrls));
            }

            $cmd = new Product3DModelRowVersionCommand($productPublicId, $modelPublicId, $expected);
            if (str_ends_with($path, '/publish')) {
                $updated = $this->transaction->run(fn () => $this->manage->publish($actor, $cmd));
            } elseif (str_ends_with($path, '/unpublish')) {
                $updated = $this->transaction->run(fn () => $this->manage->unpublish($actor, $cmd));
            } elseif (str_ends_with($path, '/set-primary')) {
                $updated = $this->transaction->run(fn () => $this->manage->setPrimary($actor, $cmd));
            } elseif (str_ends_with($path, '/soft-delete')) {
                $updated = $this->transaction->run(fn () => $this->manage->softDelete($actor, $cmd));
            } else {
                throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'Unsupported 3D model operation.');
            }

            return $this->responder->success(ProductManagementDtoSerializer::product3DModelSummary($updated, $this->devUrls));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
