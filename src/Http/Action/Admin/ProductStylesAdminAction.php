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
use TriCab\Api\Http\Request\Admin\Product\CompleteProductMediaUploadRequest;
use TriCab\Api\Http\Request\Admin\Product\InitiateStyleMediaUploadRequest;
use TriCab\Api\Http\Request\Admin\Product\UpsertProductStyleRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Product\Storage\ProductDevMediaUrlResolver;
use TriCab\Core\Product\Application\Command\AssignProductStyleCommand;
use TriCab\Core\Product\Application\Command\ChangeProductStyleParentCommand;
use TriCab\Core\Product\Application\Command\CompleteStyleMediaUploadCommand;
use TriCab\Core\Product\Application\Command\DeleteStyleMediaAssetCommand;
use TriCab\Core\Product\Application\Command\InitiateStyleMediaUploadCommand;
use TriCab\Core\Product\Application\Command\MakeLocalCopyStyleResourceCommand;
use TriCab\Core\Product\Application\Command\ProductStyleStatusCommand;
use TriCab\Core\Product\Application\Command\ReorderProductStylesCommand;
use TriCab\Core\Product\Application\Command\ReplaceStyleSpecificationValuesCommand;
use TriCab\Core\Product\Application\Command\UpdateStyleInheritanceCommand;
use TriCab\Core\Product\Application\Command\UpsertProductStyleCommand;
use TriCab\Core\Product\Application\DTO\EffectiveStyleAsset;
use TriCab\Core\Product\Application\Service\AssignProductStyleService;
use TriCab\Core\Product\Application\Service\CompleteStyleMediaUploadService;
use TriCab\Core\Product\Application\Service\DeleteStyleMediaAssetService;
use TriCab\Core\Product\Application\Service\DiscoverExistingProductStyleMappingService;
use TriCab\Core\Product\Application\Service\DiscoverProductCodeStyleMappingService;
use TriCab\Core\Product\Application\Service\DiscoverProductStyleFamilyTreeService;
use TriCab\Core\Product\Application\Service\ApplyProductCodeStyleMappingService;
use TriCab\Core\Product\Application\Service\DiscoverLegacyStyleHierarchyService;
use TriCab\Core\Product\Application\Service\InitiateStyleMediaUploadService;
use TriCab\Core\Product\Application\Service\MakeLocalCopyStyleResourceService;
use TriCab\Core\Product\Application\Service\ManageProductStyleService;
use TriCab\Core\Product\Application\Service\ResolveEffectiveStyleResourcesService;
use TriCab\Core\Product\Application\Service\ReviewLegacyStyleHierarchyEvidenceService;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\StyleResourceType;

