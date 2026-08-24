<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin\Migration;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Migration\Application\Command\CreateMigrationBatchCommand;
use TriCab\Core\Product\Migration\Application\Exception\InvalidMigrationBatchState;
use TriCab\Core\Product\Migration\Application\Exception\MigrationBatchNotFound;
use TriCab\Core\Product\Migration\Application\Exception\MigrationIssueNotFound;
use TriCab\Core\Product\Migration\Application\Service\GetCatalogueReadinessService;
use TriCab\Core\Product\Migration\Application\Service\InspectLegacyCatalogueService;
use TriCab\Core\Product\Migration\Application\Service\DiscoverLegacyMasterDataService;
use TriCab\Core\Product\Migration\Application\Service\BuildCatalogueMappingReadinessService;
use TriCab\Core\Product\Migration\Application\Service\PlanProductMigrationMasterDataService;
use TriCab\Core\Product\Migration\Application\Service\PlanBatchAClassificationService;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD3CliGuard;
use TriCab\Core\Product\Migration\Application\Service\ResolveBatchBSpecificationMasterService;
use TriCab\Core\Product\Migration\Application\Exception\LegacyDatabaseWriteAttemptException;
use TriCab\Core\Product\Migration\Support\MigrationPayload;
use TriCab\Core\Product\Migration\Application\Service\ProductMigrationService;

