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
use TriCab\Core\Product\Migration\Application\Exception\LegacyDatabaseWriteAttemptException;
use TriCab\Core\Product\Migration\Support\MigrationPayload;
use TriCab\Core\Product\Migration\Application\Service\ProductMigrationService;

final class ProductMigrationAdminAction
{
    public function __construct(
        private readonly ProductMigrationService $service,
        private readonly GetCatalogueReadinessService $readiness,
        private readonly InspectLegacyCatalogueService $inspection,
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
}