final class ProductStylesAdminAction
{
    public function __construct(
        private readonly ManageProductStyleService $styles,
        private readonly ResolveEffectiveStyleResourcesService $effective,
        private readonly MakeLocalCopyStyleResourceService $makeLocalCopy,
        private readonly DiscoverLegacyStyleHierarchyService $discovery,
        private readonly ReviewLegacyStyleHierarchyEvidenceService $evidenceReview,
        private readonly DiscoverExistingProductStyleMappingService $productMapping,
        private readonly DiscoverProductCodeStyleMappingService $productCodeMapping,
        private readonly DiscoverProductStyleFamilyTreeService $familyTree,
        private readonly ApplyProductCodeStyleMappingService $productCodeApply,
        private readonly AssignProductStyleService $assignments,
        private readonly InitiateStyleMediaUploadService $initiateMedia,
        private readonly CompleteStyleMediaUploadService $completeMedia,
        private readonly DeleteStyleMediaAssetService $deleteMedia,
        private readonly ProductDevMediaUrlResolver $devUrls,
        private readonly Settings $settings,
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

            if ($op === 'tree') {
                return $this->responder->success([
                    'items' => array_map(ProductManagementDtoSerializer::styleTreeNode(...), $this->styles->tree(self::includeDeleted($request))),
                ]);
            }
            if ($op === 'list') {
                return $this->responder->success([
                    'items' => array_map(ProductManagementDtoSerializer::styleRecord(...), $this->styles->list(self::includeDeleted($request))),
                ]);
            }
            if ($op === 'discovery-preview') {
                $body = self::optionalBody($request);
                $extra = [];
                if (isset($body['styleCodes']) && is_array($body['styleCodes'])) {
                    foreach ($body['styleCodes'] as $code) {
                        if (is_string($code)) {
                            $extra[] = $code;
                        }
                    }
                }
                $preview = $this->discovery->preview($extra);

                return $this->responder->success(ProductManagementDtoSerializer::styleDiscoveryPreview($preview));
            }
            if ($op === 'discovery-evidence') {
                $body = self::optionalBody($request);
                $extra = [];
                if (isset($body['styleCodes']) && is_array($body['styleCodes'])) {
                    foreach ($body['styleCodes'] as $code) {
                        if (is_string($code)) {
                            $extra[] = $code;
                        }
                    }
                }
                $review = $this->evidenceReview->review($extra);

                return $this->responder->success(ProductManagementDtoSerializer::styleHierarchyEvidenceReview($review));
            }
            if ($op === 'discovery-product-mapping') {
                $mapping = $this->productMapping->dryRun();

                return $this->responder->success(ProductManagementDtoSerializer::productStyleMappingDryRun($mapping));
            }
            if ($op === 'discovery-product-code-mapping') {
                $mapping = $this->productCodeMapping->dryRun();

                return $this->responder->success(ProductManagementDtoSerializer::productCodeStyleMappingDryRun($mapping));
            }
            if ($op === 'discovery-family-tree') {
                $tree = $this->familyTree->dryRun();

                return $this->responder->success(ProductManagementDtoSerializer::styleFamilyTreeDryRun($tree));
            }
            if ($op === 'discovery-apply-product-code-mapping') {
                $body = JsonRequestBody::requireObject($request);
                $confirmed = ($body['confirmed'] ?? false) === true;
                if (!$confirmed) {
                    throw InvalidProductValue::for('confirmed', 'false');
                }
                $create = is_array($body['createStyles'] ?? null) ? $body['createStyles'] : [];
                $assign = is_array($body['assignments'] ?? null) ? $body['assignments'] : [];
                $replace = is_array($body['replaceAssignments'] ?? null) ? $body['replaceAssignments'] : [];
                $results = $this->transaction->run(
                    fn () => $this->productCodeApply->apply($actor, true, $create, $assign, $replace),
                );

                return $this->responder->success($results);
            }
            if ($op === 'discovery-apply-assignments') {
                $body = JsonRequestBody::requireObject($request);
                $confirmed = ($body['confirmed'] ?? false) === true;
                if (!$confirmed) {
                    throw InvalidProductValue::for('confirmed', 'false');
                }
                $assignments = [];
                $raw = $body['assignments'] ?? [];
                if (is_array($raw)) {
                    foreach ($raw as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $assignments[] = [
                            'productPublicId' => is_string($row['productPublicId'] ?? null) ? $row['productPublicId'] : '',
                            'productStylePublicId' => is_string($row['productStylePublicId'] ?? null) ? $row['productStylePublicId'] : '',
                        ];
                    }
                }
                $results = $this->transaction->run(
                    fn () => $this->assignments->applySelected($actor, $assignments, true),
                );

                return $this->responder->success([
                    'items' => $results,
                    'writeGuard' => [
                        'wordpressWrites' => 0,
                        'tcWrites' => 0,
                        'forbiddenWriteAttempts' => 0,
                    ],
                ]);
            }
            if ($op === 'discovery-apply') {
                $body = JsonRequestBody::requireObject($request);
                $confirmed = ($body['confirmed'] ?? false) === true;
                if (!$confirmed) {
                    throw InvalidProductValue::for('confirmed', 'false');
                }
                $approved = [];
                $raw = $body['relationships'] ?? $body['approved'] ?? [];
                if (is_array($raw)) {
                    foreach ($raw as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $approved[] = [
                            'parentStyleCode' => is_string($row['parentStyleCode'] ?? null) ? $row['parentStyleCode'] : '',
                            'childStyleCode' => is_string($row['childStyleCode'] ?? null) ? $row['childStyleCode'] : '',
                            'parentStylePublicId' => is_string($row['parentStylePublicId'] ?? null) ? $row['parentStylePublicId'] : '',
                            'childStylePublicId' => is_string($row['childStylePublicId'] ?? null) ? $row['childStylePublicId'] : '',
                        ];
                    }
                }
                $results = $this->transaction->run(
                    fn () => $this->discovery->applyApprovedHierarchy($actor, $approved, true),
                );

                return $this->responder->success(['items' => $results, 'writeGuard' => [
                    'wordpressWrites' => 0,
                    'tcWrites' => 0,
                    'forbiddenWriteAttempts' => 0,
                    'persistedHierarchyLinksOnly' => true,
                ]]);
            }
            if ($op === 'create') {
                $input = UpsertProductStyleRequest::fromArray(JsonRequestBody::requireObject($request));
                $created = $this->transaction->run(fn () => $this->styles->create($actor, new UpsertProductStyleCommand(
                    styleCode: $input->styleCode,
                    displayName: $input->displayName,
                    parentPublicId: $input->parentPublicId,
                    sortOrder: $input->sortOrder,
                    isActive: $input->isActive,
                )));

                return $this->responder->success(ProductManagementDtoSerializer::styleRecord($created), 201);
            }
            if ($op === 'reorder') {
                $body = JsonRequestBody::requireObject($request);
                $ids = $body['orderedPublicIds'] ?? [];
                $ordered = is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
                $parent = is_string($body['parentPublicId'] ?? null) ? $body['parentPublicId'] : null;
                $this->transaction->run(fn () => $this->styles->reorder($actor, new ReorderProductStylesCommand($ordered, $parent)));

                return $this->responder->success(['acknowledged' => true]);
            }
            if ($op === 'product-get') {
                $productPublicId = RouteParams::require($request, 'productPublicId');
                $specs = $this->effective->specificationsForProduct($productPublicId);

                return $this->responder->success(ProductManagementDtoSerializer::productStyleInheritance(
                    $this->assignments->getAssignment($productPublicId),
                    $specs,
                    $this->effective->assetsForProduct($productPublicId, StyleResourceType::images()),
                    $this->effective->specificationSummary($specs),
                    $this->effective->assetsForProduct($productPublicId, StyleResourceType::fromString(StyleResourceType::DOCUMENTS)),
                    $this->effective->assetsForProduct($productPublicId, StyleResourceType::fromString(StyleResourceType::SPIN_360)),
                    $this->effective->assetsForProduct($productPublicId, StyleResourceType::fromString(StyleResourceType::MODELS_3D)),
                    $this->effective->assetsForProduct($productPublicId, StyleResourceType::fromString(StyleResourceType::AR_ASSETS)),
                    $this->devUrls,
                ));
            }
            if ($op === 'product-assign') {
                $productPublicId = RouteParams::require($request, 'productPublicId');
                $body = JsonRequestBody::requireObject($request);
                $stylePublicId = is_string($body['stylePublicId'] ?? null) ? $body['stylePublicId'] : null;
                $assignment = $this->transaction->run(fn () => $this->assignments->execute(
                    $actor,
                    new AssignProductStyleCommand($productPublicId, $stylePublicId),
                ));

                return $this->responder->success([
                    'assignment' => $assignment === null ? null : ProductManagementDtoSerializer::styleAssignment($assignment),
                ]);
            }

            $id = RouteParams::require($request, 'stylePublicId');
            if ($op === 'get') {
                return $this->responder->success(ProductManagementDtoSerializer::styleRecord($this->styles->get($id)));
            }
            if ($op === 'usage') {
                return $this->responder->success(ProductManagementDtoSerializer::styleUsage($this->styles->getUsage($id)));
            }
            if ($op === 'effective') {
                $specs = $this->effective->specificationsForStyle($id);
                $images = $this->effective->assetsForStyle($id, StyleResourceType::images());
                $documents = $this->effective->assetsForStyle($id, StyleResourceType::fromString(StyleResourceType::DOCUMENTS));
                $spin = $this->effective->assetsForStyle($id, StyleResourceType::fromString(StyleResourceType::SPIN_360));
                $models = $this->effective->assetsForStyle($id, StyleResourceType::fromString(StyleResourceType::MODELS_3D));
                $ar = $this->effective->assetsForStyle($id, StyleResourceType::fromString(StyleResourceType::AR_ASSETS));

                return $this->responder->success([
                    'specifications' => array_map(ProductManagementDtoSerializer::effectiveSpecification(...), $specs),
                    'specificationSummary' => $this->effective->specificationSummary($specs),
                    'images' => $this->serializeAssets($images),
                    'documents' => $this->serializeAssets($documents),
                    'spin360' => $this->serializeAssets($spin),
                    'models3d' => $this->serializeAssets($models),
                    'arAssets' => $this->serializeAssets($ar),
                ]);
            }
            if ($op === 'update') {
                $input = UpsertProductStyleRequest::fromArray(JsonRequestBody::requireObject($request));
                $updated = $this->transaction->run(fn () => $this->styles->update($actor, new UpsertProductStyleCommand(
                    styleCode: $input->styleCode,
                    displayName: $input->displayName,
                    parentPublicId: $input->parentPublicId,
                    sortOrder: $input->sortOrder,
                    isActive: $input->isActive,
                    expectedRowVersion: $input->expectedRowVersion,
                    stylePublicId: $id,
                )));

                return $this->responder->success(ProductManagementDtoSerializer::styleRecord($updated));
            }
            if ($op === 'parent') {
                $body = JsonRequestBody::requireObject($request);
                $updated = $this->transaction->run(fn () => $this->styles->changeParent($actor, new ChangeProductStyleParentCommand(
                    stylePublicId: $id,
                    parentPublicId: is_string($body['parentPublicId'] ?? null) ? $body['parentPublicId'] : null,
                    expectedRowVersion: is_string($body['expectedRowVersion'] ?? null) ? $body['expectedRowVersion'] : '',
                )));

                return $this->responder->success(ProductManagementDtoSerializer::styleRecord($updated));
            }
            if ($op === 'inheritance') {
                $body = JsonRequestBody::requireObject($request);
                $modes = is_array($body['modes'] ?? null) ? $body['modes'] : $body;
                $typed = [];
                foreach ($modes as $key => $value) {
                    if (is_string($key) && is_string($value)) {
                        $typed[$key] = $value;
                    }
                }
                $updated = $this->transaction->run(fn () => $this->styles->updateInheritance($actor, new UpdateStyleInheritanceCommand(
                    stylePublicId: $id,
                    modes: $typed,
                    expectedRowVersion: is_string($body['expectedRowVersion'] ?? null) ? $body['expectedRowVersion'] : '',
                )));

                return $this->responder->success(ProductManagementDtoSerializer::styleRecord($updated));
            }
            if ($op === 'specifications') {
                $body = JsonRequestBody::requireObject($request);
                $values = is_array($body['values'] ?? null) ? $body['values'] : [];
                $updated = $this->transaction->run(fn () => $this->styles->replaceSpecifications($actor, new ReplaceStyleSpecificationValuesCommand(
                    stylePublicId: $id,
                    values: array_values(array_filter($values, 'is_array')),
                    expectedRowVersion: is_string($body['expectedRowVersion'] ?? null) ? $body['expectedRowVersion'] : '',
                )));

                return $this->responder->success(ProductManagementDtoSerializer::styleRecord($updated));
            }
            if ($op === 'make-local-copy') {
                $body = JsonRequestBody::requireObject($request);
                $updated = $this->transaction->run(fn () => $this->makeLocalCopy->execute($actor, new MakeLocalCopyStyleResourceCommand(
                    stylePublicId: $id,
                    resourceType: is_string($body['resourceType'] ?? null) ? $body['resourceType'] : '',
                    expectedRowVersion: is_string($body['expectedRowVersion'] ?? null) ? $body['expectedRowVersion'] : '',
                    confirmed: ($body['confirmed'] ?? false) === true,
                )));

                return $this->responder->success(ProductManagementDtoSerializer::styleRecord($updated));
            }
            if ($op === 'media-upload-session') {
                $input = InitiateStyleMediaUploadRequest::fromArray(JsonRequestBody::requireObject($request));
                $result = $this->transaction->run(fn () => $this->initiateMedia->execute(
                    $actor,
                    new InitiateStyleMediaUploadCommand(
                        stylePublicId: $id,
                        resourceType: $input->resourceType,
                        originalFileName: $input->originalFileName,
                        contentType: $input->contentType,
                        byteSize: $input->byteSize,
                        title: $input->title,
                        roleCode: $input->roleCode,
                        altText: $input->altText,
                        displayOrder: $input->displayOrder,
                        isPrimary: $input->isPrimary,
                        metadata: $input->metadata,
                        storageContainer: $this->settings->blobContainerProducts,
                        uploadExpiresInSeconds: $this->settings->blobUploadSasTtlSeconds,
                    ),
                ));

                return $this->responder->success(ProductManagementDtoSerializer::uploadSessionResult($result), 201);
            }
            if ($op === 'media-complete') {
                $assetPublicId = RouteParams::require($request, 'assetPublicId');
                $parsed = $request->getParsedBody();
                $body = is_array($parsed) ? $parsed : [];
                /** @var array<string, mixed> $body */
                $input = CompleteProductMediaUploadRequest::fromArray($body);
                $completed = $this->transaction->run(fn () => $this->completeMedia->execute(
                    $actor,
                    new CompleteStyleMediaUploadCommand(
                        stylePublicId: $id,
                        assetPublicId: $assetPublicId,
                        binaryContent: $input->binaryContent,
                    ),
                ));

                return $this->responder->success([
                    'publicId' => $completed['publicId'] ?? $assetPublicId,
                    'resourceType' => $completed['resourceType'] ?? null,
                    'title' => $completed['title'] ?? null,
                    'roleCode' => $completed['roleCode'] ?? null,
                    'storagePath' => $completed['storagePath'] ?? null,
                    'publicUrl' => $this->devUrls->resolvePublicUrl(
                        isset($completed['publicUrl']) && is_string($completed['publicUrl']) ? $completed['publicUrl'] : null,
                        is_string($completed['publicId'] ?? null) ? $completed['publicId'] : $assetPublicId,
                        isset($completed['storageContainer']) && is_string($completed['storageContainer']) ? $completed['storageContainer'] : null,
                        isset($completed['storagePath']) && is_string($completed['storagePath']) ? $completed['storagePath'] : null,
                    ),
                    'uploadStatusCode' => $completed['uploadStatusCode'] ?? 'active',
                ]);
            }
            if ($op === 'media-delete') {
                $assetPublicId = RouteParams::require($request, 'assetPublicId');
                $this->transaction->run(fn () => $this->deleteMedia->execute(
                    $actor,
                    new DeleteStyleMediaAssetCommand($id, $assetPublicId),
                ));

                return $this->responder->success(['acknowledged' => true]);
            }

            $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $rowVersion = is_string($body['expectedRowVersion'] ?? null) ? $body['expectedRowVersion'] : '';
            $cmd = new ProductStyleStatusCommand($id, $rowVersion);
            $result = $this->transaction->run(fn () => match ($op) {
                'activate' => $this->styles->activate($actor, $cmd),
                'deactivate' => $this->styles->deactivate($actor, $cmd),
                'archive' => $this->styles->archive($actor, $cmd),
                'restore' => $this->styles->restore($actor, $cmd),
                default => throw new \InvalidArgumentException('Unknown product style operation.'),
            });

            return $this->responder->success(ProductManagementDtoSerializer::styleRecord($result));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }

    private static function resolveOp(ServerRequestInterface $request): string
    {
        $path = rtrim($request->getUri()->getPath(), '/');
        $method = strtoupper($request->getMethod());
        if (str_contains($path, '/admin/products/') && str_ends_with($path, '/style-inheritance')) {
            return $method === 'PUT' ? 'product-assign' : 'product-get';
        }
        if (str_ends_with($path, '/discovery/preview')) {
            return 'discovery-preview';
        }
        if (str_ends_with($path, '/discovery/evidence-review')) {
            return 'discovery-evidence';
        }
        if (str_ends_with($path, '/discovery/product-code-mapping')) {
            return 'discovery-product-code-mapping';
        }
        if (str_ends_with($path, '/discovery/family-tree')) {
            return 'discovery-family-tree';
        }
        if (str_ends_with($path, '/discovery/apply-product-code-mapping')) {
            return 'discovery-apply-product-code-mapping';
        }
        if (str_ends_with($path, '/discovery/product-mapping')) {
            return 'discovery-product-mapping';
        }
        if (str_ends_with($path, '/discovery/apply-assignments')) {
            return 'discovery-apply-assignments';
        }
        if (str_ends_with($path, '/discovery/apply-hierarchy')) {
            return 'discovery-apply';
        }
        if (str_ends_with($path, '/media/upload-session')) {
            return 'media-upload-session';
        }
        if (str_contains($path, '/media/') && str_ends_with($path, '/complete-upload')) {
            return 'media-complete';
        }
        if ($method === 'DELETE' && str_contains($path, '/media/')) {
            return 'media-delete';
        }
        foreach ([
            'make-local-copy', 'effective-resources', 'specifications', 'inheritance', 'parent',
            'reorder', 'usage', 'activate', 'deactivate', 'archive', 'restore', 'tree',
        ] as $suffix) {
            if (str_ends_with($path, '/' . $suffix)) {
                return $suffix === 'effective-resources' ? 'effective' : $suffix;
            }
        }
        if ($method === 'POST') {
            return 'create';
        }
        if ($method === 'PUT' || $method === 'PATCH') {
            return 'update';
        }
        $id = $request->getAttribute('stylePublicId');
        if (is_string($id) && $id !== '') {
            return 'get';
        }

        return 'list';
    }

    private static function includeDeleted(ServerRequestInterface $request): bool
    {
        $query = $request->getQueryParams();

        return ($query['includeDeleted'] ?? '') === 'true' || ($query['includeDeleted'] ?? '') === '1';
    }

    /**
     * @return array<string, mixed>
     */
    private static function optionalBody(ServerRequestInterface $request): array
    {
        try {
            return JsonRequestBody::requireObject($request);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param list<EffectiveStyleAsset> $assets
     * @return list<array<string, mixed>>
     */
    private function serializeAssets(array $assets): array
    {
        return array_map(
            fn (EffectiveStyleAsset $item): array => ProductManagementDtoSerializer::effectiveAsset($item, $this->devUrls),
            $assets,
        );
    }
}