final class ProductMigrationAdminAction
{
    public function __construct(
        private readonly ProductMigrationService $service,
        private readonly GetCatalogueReadinessService $readiness,
        private readonly InspectLegacyCatalogueService $inspection,
        private readonly DiscoverLegacyMasterDataService $discovery,
        private readonly BuildCatalogueMappingReadinessService $mappingReadiness,
        private readonly PlanProductMigrationMasterDataService $masterDataPlan,
        private readonly PlanBatchAClassificationService $batchAClassification,
        private readonly ResolveBatchBSpecificationMasterService $batchBSpecification,
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

            if ($op === 'readiness') {
                return $this->responder->success($this->readiness->execute()->toArray());
            }
            if ($op === 'inspect') {
                return $this->responder->success($this->inspection->execute()->toArray());
            }
            if ($op === 'master-data-discovery') {
                return $this->responder->success($this->discovery->execute()->toArray());
            }
            if ($op === 'catalogue-mapping-readiness') {
                return $this->responder->success($this->mappingReadiness->execute()->toArray());
            }
            if ($op === 'master-data-plan-dry-run') {
                $candidates = [
                    dirname(__DIR__, 5) . '/core/docs/phase12.6-mappings/readiness-report.json',
                    dirname(__DIR__, 6) . '/core/docs/phase12.6-mappings/readiness-report.json',
                ];
                $phase126Payload = [];
                foreach ($candidates as $phase126Path) {
                    if (!is_file($phase126Path)) {
                        continue;
                    }
                    $decoded = json_decode((string) file_get_contents($phase126Path), true);
                    if (is_array($decoded) && is_array($decoded['payload'] ?? null)) {
                        $phase126Payload = $decoded['payload'];
                        break;
                    }
                }
                $report = $this->masterDataPlan->dryRun(
                    $phase126Payload,
                    [],
                    [
                        'server' => 'api-dry-run',
                        'database' => 'api-dry-run',
                        'environmentLabel' => 'UNKNOWN',
                    ],
                );

                return $this->responder->success($report->toArray());
            }
            if ($op === 'batch-a-classification') {
                return $this->responder->success($this->runBatchADryRun());
            }
            if ($op === 'batch-b-specification-masters') {
                return $this->responder->success($this->loadBatchBPackage());
            }
            if ($op === 'batch-b-decision') {
                $body = JsonRequestBody::requireObject($request);
                return $this->responder->success($this->recordBatchBDecision($body));
            }
            if ($op === 'batch-c-style-field-mappings') {
                return $this->responder->success($this->loadBatchCPackage());
            }
            if ($op === 'batch-c-decision') {
                $body = JsonRequestBody::requireObject($request);
                return $this->responder->success($this->recordBatchCDecision($body));
            }
            if ($op === 'batch-c1-unresolved-concepts') {
                return $this->responder->success($this->loadBatchC1Package());
            }
            if ($op === 'batch-c1-decision') {
                $body = JsonRequestBody::requireObject($request);
                return $this->responder->success($this->recordBatchC1Decision($body));
            }
            if ($op === 'batch-c2-final-package') {
                return $this->responder->success($this->loadBatchC2Package());
            }
            if ($op === 'batch-c2-decision') {
                $body = JsonRequestBody::requireObject($request);
                return $this->responder->success($this->recordBatchC2Decision($body));
            }
            if ($op === 'cable-spec-field-mappings') {
                return $this->responder->success($this->loadCableSpecPackage());
            }
            if ($op === 'cable-spec-d2-review') {
                return $this->responder->success($this->loadCableSpecD2Package());
            }
            if ($op === 'cable-spec-d2-decisions') {
                return $this->responder->success($this->loadCableSpecD2DecisionsPackage());
            }
            if ($op === 'cable-spec-d3-final') {
                return $this->responder->success($this->loadCableSpecD3FinalPackage());
            }
            if ($op === 'cable-spec-d4-apply') {
                return $this->responder->success($this->loadCableSpecD4ApplyReport());
            }
            if ($op === 'cable-spec-d5-review') {
                return $this->responder->success($this->loadCableSpecD5ReviewPackage());
            }
            if ($op === 'cable-spec-d5-1-final') {
                return $this->responder->success($this->loadCableSpecD51FinalPackage());
            }
            if ($op === 'pilot-dry-run') {
                return $this->responder->success($this->loadPilotDryRunPackage());
            }
            if ($op === 'catalogue-navigation-dry-run') {
                return $this->responder->success($this->loadCatalogueNavigationDryRunPackage());
            }
            if ($op === 'pilot-f12-apply') {
                return $this->responder->success($this->loadPilotF12ApplyReport());
            }
            if ($op === 'pilot-f12-live-sku') {
                return $this->responder->success($this->loadPilotF12LiveSkuPackage());
            }
            if ($op === 'cable-spec-decision') {
                $body = JsonRequestBody::requireObject($request);
                return $this->responder->success($this->recordCableSpecDecision($body));
            }
            if ($op === 'batch-a-decision') {
                $body = JsonRequestBody::requireObject($request);
                $entityType = MigrationPayload::string($body['entityType'] ?? 'category');
                $sourceKey = MigrationPayload::string($body['sourceKey'] ?? $body['legacyClassificationValue'] ?? '');
                $decision = MigrationPayload::string($body['decision'] ?? 'REVIEW');
                $reason = MigrationPayload::string($body['reason'] ?? '');
                $rowVersion = isset($body['rowVersion']) ? MigrationPayload::string($body['rowVersion']) : null;
                $mappingBody = [
                    'sourceSystem' => MigrationPayload::string($body['sourceSystem'] ?? 'wordpress'),
                    'legacyClassificationValue' => $sourceKey,
                    'legacyTaxonomy' => MigrationPayload::stringOrNull($body['legacyTaxonomy'] ?? $entityType),
                    'targetFamilyPublicId' => MigrationPayload::stringOrNull($body['targetFamilyPublicId'] ?? null),
                    'targetCategoryPublicId' => MigrationPayload::stringOrNull($body['targetCategoryPublicId'] ?? null),
                    'targetSeriesPublicId' => MigrationPayload::stringOrNull($body['targetSeriesPublicId'] ?? null),
                    'statusCode' => $this->mapDecisionToStatusCode($decision),
                    'notes' => json_encode([
                        'batch' => '12.7-batch-a',
                        'decision' => $decision,
                        'reason' => $reason,
                        'entityType' => $entityType,
                        'approvedAtUtc' => gmdate('c'),
                        'rowVersion' => $rowVersion,
                    ], JSON_UNESCAPED_SLASHES),
                    'mappedByUserId' => $actor->userId,
                ];
                if ($rowVersion !== null && $rowVersion !== '') {
                    $mappingBody['rowVersion'] = $rowVersion;
                }
                $id = MigrationPayload::string($body['mappingPublicId'] ?? 'new');

                return $this->responder->success($this->transaction->run(
                    fn () => $this->service->upsertClassificationMapping($actor, $id, $mappingBody),
                ));
            }
            if ($op === 'batch-a-bulk-validate') {
                $body = JsonRequestBody::requireObject($request);
                $entityType = MigrationPayload::string($body['entityType'] ?? 'category');
                $decision = is_array($body['decision'] ?? null) ? $body['decision'] : [];
                $candidates = is_array($body['candidates'] ?? null) ? $body['candidates'] : [];
                $result = $this->batchAClassification->validateBulkApproval($entityType, $decision, $candidates);

                return $this->responder->success($result);
            }
            if ($op === 'list-batches') {
                $status = $request->getQueryParams()['statusCode'] ?? null;
                $items = array_map(
                    static fn ($b) => $b->toArray(),
                    $this->service->listBatches(is_string($status) ? $status : null),
                );

                return $this->responder->success(['items' => $items]);
            }
            if ($op === 'create-batch') {
                $body = JsonRequestBody::requireObject($request);
                $created = $this->transaction->run(fn () => $this->service->createBatch($actor, new CreateMigrationBatchCommand(
                    name: MigrationPayload::string($body['name'] ?? ''),
                    sourceSystem: MigrationPayload::string($body['sourceSystem'] ?? 'combined', 'combined'),
                    description: isset($body['description']) ? MigrationPayload::string($body['description']) : null,
                    sourceFilter: is_array($body['sourceFilter'] ?? null) ? MigrationPayload::assoc($body['sourceFilter']) : null,
                    dryRun: (bool) ($body['dryRun'] ?? true),
                )));

                return $this->responder->success($created->toArray(), 201);
            }
            if ($op === 'get-batch') {
                $id = RouteParams::require($request, 'batchPublicId');

                return $this->responder->success($this->service->getBatch($id)->toArray());
            }
            if ($op === 'extract') {
                $id = RouteParams::require($request, 'batchPublicId');

                return $this->responder->success($this->transaction->run(fn () => $this->service->extract($actor, $id))->toArray());
            }
            if ($op === 'normalize') {
                $id = RouteParams::require($request, 'batchPublicId');

                return $this->responder->success($this->transaction->run(fn () => $this->service->normalize($actor, $id))->toArray());
            }
            if ($op === 'validate') {
                $id = RouteParams::require($request, 'batchPublicId');

                return $this->responder->success($this->transaction->run(fn () => $this->service->validate($actor, $id))->toArray());
            }
            if ($op === 'dry-run') {
                $id = RouteParams::require($request, 'batchPublicId');
                $report = $this->transaction->run(fn () => $this->service->dryRun($actor, $id));
                $format = $request->getQueryParams()['format'] ?? 'json';
                if ($format === 'csv') {
                    return $this->responder->success(['csv' => $report->toCsv(), 'report' => $report->toArray()]);
                }

                return $this->responder->success($report->toArray());
            }
            if ($op === 'execute') {
                $id = RouteParams::require($request, 'batchPublicId');

                return $this->responder->success($this->transaction->run(fn () => $this->service->execute($actor, $id))->toArray());
            }
            if ($op === 'cancel') {
                $id = RouteParams::require($request, 'batchPublicId');

                return $this->responder->success($this->transaction->run(fn () => $this->service->cancel($actor, $id))->toArray());
            }
            if ($op === 'retry') {
                $id = RouteParams::require($request, 'batchPublicId');

                return $this->responder->success($this->transaction->run(fn () => $this->service->retry($actor, $id))->toArray());
            }
            if ($op === 'rollback') {
                $id = RouteParams::require($request, 'batchPublicId');
                $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
                $force = (bool) ($body['forceProtected'] ?? false);
                $result = $this->transaction->run(fn () => $this->service->rollback($actor, $id, $force));

                return $this->responder->success($result);
            }
            if ($op === 'issues') {
                $id = RouteParams::require($request, 'batchPublicId');
                $status = $request->getQueryParams()['statusCode'] ?? 'open';
                $items = array_map(
                    static fn ($i) => $i->toArray(),
                    $this->service->listIssues($id, is_string($status) ? $status : 'open'),
                );

                return $this->responder->success(['items' => $items]);
            }
            if ($op === 'reconciliation') {
                $id = RouteParams::require($request, 'batchPublicId');
                $existing = $this->service->getLatestReconciliation($id);
                if ($existing === null || (($request->getQueryParams()['refresh'] ?? null) === '1')) {
                    $existing = $this->transaction->run(fn () => $this->service->reconcile($actor, $id));
                }

                return $this->responder->success($existing);
            }
            if ($op === 'list-review') {
                $status = $request->getQueryParams()['statusCode'] ?? 'open';
                $items = array_map(
                    static fn ($i) => $i->toArray(),
                    $this->service->listIssues(null, is_string($status) ? $status : 'open'),
                );

                return $this->responder->success(['items' => $items]);
            }
            if ($op === 'resolve-review') {
                $id = RouteParams::require($request, 'issuePublicId');
                $body = JsonRequestBody::requireObject($request);
                $issue = $this->transaction->run(fn () => $this->service->resolveIssue($actor, $id, $body));

                return $this->responder->success($issue->toArray());
            }
            if ($op === 'list-classification-mappings') {
                return $this->responder->success(['items' => $this->service->listClassificationMappings()]);
            }
            if ($op === 'put-classification-mapping') {
                $id = RouteParams::require($request, 'mappingPublicId');
                $body = JsonRequestBody::requireObject($request);

                return $this->responder->success($this->transaction->run(fn () => $this->service->upsertClassificationMapping($actor, $id, $body)));
            }
            if ($op === 'list-specification-mappings') {
                return $this->responder->success(['items' => $this->service->listSpecificationMappings()]);
            }
            if ($op === 'put-specification-mapping') {
                $id = RouteParams::require($request, 'mappingPublicId');
                $body = JsonRequestBody::requireObject($request);

                return $this->responder->success($this->transaction->run(fn () => $this->service->upsertSpecificationMapping($actor, $id, $body)));
            }
            if ($op === 'list-option-aliases') {
                return $this->responder->success(['items' => $this->service->listOptionAliases()]);
            }
            if ($op === 'put-option-alias') {
                $id = RouteParams::require($request, 'mappingPublicId');
                $body = JsonRequestBody::requireObject($request);

                return $this->responder->success($this->transaction->run(fn () => $this->service->upsertOptionAlias($actor, $id, $body)));
            }

            throw new \InvalidArgumentException('Unknown product migration operation.');
        } catch (MigrationBatchNotFound | MigrationIssueNotFound $e) {
            return $this->responder->error('not_found', $e->getMessage(), 404);
        } catch (InvalidMigrationBatchState $e) {
            return $this->responder->error('conflict', $e->getMessage(), 409);
        } catch (LegacyDatabaseWriteAttemptException $e) {
            return $this->responder->error('legacy_write_blocked', $e->getMessage(), 400);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }

    private static function resolveOp(ServerRequestInterface $request): string
    {
        $path = rtrim($request->getUri()->getPath(), '/');
        $method = strtoupper($request->getMethod());

        if (str_ends_with($path, '/product-migration/readiness')) {
            return 'readiness';
        }
        if (str_ends_with($path, '/product-migration/inspect')) {
            return 'inspect';
        }
        if (str_ends_with($path, '/product-migration/master-data-discovery')) {
            return 'master-data-discovery';
        }
        if (str_ends_with($path, '/product-migration/catalogue-mapping-readiness')) {
            return 'catalogue-mapping-readiness';
        }
        if (str_ends_with($path, '/product-migration/master-data-plan-dry-run')) {
            return 'master-data-plan-dry-run';
        }
        if (str_ends_with($path, '/product-migration/batch-a-classification/bulk-validate')) {
            return 'batch-a-bulk-validate';
        }
        if (str_ends_with($path, '/product-migration/batch-a-classification/decisions')) {
            return 'batch-a-decision';
        }
        if (str_ends_with($path, '/product-migration/batch-a-classification')) {
            return 'batch-a-classification';
        }
        if (str_ends_with($path, '/product-migration/batch-b-specification-masters/decisions')) {
            return 'batch-b-decision';
        }
        if (str_ends_with($path, '/product-migration/batch-b-specification-masters')) {
            return 'batch-b-specification-masters';
        }
        if (str_ends_with($path, '/product-migration/batch-c-style-field-mappings/decisions')) {
            return 'batch-c-decision';
        }
        if (str_ends_with($path, '/product-migration/batch-c-style-field-mappings')) {
            return 'batch-c-style-field-mappings';
        }
        if (str_ends_with($path, '/product-migration/batch-c1-unresolved-concepts/decisions')) {
            return 'batch-c1-decision';
        }
        if (str_ends_with($path, '/product-migration/batch-c1-unresolved-concepts')) {
            return 'batch-c1-unresolved-concepts';
        }
        if (str_ends_with($path, '/product-migration/batch-c2-final-package/decisions')) {
            return 'batch-c2-decision';
        }
        if (str_ends_with($path, '/product-migration/batch-c2-final-package')) {
            return 'batch-c2-final-package';
        }
        if (str_ends_with($path, '/product-migration/cable-spec-field-mappings/decisions')) {
            return 'cable-spec-decision';
        }
        if (str_ends_with($path, '/product-migration/cable-spec-field-mappings')) {
            return 'cable-spec-field-mappings';
        }
        if (str_ends_with($path, '/product-migration/cable-spec-d2-review')) {
            return 'cable-spec-d2-review';
        }
        if (str_ends_with($path, '/product-migration/cable-spec-d2-decisions')) {
            return 'cable-spec-d2-decisions';
        }
        if (str_ends_with($path, '/product-migration/cable-spec-d3-final')) {
            return 'cable-spec-d3-final';
        }
        if (str_ends_with($path, '/product-migration/cable-spec-d4-apply')) {
            return 'cable-spec-d4-apply';
        }
        if (str_ends_with($path, '/product-migration/cable-spec-d5-review')) {
            return 'cable-spec-d5-review';
        }
        if (str_ends_with($path, '/product-migration/cable-spec-d5-1-final')) {
            return 'cable-spec-d5-1-final';
        }
        if (str_ends_with($path, '/product-migration/pilot-dry-run')) {
            return 'pilot-dry-run';
        }
        if (str_ends_with($path, '/product-migration/catalogue-navigation-dry-run')) {
            return 'catalogue-navigation-dry-run';
        }
        if (str_ends_with($path, '/product-migration/pilot-f12-apply')) {
            return 'pilot-f12-apply';
        }
        if (str_ends_with($path, '/product-migration/pilot-f12-live-sku')) {
            return 'pilot-f12-live-sku';
        }
        if (str_contains($path, '/product-migration/review')) {
            if ($method === 'POST' && str_contains($path, '/resolve')) {
                return 'resolve-review';
            }

            return 'list-review';
        }
        if (str_contains($path, '/mappings/classifications')) {
            return $method === 'PUT' ? 'put-classification-mapping' : 'list-classification-mappings';
        }
        if (str_contains($path, '/mappings/specifications')) {
            return $method === 'PUT' ? 'put-specification-mapping' : 'list-specification-mappings';
        }
        if (str_contains($path, '/mappings/options')) {
            return $method === 'PUT' ? 'put-option-alias' : 'list-option-aliases';
        }

        foreach (['extract', 'normalize', 'validate', 'dry-run', 'execute', 'cancel', 'retry', 'rollback', 'issues', 'reconciliation'] as $suffix) {
            if (str_ends_with($path, '/' . $suffix)) {
                return $suffix;
            }
        }

        $args = $request->getAttributes();
        $hasBatch = isset($args['batchPublicId']) && is_string($args['batchPublicId']) && $args['batchPublicId'] !== '';
        if ($method === 'POST' && !$hasBatch) {
            return 'create-batch';
        }
        if ($hasBatch) {
            return 'get-batch';
        }

        return 'list-batches';
    }

    /**
     * @return array<string, mixed>
     */
    private function runBatchADryRun(): array
    {
        $docsCandidates = [
            dirname(__DIR__, 5) . '/core/docs',
            dirname(__DIR__, 6) . '/core/docs',
        ];
        $docsRoot = null;
        foreach ($docsCandidates as $candidate) {
            if (is_dir($candidate . '/phase12.6-mappings')) {
                $docsRoot = $candidate;
                break;
            }
        }

        // Prefer Batch A.1 resolution artifacts when present (operator review UI).
        $resolutionPath = ($docsRoot ?? '') . '/phase12.7/batch-a-category-resolution.json';
        $seriesResolutionPath = ($docsRoot ?? '') . '/phase12.7/batch-a-series-resolution.json';
        $previewPath = ($docsRoot ?? '') . '/phase12.7/batch-a-product-classification-preview.json';
        if (is_file($resolutionPath)) {
            $load = static function (string $path): array {
                if (!is_file($path)) {
                    return [];
                }
                $decoded = json_decode((string) file_get_contents($path), true);

                return is_array($decoded) ? $decoded : [];
            };
            $resolution = $load($resolutionPath);
            $seriesRes = $load($seriesResolutionPath);
            $preview = $load($previewPath);

            return [
                'phase' => '12.7-batch-a.1',
                'mode' => 'CATEGORY_RESOLUTION + SERIES_RE_EVALUATION',
                'applied' => false,
                'legacyWrites' => 0,
                'productWrites' => 0,
                'variantWrites' => 0,
                'catalogueWrites' => 0,
                'payload' => [
                    'targetLiveValidation' => $resolution['targetLiveValidation'] ?? 'UNKNOWN',
                    'targetInspection' => $resolution['targetInspection'] ?? [],
                    'categories' => [
                        'counts' => $resolution['counts'] ?? [],
                        'items' => $resolution['items'] ?? [],
                        'proposedCreates' => $resolution['proposedCreates'] ?? [],
                    ],
                    'series' => [
                        'counts' => $seriesRes['counts'] ?? [],
                        'items' => $seriesRes['items'] ?? [],
                    ],
                    'productClassificationPreview' => $preview !== [] ? $preview : ['counts' => [], 'items' => []],
                    'duplicates' => $resolution['duplicates'] ?? [],
                    'conflicts' => [],
                    'approvalBoundary' => $resolution['approvalBoundary'] ?? [],
                    'dryRunTargetPlan' => [
                        'note' => 'Mapping proposals only — Azure Category/Series apply NOT RUN',
                        'categories' => [
                            'mapExisting' => (int) (($resolution['counts']['APPROVED_MAP_EXISTING'] ?? 0)),
                            'create' => (int) (($resolution['counts']['APPROVED_CREATE'] ?? 0)),
                            'proposedCreate' => (int) (($resolution['counts']['PROPOSED_CREATE'] ?? 0)),
                        ],
                        'series' => [
                            'mapExisting' => (int) (($seriesRes['counts']['APPROVED_MAP_EXISTING'] ?? 0)),
                            'create' => (int) (($seriesRes['counts']['APPROVED_CREATE'] ?? 0)),
                        ],
                        'products' => ['mutations' => 0],
                        'variants' => ['mutations' => 0],
                        'catalogue' => ['mutations' => 0],
                    ],
                ],
            ];
        }

        $phase126Dir = ($docsRoot ?? '') . '/phase12.6-mappings';
        $load = static function (string $path): array {
            if (!is_file($path)) {
                return [];
            }
            $decoded = json_decode((string) file_get_contents($path), true);

            return is_array($decoded) ? $decoded : [];
        };
        $readiness = $load($phase126Dir . '/readiness-report.json');
        $phase126Payload = is_array($readiness['payload'] ?? null) ? $readiness['payload'] : [];
        $phase126 = $phase126Payload;
        $phase126['classification'] = [
            'categories' => $load($phase126Dir . '/category-mappings.proposed.json'),
            'series' => $load($phase126Dir . '/series-mappings.proposed.json'),
            'families' => $load($phase126Dir . '/family-mappings.proposed.json'),
        ];
        $identity = $load($phase126Dir . '/product-identity.proposed.json');
        if ($identity !== []) {
            $phase126['productIdentity'] = $identity;
        }
        $discovery = $load(($docsRoot ?? '') . '/pre-migration-mappings/discovery-report.json');
        $discoveryAzure = [];
        if (isset($discovery['azure']) && is_array($discovery['azure'])) {
            $discoveryAzure = $discovery['azure'];
        } elseif (isset($discovery['payload']['azure']) && is_array($discovery['payload']['azure'])) {
            $discoveryAzure = $discovery['payload']['azure'];
        }
        $families = $discoveryAzure['families'] ?? [
            ['code' => 'CABLE', 'name' => 'Cables'],
            ['code' => 'PLUG', 'name' => 'Plugs & Sockets'],
        ];
        $hasAccessories = false;
        foreach ($families as $f) {
            if (strtoupper((string) ($f['code'] ?? '')) === 'ACCESSORIES') {
                $hasAccessories = true;
                break;
            }
        }
        if (!$hasAccessories) {
            $families[] = ['code' => 'ACCESSORIES', 'name' => 'Accessories'];
        }
        $azure = [
            'source' => 'api-artifact-snapshot',
            'families' => $families,
            'categories' => $discoveryAzure['categories'] ?? [],
            'series' => $discoveryAzure['series'] ?? [],
            'productCount' => 118,
            'variantCount' => 4178,
            'catalogueNodeCount' => 97,
        ];
        $report = $this->batchAClassification->dryRun(
            $phase126,
            $azure,
            [
                'server' => 'api-dry-run',
                'database' => 'api-dry-run',
                'environmentLabel' => 'NON-PRODUCTION',
            ],
        );

        return $report->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function loadBatchBPackage(): array
    {
        $path = dirname(__DIR__, 5) . '/core/docs/phase12.7/batch-b-final-package.json';
        if (!is_file($path)) {
            return [
                'phase' => '12.7-batch-b',
                'status' => 'NOT_GENERATED',
                'message' => 'Run php bin/resolve-batch-b-specification-masters.php --confirm-non-production first.',
                'approvalBoundary' => ['batchBApplyGuard' => 'BATCH_B_APPLY_NOT_APPROVED'],
            ];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : ['status' => 'INVALID_ARTIFACT'];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function recordBatchBDecision(array $body): array
    {
        $docs = dirname(__DIR__, 5) . '/core/docs/phase12.7';
        $decisionsPath = $docs . '/batch-b-operator-decisions.json';
        $existing = [];
        if (is_file($decisionsPath)) {
            $decoded = json_decode((string) file_get_contents($decisionsPath), true);
            $existing = is_array($decoded) ? $decoded : [];
        }
        $section = MigrationPayload::string($body['section'] ?? 'definitions');
        $key = MigrationPayload::string($body['key'] ?? $body['sourceField'] ?? $body['normalizedStandard'] ?? $body['normalizedApproval'] ?? '');
        $decision = MigrationPayload::string($body['decision'] ?? 'REVIEW');
        if ($key === '') {
            return ['ok' => false, 'error' => 'key_required'];
        }
        if (!isset($existing[$section]) || !is_array($existing[$section])) {
            $existing[$section] = [];
        }
        $existing[$section][$key] = [
            'decision' => $decision,
            'reason' => MigrationPayload::string($body['reason'] ?? 'operator_decision'),
            'existingTargetDefinition' => MigrationPayload::stringOrNull($body['existingTargetDefinition'] ?? null),
            'proposedDefinitionCode' => MigrationPayload::stringOrNull($body['proposedDefinitionCode'] ?? null),
            'updatedAtUtc' => gmdate('c'),
        ];
        if (!is_dir($docs)) {
            mkdir($docs, 0775, true);
        }
        file_put_contents($decisionsPath, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        // Patch latest package in-place for UI feedback (full regenerate via CLI).
        $package = $this->loadBatchBPackage();
        $listKey = match ($section) {
            'standards' => 'standards',
            'approvals' => 'approvals',
            'aliases' => 'aliases',
            'units' => 'units',
            default => 'definitions',
        };
        if (isset($package[$listKey]['items']) && is_array($package[$listKey]['items'])) {
            foreach ($package[$listKey]['items'] as &$item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemKey = MigrationPayload::string(
                    $item['sourceField']
                    ?? $item['normalizedStandard']
                    ?? $item['normalizedApproval']
                    ?? $item['rawValue']
                    ?? $item['rawUnit']
                    ?? '',
                );
                if ($itemKey === $key) {
                    $item['decision'] = $decision;
                    $item['reason'] = MigrationPayload::string($body['reason'] ?? 'operator_decision');
                }
            }
            unset($item);
            file_put_contents($docs . '/batch-b-final-package.json', json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        }

        return ['ok' => true, 'section' => $section, 'key' => $key, 'decision' => $decision, 'package' => $package];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadBatchCPackage(): array
    {
        $path = dirname(__DIR__, 5) . '/core/docs/phase12.7/batch-c-final-package.json';
        if (!is_file($path)) {
            return [
                'phase' => '12.7-batch-c',
                'status' => 'NOT_GENERATED',
                'message' => 'Run php bin/resolve-batch-c-style-field-mappings.php --confirm-non-production first.',
                'approvalBoundary' => ['batchCApplyGuard' => 'BATCH_C_APPLY_NOT_APPROVED'],
            ];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : ['status' => 'INVALID_ARTIFACT'];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function recordBatchCDecision(array $body): array
    {
        $docs = dirname(__DIR__, 5) . '/core/docs/phase12.7';
        $decisionsPath = $docs . '/batch-c-operator-decisions.json';
        $existing = [];
        if (is_file($decisionsPath)) {
            $decoded = json_decode((string) file_get_contents($decisionsPath), true);
            $existing = is_array($decoded) ? $decoded : [];
        }
        if (!isset($existing['decisions']) || !is_array($existing['decisions'])) {
            $existing['decisions'] = [];
        }

        $scope = MigrationPayload::string($body['scope'] ?? 'row'); // row | group
        $style = strtoupper(MigrationPayload::string($body['styleCode'] ?? ''));
        $field = strtolower(MigrationPayload::string($body['sourceField'] ?? ''));
        $groupKey = MigrationPayload::string($body['groupKey'] ?? '');
        $decision = MigrationPayload::string($body['decision'] ?? 'REVIEW');
        $targetCode = MigrationPayload::stringOrNull($body['targetDefinitionCode'] ?? null);

        if ($scope === 'group') {
            if ($groupKey === '') {
                return ['ok' => false, 'error' => 'groupKey_required'];
            }
            $key = 'group:' . $groupKey;
        } else {
            if ($style === '' || $field === '') {
                return ['ok' => false, 'error' => 'styleCode_and_sourceField_required'];
            }
            $key = 'tc|' . $style . '|' . $field;
        }

        $existing['decisions'][$key] = [
            'decision' => $decision,
            'targetDefinitionCode' => $targetCode,
            'reason' => MigrationPayload::string($body['reason'] ?? 'operator_decision'),
            'updatedAtUtc' => gmdate('c'),
        ];
        $existing['updatedAtUtc'] = gmdate('c');
        if (!is_dir($docs)) {
            mkdir($docs, 0775, true);
        }
        file_put_contents($decisionsPath, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        $package = $this->loadBatchCPackage();
        if (isset($package['mappings']['items']) && is_array($package['mappings']['items'])) {
            foreach ($package['mappings']['items'] as &$item) {
                if (!is_array($item)) {
                    continue;
                }
                $match = false;
                if ($scope === 'group') {
                    $match = MigrationPayload::string($item['groupKey'] ?? '') === $groupKey;
                } else {
                    $match = strtoupper(MigrationPayload::string($item['styleCode'] ?? '')) === $style
                        && strtolower(MigrationPayload::string($item['sourceField'] ?? '')) === $field;
                }
                if ($match) {
                    $item['decision'] = $decision;
                    $item['reason'] = MigrationPayload::string($body['reason'] ?? 'operator_decision');
                    if ($targetCode !== null && $targetCode !== '') {
                        $item['targetDefinitionCode'] = $targetCode;
                        $item['candidateDefinition'] = $targetCode;
                    }
                }
            }
            unset($item);
            file_put_contents($docs . '/batch-c-final-package.json', json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        }

        return ['ok' => true, 'key' => $key, 'decision' => $decision, 'package' => $package];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadBatchC1Package(): array
    {
        $path = dirname(__DIR__, 5) . '/core/docs/phase12.7/batch-c1-final-package.json';
        if (!is_file($path)) {
            return [
                'phase' => '12.7-batch-c1',
                'status' => 'NOT_GENERATED',
                'message' => 'Run php bin/resolve-batch-c1-unresolved-concepts.php --confirm-non-production first.',
                'approvalBoundary' => ['batchC1ApplyGuard' => 'BATCH_C1_APPLY_NOT_APPROVED'],
            ];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : ['status' => 'INVALID_ARTIFACT'];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function recordBatchC1Decision(array $body): array
    {
        $docs = dirname(__DIR__, 5) . '/core/docs/phase12.7';
        $decisionsPath = $docs . '/batch-c1-operator-decisions.json';
        $existing = [];
        if (is_file($decisionsPath)) {
            $decoded = json_decode((string) file_get_contents($decisionsPath), true);
            $existing = is_array($decoded) ? $decoded : [];
        }
        if (!isset($existing['concepts']) || !is_array($existing['concepts'])) {
            $existing['concepts'] = [];
        }
        $concept = MigrationPayload::string($body['concept'] ?? $body['key'] ?? '');
        $decision = MigrationPayload::string($body['decision'] ?? 'REVIEW');
        if ($concept === '') {
            return ['ok' => false, 'error' => 'concept_required'];
        }
        $existing['concepts'][$concept] = [
            'decision' => $decision,
            'targetDefinitionCode' => MigrationPayload::stringOrNull($body['targetDefinitionCode'] ?? null),
            'reason' => MigrationPayload::string($body['reason'] ?? 'operator_decision'),
            'updatedAtUtc' => gmdate('c'),
        ];
        $existing['updatedAtUtc'] = gmdate('c');
        if (!is_dir($docs)) {
            mkdir($docs, 0775, true);
        }
        file_put_contents($decisionsPath, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        $package = $this->loadBatchC1Package();
        if (isset($package['unresolvedConcepts']['items']) && is_array($package['unresolvedConcepts']['items'])) {
            foreach ($package['unresolvedConcepts']['items'] as &$item) {
                if (!is_array($item)) {
                    continue;
                }
                if (MigrationPayload::string($item['concept'] ?? '') === $concept) {
                    $item['decision'] = $decision;
                    $item['reason'] = MigrationPayload::string($body['reason'] ?? 'operator_decision');
                    if (isset($body['targetDefinitionCode'])) {
                        $item['existingTargetCandidate'] = MigrationPayload::stringOrNull($body['targetDefinitionCode']);
                    }
                }
            }
            unset($item);
            file_put_contents($docs . '/batch-c1-final-package.json', json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        }

        return ['ok' => true, 'concept' => $concept, 'decision' => $decision, 'package' => $package];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadBatchC2Package(): array
    {
        $path = dirname(__DIR__, 5) . '/core/docs/phase12.7/batch-c2-final-package.json';
        if (!is_file($path)) {
            return [
                'phase' => '12.7-batch-c2',
                'status' => 'NOT_GENERATED',
                'message' => 'Run php bin/resolve-batch-c2-operator-approval.php --confirm-non-production first.',
                'approvalBoundary' => ['batchC2ApplyGuard' => 'BATCH_C2_APPLY_NOT_APPROVED'],
            ];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : ['status' => 'INVALID_ARTIFACT'];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function recordBatchC2Decision(array $body): array
    {
        $docs = dirname(__DIR__, 5) . '/core/docs/phase12.7';
        $decisionsPath = $docs . '/batch-c2-operator-decisions.json';
        $existing = [];
        if (is_file($decisionsPath)) {
            $decoded = json_decode((string) file_get_contents($decisionsPath), true);
            $existing = is_array($decoded) ? $decoded : [];
        }
        if (!isset($existing['concepts']) || !is_array($existing['concepts'])) {
            $existing['concepts'] = [];
        }
        $concept = MigrationPayload::string($body['concept'] ?? $body['key'] ?? '');
        $decision = MigrationPayload::string($body['decision'] ?? 'REVIEW');
        if ($concept === '') {
            return ['ok' => false, 'error' => 'concept_required'];
        }
        $existing['concepts'][$concept] = [
            'decision' => $decision,
            'code' => MigrationPayload::stringOrNull($body['code'] ?? $body['targetDefinitionCode'] ?? null),
            'name' => MigrationPayload::stringOrNull($body['name'] ?? null),
            'group' => MigrationPayload::stringOrNull($body['group'] ?? null),
            'dataType' => MigrationPayload::stringOrNull($body['dataType'] ?? null),
            'unit' => MigrationPayload::stringOrNull($body['unit'] ?? null),
            'reason' => MigrationPayload::string($body['reason'] ?? 'operator_decision'),
            'updatedAtUtc' => gmdate('c'),
        ];
        $existing['updatedAtUtc'] = gmdate('c');
        if (!is_dir($docs)) {
            mkdir($docs, 0775, true);
        }
        file_put_contents($decisionsPath, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        $package = $this->loadBatchC2Package();
        if (isset($package['conceptDecisions']['items']) && is_array($package['conceptDecisions']['items'])) {
            foreach ($package['conceptDecisions']['items'] as &$item) {
                if (!is_array($item)) {
                    continue;
                }
                if (MigrationPayload::string($item['concept'] ?? '') === $concept) {
                    $item['decision'] = $decision;
                    $item['reason'] = MigrationPayload::string($body['reason'] ?? 'operator_decision');
                    if (isset($body['code']) || isset($body['targetDefinitionCode'])) {
                        $item['code'] = MigrationPayload::stringOrNull($body['code'] ?? $body['targetDefinitionCode'] ?? null);
                    }
                }
            }
            unset($item);
            file_put_contents($docs . '/batch-c2-final-package.json', json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        }

        return ['ok' => true, 'concept' => $concept, 'decision' => $decision, 'package' => $package];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCableSpecPackage(): array
    {
        $path = dirname(__DIR__, 5) . '/core/docs/phase12.7/cable-spec-final-dry-run-package.json';
        if (!is_file($path)) {
            return [
                'phase' => '12.7-cable-spec-field-mapping',
                'status' => 'NOT_GENERATED',
                'message' => 'Run php bin/resolve-cable-spec-field-mappings.php --confirm-non-production first.',
                'approvalBoundary' => ['cableSpecApplyGuard' => 'CABLE_SPEC_APPLY_NOT_APPROVED'],
            ];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : ['status' => 'INVALID_ARTIFACT'];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCableSpecD2Package(): array
    {
        $path = dirname(__DIR__, 5) . '/core/docs/phase12.7/cable-spec-d2-final-review-package.json';
        if (!is_file($path)) {
            return [
                'phase' => '12.7-batch-d2-cable-spec-operator-review',
                'status' => 'NOT_GENERATED',
                'message' => 'Run php bin/resolve-cable-spec-d2-operator-review.php --confirm-non-production first.',
                'approvalBoundary' => ['cableSpecD2ApplyGuard' => 'CABLE_SPEC_D2_APPLY_NOT_AVAILABLE'],
            ];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : ['status' => 'INVALID_ARTIFACT'];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCableSpecD2DecisionsPackage(): array
    {
        $path = dirname(__DIR__, 5) . '/core/docs/phase12.7/cable-spec-d2-final-decision-package.json';
        if (!is_file($path)) {
            return [
                'phase' => '12.7-batch-d2-cable-spec-operator-decisions',
                'status' => 'NOT_GENERATED',
                'message' => 'Run php bin/record-cable-spec-d2-operator-decisions.php --confirm-non-production first.',
                'approvalBoundary' => [
                    'cableSpecD2DecisionApplyGuard' => 'CABLE_SPEC_D2_DECISION_APPLY_NOT_AVAILABLE',
                ],
            ];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : ['status' => 'INVALID_ARTIFACT'];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCableSpecD3FinalPackage(): array
    {
        $path = dirname(__DIR__, 5) . '/core/docs/phase12.7/cable-spec-d3-final-package.json';
        if (!is_file($path)) {
            return [
                'phase' => '12.7-batch-d3-cable-spec-final-dry-run',
                'status' => 'NOT_GENERATED',
                'message' => 'Run php bin/resolve-cable-spec-d3-final-dry-run.php --confirm-non-production first.',
                'approvalBoundary' => ['cableSpecD3ApplyGuard' => CableSpecBatchD3CliGuard::APPLY_NOT_APPROVED],
            ];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : ['status' => 'INVALID_ARTIFACT'];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCableSpecD4ApplyReport(): array
    {
        $path = dirname(__DIR__, 5) . '/core/docs/phase12.7/cable-spec-d4-final-report.json';
        if (!is_file($path)) {
            return [
                'phase' => '12.7-batch-d4-cable-spec-target-apply',
                'status' => 'NOT_APPLIED',
                'message' => 'Run php bin/apply-cable-spec-d4-target.php --apply --batch-d4 --confirm-non-production --cable-spec-d4-apply-approved first.',
                'approvalBoundary' => ['cableSpecD4ApplyGuard' => 'CABLE_SPEC_D4_APPLY_NOT_APPROVED'],
            ];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : ['status' => 'INVALID_ARTIFACT'];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCableSpecD5ReviewPackage(): array
    {
        $path = dirname(__DIR__, 5) . '/core/docs/phase12.7/cable-spec-d5-final-review-package.json';
        if (!is_file($path)) {
            return [
                'phase' => '12.7-batch-d5-cable-spec-review-investigation',
                'status' => 'NOT_INVESTIGATED',
                'message' => 'Run php bin/investigate-cable-spec-d5-review.php --confirm-non-production first.',
                'investigationBanner' => 'D.5 INVESTIGATION ONLY — NOTHING APPLIED',
                'approvalBoundary' => ['cableSpecD5ApplyGuard' => 'CABLE_SPEC_D5_APPLY_NOT_AVAILABLE'],
            ];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : ['status' => 'INVALID_ARTIFACT'];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCableSpecD51FinalPackage(): array
    {
        $path = dirname(__DIR__, 5) . '/core/docs/phase12.7/cable-spec-d5-1-final-package.json';
        if (!is_file($path)) {
            return [
                'phase' => '12.7-batch-d5-1-cable-spec-final-resolution',
                'status' => 'NOT_INVESTIGATED',
                'message' => 'Run php bin/investigate-cable-spec-d5-1-final-resolution.php --confirm-non-production first.',
                'investigationBanner' => 'FINAL SEMANTIC INVESTIGATION — NOTHING APPLIED',
                'approvalBoundary' => ['cableSpecD51ApplyGuard' => 'CABLE_SPEC_D5_1_APPLY_NOT_AVAILABLE'],
            ];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : ['status' => 'INVALID_ARTIFACT'];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private function loadCatalogueNavigationDryRunPackage(): array
    {
        $path = dirname(__DIR__, 5) . '/core/docs/phase12.8/catalogue-navigation-final-dry-run.json';
        if (!is_file($path)) {
            return [
                'phase' => '12.8-catalogue-navigation-additive-mapping',
                'status' => 'NOT_RUN',
                'banner' => 'CATALOGUE NAVIGATION — ADDITIVE ONLY. EXISTING STRUCTURE AND RELATIONSHIPS ARE PROTECTED.',
                'message' => 'Run php bin/resolve-catalogue-navigation-dry-run.php --dry-run --confirm-non-production first.',
                'applied' => false,
                'wordpressWrites' => 0,
                'tcWrites' => 0,
                'forbiddenWriteAttempts' => 0,
                'targetWrites' => 0,
                'catalogueTabWrites' => 0,
                'catalogueNodeWrites' => 0,
                'productNodeWrites' => 0,
            ];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : ['status' => 'INVALID_ARTIFACT'];
    }


    /**
     * @return array<string, mixed>
     */
    private function loadPilotF12LiveSkuPackage(): array
    {
        $path = dirname(__DIR__, 5) . '/core/docs/phase12.8/pilot-f12-live-sku-final-package.json';
        if (!is_file($path)) {
            return [
                'phase' => '12.8-f12-corrected-live-sku-pilot-dry-run',
                'status' => 'NOT_RUN',
                'message' => 'Run php bin/run-f12-live-sku-pilot-dry-run.php --style=F12 --confirm-non-production --verify-deterministic first.',
                'banner' => 'F12 — Inspection Tee. PREVIOUS PILOT PACKAGE: SUPERSEDED — SOURCE DRIFT. CORRECTED SOURCE: LIVE accessory_specs SKU ROWS.',
                'applied' => false,
                'wordpressWrites' => 0,
                'tcWrites' => 0,
                'targetWrites' => 0,
                'blobWrites' => 0,
                'pricingWrites' => 0,
                'approvalBoundary' => ['pilotApplyGuard' => 'PILOT_APPLY_NOT_APPROVED'],
            ];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : ['status' => 'INVALID_ARTIFACT'];
    }

    private function loadPilotDryRunPackage(): array
    {
        $path = dirname(__DIR__, 5) . '/core/docs/phase12.8/pilot-final-dry-run-package.json';
        if (!is_file($path)) {
            return [
                'phase' => '12.8-single-accessory-dv-product-pilot-dry-run',
                'status' => 'NOT_RUN',
                'message' => 'Run php bin/run-product-migration-pilot.php --select-best-accessory --dry-run --confirm-non-production first.',
                'banner' => 'SINGLE PRODUCT PILOT — DRY-RUN ONLY — NOTHING APPLIED',
                'approvalBoundary' => ['pilotApplyGuard' => 'PILOT_APPLY_NOT_APPROVED'],
            ];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : ['status' => 'INVALID_ARTIFACT'];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadPilotF12ApplyReport(): array
    {
        $dir = dirname(__DIR__, 5) . '/core/docs/phase12.8';
        $final = $dir . '/pilot-f12-final-report.json';
        if (!is_file($final)) {
            return [
                'phase' => '12.8-f12-single-product-pilot-apply',
                'status' => 'NOT_APPLIED',
                'message' => 'Run php bin/apply-product-migration-pilot.php --apply --pilot --style=F12 --confirm-non-production --pilot-apply-approved first.',
                'banner' => 'PILOT PRODUCT: F12 — Inspection Tee | TARGET: NON-PRODUCTION | STATUS: DRAFT',
                'approvalBoundary' => ['pilotApplyGuard' => 'PILOT_APPLY_NOT_APPROVED'],
                'artifacts' => [],
            ];
        }
        $decoded = json_decode((string) file_get_contents($final), true);
        if (!is_array($decoded)) {
            return ['status' => 'INVALID_ARTIFACT'];
        }
        $load = static function (string $path): ?array {
            if (!is_file($path)) {
                return null;
            }
            $json = json_decode((string) file_get_contents($path), true);

            return is_array($json) ? $json : null;
        };
        $decoded['artifacts'] = [
            'preflight' => $load($dir . '/pilot-f12-apply-preflight.json'),
            'applyResult' => $load($dir . '/pilot-f12-apply-result.json'),
            'reconciliation' => $load($dir . '/pilot-f12-reconciliation.json'),
            'idempotency' => $load($dir . '/pilot-f12-idempotency.json'),
            'publicationCheck' => $load($dir . '/pilot-f12-publication-check.json'),
            'rollbackPackage' => $load($dir . '/pilot-f12-rollback-package.json'),
        ];

        return $decoded;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function recordCableSpecDecision(array $body): array
    {
        $docs = dirname(__DIR__, 5) . '/core/docs/phase12.7';
        $decisionsPath = $docs . '/cable-spec-operator-decisions.json';
        $existing = [];
        if (is_file($decisionsPath)) {
            $decoded = json_decode((string) file_get_contents($decisionsPath), true);
            $existing = is_array($decoded) ? $decoded : [];
        }
        if (!isset($existing['fields']) || !is_array($existing['fields'])) {
            $existing['fields'] = [];
        }
        $field = MigrationPayload::string($body['field'] ?? $body['concept'] ?? $body['key'] ?? '');
        $decision = MigrationPayload::string($body['decision'] ?? 'REVIEW');
        if ($field === '') {
            return ['ok' => false, 'error' => 'field_required'];
        }
        $existing['fields'][$field] = [
            'decision' => $decision,
            'code' => MigrationPayload::stringOrNull($body['code'] ?? $body['targetDefinitionCode'] ?? null),
            'reason' => MigrationPayload::string($body['reason'] ?? 'operator_decision'),
            'updatedAtUtc' => gmdate('c'),
        ];
        $existing['updatedAtUtc'] = gmdate('c');
        if (!is_dir($docs)) {
            mkdir($docs, 0775, true);
        }
        file_put_contents($decisionsPath, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        $package = $this->loadCableSpecPackage();
        if (isset($package['conceptDecisions']['items']) && is_array($package['conceptDecisions']['items'])) {
            foreach ($package['conceptDecisions']['items'] as &$item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemField = MigrationPayload::string($item['field'] ?? $item['concept'] ?? '');
                if ($itemField === $field) {
                    $item['decision'] = $decision;
                    $item['reason'] = MigrationPayload::string($body['reason'] ?? 'operator_decision');
                    if (isset($body['code']) || isset($body['targetDefinitionCode'])) {
                        $item['code'] = MigrationPayload::stringOrNull($body['code'] ?? $body['targetDefinitionCode'] ?? null);
                    }
                }
            }
            unset($item);
            file_put_contents($docs . '/cable-spec-final-dry-run-package.json', json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        }

        return ['ok' => true, 'field' => $field, 'decision' => $decision, 'package' => $package, 'apply' => 'NOT_APPROVED'];
    }

    private function mapDecisionToStatusCode(string $decision): string
    {
        return match (strtoupper($decision)) {
            'APPROVED_MAP_EXISTING' => 'mapped',
            'APPROVED_CREATE' => 'approved-create',
            'NOT_A_PRODUCT_CATEGORY' => 'not-category',
            'REJECT' => 'rejected',
            'INVALID' => 'invalid',
            'SOURCE_ONLY' => 'source-only',
            'CONFLICT' => 'conflict',
            default => 'needs-review',
        };
    }
}
