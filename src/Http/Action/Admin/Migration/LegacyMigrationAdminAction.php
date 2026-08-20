<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin\Migration;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\LegacyMigration\Application\Exception\InvalidLegacyMigrationState;
use TriCab\Core\LegacyMigration\Application\Exception\LegacyMigrationBatchNotFound;
use TriCab\Core\LegacyMigration\Application\Exception\LegacyMigrationModuleNotFound;
use TriCab\Core\LegacyMigration\Application\Exception\ProductionWriteRefused;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Api\LegacyMigration\Application\Service\LegacyStaffReadOnlyAnalysisService;
use TriCab\Api\LegacyMigration\Application\Service\StaffEntraLinkDryRunService;
use TriCab\Api\LegacyMigration\Application\Service\StaffEntraLinkExecuteService;
use TriCab\Api\LegacyMigration\Application\Service\StaffUserMigrationDryRunService;
use TriCab\Api\LegacyMigration\Application\Service\StaffUserMigrationExecuteService;
use TriCab\Core\LegacyMigration\StaffMigration\StaffMigrationExecutionException;
use TriCab\Core\LegacyMigration\Application\Service\LegacyMigrationEngine;

final class LegacyMigrationAdminAction
{
    public function __construct(
        private readonly LegacyMigrationEngine $engine,
        private readonly LegacyStaffReadOnlyAnalysisService $staffAnalysis,
        private readonly StaffUserMigrationDryRunService $staffDryRun,
        private readonly StaffUserMigrationExecuteService $staffExecute,
        private readonly StaffEntraLinkDryRunService $staffEntraDryRun,
        private readonly StaffEntraLinkExecuteService $staffEntraExecute,
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

            return match ($op) {
                'overview' => $this->responder->success($this->engine->overview()),
                'user-workspace' => $this->responder->success($this->engine->userWorkspace(
                    is_string($request->getQueryParams()['batchPublicId'] ?? null) ? $request->getQueryParams()['batchPublicId'] : null,
                    $request->getQueryParams(),
                )),
                'staff-readonly-analysis' => $this->staffReadonlyAnalysis($request),
                'staff-migration-dry-run' => $this->staffMigrationDryRun($request),
                'staff-migration-execute' => $this->staffMigrationExecute($request, $actor),
                'staff-entra-dry-run' => $this->staffEntraDryRun($request),
                'staff-entra-execute' => $this->staffEntraExecute($request, $actor),
                'statistics' => $this->responder->success($this->engine->statistics()),
                'sources' => $this->responder->success(['items' => $this->engine->sources()]),
                'source-health' => $this->responder->success($this->engine->sourceHealth(RouteParams::require($request, 'sourceCode'))),
                'list-batches' => $this->responder->success(['items' => $this->engine->listBatches(
                    is_string($request->getQueryParams()['statusCode'] ?? null) ? $request->getQueryParams()['statusCode'] : null,
                    is_string($request->getQueryParams()['moduleCode'] ?? null) ? $request->getQueryParams()['moduleCode'] : null,
                )]),
                'create-batch' => $this->createBatch($request, $actor),
                'get-batch' => $this->responder->success($this->engine->getBatch(RouteParams::require($request, 'batchPublicId'))),
                'items' => $this->responder->success(['items' => $this->engine->listItems(RouteParams::require($request, 'batchPublicId'))]),
                'extract' => $this->responder->success($this->engine->extract($actor, RouteParams::require($request, 'batchPublicId'), false)),
                'refresh' => $this->responder->success($this->engine->extract($actor, RouteParams::require($request, 'batchPublicId'), false)),
                'incremental' => $this->responder->success($this->engine->extract($actor, RouteParams::require($request, 'batchPublicId'), true)),
                'normalize' => $this->responder->success($this->engine->normalize($actor, RouteParams::require($request, 'batchPublicId'))),
                'validate' => $this->responder->success($this->engine->validate($actor, RouteParams::require($request, 'batchPublicId'))),
                'preview' => $this->responder->success($this->engine->preview($actor, RouteParams::require($request, 'batchPublicId'))),
                'approve' => $this->responder->success($this->engine->approve($actor, RouteParams::require($request, 'batchPublicId'))),
                'execute' => $this->responder->success($this->engine->execute($actor, RouteParams::require($request, 'batchPublicId'))),
                'rollback' => $this->responder->success($this->engine->rollback($actor, RouteParams::require($request, 'batchPublicId'))),
                'cancel' => $this->responder->success($this->engine->cancel($actor, RouteParams::require($request, 'batchPublicId'))),
                'retry' => $this->responder->success($this->engine->retry($actor, RouteParams::require($request, 'batchPublicId'))),
                'conflicts' => $this->responder->success(['items' => $this->engine->conflicts(
                    $request->getAttribute('batchPublicId') !== null
                        ? RouteParams::require($request, 'batchPublicId')
                        : null,
                )]),
                'resolve-conflict' => $this->resolveConflict($request, $actor),
                'logs' => $this->responder->success(['items' => $this->engine->logs(
                    is_string($request->getQueryParams()['batchPublicId'] ?? null)
                        ? $request->getQueryParams()['batchPublicId']
                        : ($request->getAttribute('batchPublicId') !== null ? RouteParams::require($request, 'batchPublicId') : null),
                )]),
                'reports' => $this->report($request),
                'settings-get' => $this->responder->success(['settings' => $this->engine->settings()]),
                'settings-put' => $this->updateSettings($request, $actor),
                default => throw new \InvalidArgumentException('Unknown legacy migration operation.'),
            };
        } catch (LegacyMigrationBatchNotFound | LegacyMigrationModuleNotFound $e) {
            return $this->responder->error('not_found', $e->getMessage(), 404);
        } catch (InvalidLegacyMigrationState | ProductionWriteRefused $e) {
            return $this->responder->error('conflict', $e->getMessage(), 409);
        } catch (StaffMigrationExecutionException $e) {
            return $this->responder->error('conflict', $e->getMessage(), 409);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }

    private function staffMigrationExecute(ServerRequestInterface $request, ActorContext $actor): ResponseInterface
    {
        $body = JsonRequestBody::requireObject($request);

        $result = $this->staffExecute->execute($actor, [
            'scope' => is_string($body['scope'] ?? null) ? $body['scope'] : '',
            'sourceFingerprint' => is_string($body['sourceFingerprint'] ?? null) ? $body['sourceFingerprint'] : '',
            'expectedCandidateCount' => (int) ($body['expectedCandidateCount'] ?? 0),
            'approved' => ($body['approved'] ?? false) === true,
        ]);

        return $this->responder->success($result);
    }

    private function staffEntraExecute(ServerRequestInterface $request, ActorContext $actor): ResponseInterface
    {
        $body = JsonRequestBody::requireObject($request);

        $result = $this->staffEntraExecute->execute($actor, [
            'sourceFingerprint' => is_string($body['sourceFingerprint'] ?? null) ? $body['sourceFingerprint'] : '',
            'expectedSafeLinkCount' => (int) ($body['expectedSafeLinkCount'] ?? 0),
            'explicitApproval' => ($body['explicitApproval'] ?? false) === true,
        ]);

        return $this->responder->success($result);
    }

    private function staffEntraDryRun(ServerRequestInterface $request): ResponseInterface
    {
        unset($request);
        $result = $this->staffEntraDryRun->run();

        return $this->responder->success([
            'mode' => $result['mode'] ?? 'DRY_RUN_ONLY',
            'batchType' => $result['batchType'] ?? 'STAFF_ENTRA_LINK_ACTIVATION',
            'warning' => $result['warning'] ?? 'No ExternalIdentities will be created.',
            'persistence' => $result['persistence'] ?? 'azure-sql',
            'sourceFingerprint' => $result['sourceFingerprint'] ?? '',
            'approvalRequired' => true,
            'executeEnabled' => false,
            'expectedTenantId' => $result['expectedTenantId'] ?? '',
            'providerCode' => $result['providerCode'] ?? ExternalProvider::MICROSOFT_ENTRA,
            'canonicalProviderCode' => $result['canonicalProviderCode'] ?? ExternalProvider::MICROSOFT_ENTRA,
            'providerCodesInDatabase' => $result['providerCodesInDatabase'] ?? [],
            'graphConfigured' => $result['graphConfigured'] ?? false,
            'graphDirectoryAvailable' => ($result['graphHealth'] ?? '') === 'AVAILABLE',
            'graphHealth' => $result['graphHealth'] ?? 'GRAPH_DIRECTORY_UNAVAILABLE',
            'graphError' => $result['graphError'] ?? null,
            'mappedStaffCount' => $result['mappedStaffCount'] ?? 0,
            'summary' => $result['summary'] ?? [],
            'wouldCreate' => $result['wouldCreate'] ?? [],
            'items' => $result['items'] ?? [],
            'tristanReconciliation' => $result['tristanReconciliation'] ?? null,
            'databaseSafety' => $result['databaseSafety'] ?? [],
            'graphSafety' => $result['graphSafety'] ?? ['readOperations' => 0, 'writeOperations' => 0],
            'staleDataProtection' => $result['staleDataProtection'] ?? null,
        ]);
    }

    private function staffMigrationDryRun(ServerRequestInterface $request): ResponseInterface
    {
        unset($request);
        $result = $this->staffDryRun->run();

        return $this->responder->success([
            'mode' => $result['mode'] ?? 'DRY_RUN_ONLY',
            'scope' => $result['scope'] ?? 'STAFF_ONLY',
            'warning' => $result['warning'] ?? 'No users will be created.',
            'persistence' => $result['persistence'] ?? 'MEMORY',
            'sourceFingerprint' => $result['sourceFingerprint'] ?? '',
            'approvalRequired' => true,
            'executeEnabled' => false,
            'summary' => $result['summary'] ?? [],
            'wouldCreate' => $result['wouldCreate'] ?? [],
            'proposedRoleMapping' => $result['proposedRoleMapping'] ?? [],
            'unexpectedRoleReport' => $result['unexpectedRoleReport'] ?? [],
            'administratorCandidates' => $result['administratorCandidates'] ?? [],
            'existingTriCabUsers' => $result['existingTriCabUsers'] ?? [],
            'triCabRoles' => $result['triCabRoles'] ?? [],
            'items' => $result['items'] ?? [],
            'databaseSafety' => $result['databaseSafety'] ?? [],
            'tcEmployeeEnrichment' => $result['tcEmployeeEnrichment'] ?? 'UNAVAILABLE',
            'identityLookup' => $result['identityLookup'] ?? 'UNKNOWN',
            'developmentOverride' => $result['developmentOverride'] ?? null,
            'transactionDesign' => $result['transactionDesign'] ?? null,
            'staleDataProtection' => $result['staleDataProtection'] ?? null,
        ]);
    }

    private function staffReadonlyAnalysis(ServerRequestInterface $request): ResponseInterface
    {
        unset($request);
        $result = $this->staffAnalysis->analyze();
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        $safety = is_array($result['databaseSafety'] ?? null) ? $result['databaseSafety'] : [];

        return $this->responder->success([
            'mode' => 'STRICT_READ_ONLY',
            'warning' => 'No database records will be created, edited, or deleted.',
            'persistence' => 'MEMORY',
            'tcEmployeeEnrichment' => $result['tcEmployeeEnrichment'] ?? 'UNAVAILABLE',
            'identityLookup' => $result['identityLookup'] ?? 'UNKNOWN',
            'summary' => $summary,
            'roleCounts' => $result['roleCounts'] ?? [],
            'unexpectedRoleCounts' => $result['unexpectedRoleCounts'] ?? [],
            'proposedRoleMapping' => $result['proposedRoleMapping'] ?? [],
            'developmentOverride' => $result['developmentOverride'] ?? null,
            'items' => $result['items'] ?? [],
            'databaseSafety' => $safety,
        ]);
    }

    private function createBatch(ServerRequestInterface $request, ActorContext $actor): ResponseInterface
    {
        $body = JsonRequestBody::requireObject($request);
        $created = $this->engine->createBatch(
            $actor,
            is_string($body['name'] ?? null) ? $body['name'] : '',
            is_string($body['moduleCode'] ?? null) ? $body['moduleCode'] : 'users',
            isset($body['description']) && is_string($body['description']) ? $body['description'] : null,
            is_array($body['sourceFilter'] ?? null) ? $body['sourceFilter'] : null,
        );

        return $this->responder->success($created, 201);
    }

    private function resolveConflict(ServerRequestInterface $request, ActorContext $actor): ResponseInterface
    {
        $body = JsonRequestBody::requireObject($request);
        $decision = is_string($body['decisionCode'] ?? null) ? $body['decisionCode'] : '';
        $mapping = isset($body['manualMapping']) && is_string($body['manualMapping']) ? $body['manualMapping'] : null;

        return $this->responder->success($this->engine->resolveConflict(
            $actor,
            RouteParams::require($request, 'conflictPublicId'),
            $decision,
            $mapping,
        ));
    }

    private function report(ServerRequestInterface $request): ResponseInterface
    {
        $id = RouteParams::require($request, 'batchPublicId');
        $type = is_string($request->getQueryParams()['type'] ?? null) ? $request->getQueryParams()['type'] : 'preview';
        $format = is_string($request->getQueryParams()['format'] ?? null) ? $request->getQueryParams()['format'] : 'json';

        return $this->responder->success($this->engine->report($id, $type, $format));
    }

    private function updateSettings(ServerRequestInterface $request, ActorContext $actor): ResponseInterface
    {
        $body = JsonRequestBody::requireObject($request);
        $settings = is_array($body['settings'] ?? null) ? $body['settings'] : $body;
        foreach ($settings as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value) || is_bool($value))) {
                $this->engine->updateSetting($actor, $key, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
            }
        }

        return $this->responder->success(['settings' => $this->engine->settings()]);
    }

    private static function resolveOp(ServerRequestInterface $request): string
    {
        $path = rtrim($request->getUri()->getPath(), '/');
        $method = strtoupper($request->getMethod());

        if (str_ends_with($path, '/legacy-migration/overview')) {
            return 'overview';
        }
        if (str_ends_with($path, '/legacy-migration/staff-migration-dry-run')) {
            return 'staff-migration-dry-run';
        }
        if (str_ends_with($path, '/legacy-migration/staff-migration/execute')) {
            return 'staff-migration-execute';
        }
        if (str_ends_with($path, '/legacy-migration/staff-entra/dry-run')) {
            return 'staff-entra-dry-run';
        }
        if (str_ends_with($path, '/legacy-migration/staff-entra/execute')) {
            return 'staff-entra-execute';
        }
        if (str_ends_with($path, '/legacy-migration/staff-readonly-analysis')) {
            return 'staff-readonly-analysis';
        }
        if (str_ends_with($path, '/legacy-migration/user-workspace')) {
            return 'user-workspace';
        }
        if (str_ends_with($path, '/legacy-migration/statistics')) {
            return 'statistics';
        }
        if (str_ends_with($path, '/legacy-migration/settings')) {
            return $method === 'PUT' ? 'settings-put' : 'settings-get';
        }
        if (str_ends_with($path, '/legacy-migration/sources')) {
            return 'sources';
        }
        if (str_contains($path, '/legacy-migration/sources/') && str_ends_with($path, '/health')) {
            return 'source-health';
        }
        if (str_ends_with($path, '/legacy-migration/logs')) {
            return 'logs';
        }
        if (str_ends_with($path, '/legacy-migration/conflicts')) {
            return 'conflicts';
        }
        if (str_contains($path, '/conflicts/') && str_ends_with($path, '/resolve')) {
            return 'resolve-conflict';
        }

        foreach ([
            'extract', 'refresh', 'incremental', 'normalize', 'validate', 'preview',
            'approve', 'execute', 'rollback', 'cancel', 'retry', 'conflicts', 'logs', 'reports', 'items',
        ] as $suffix) {
            if (str_ends_with($path, '/' . $suffix)) {
                return $suffix;
            }
        }

        $hasBatch = isset($request->getAttributes()['batchPublicId'])
            && is_string($request->getAttributes()['batchPublicId'])
            && $request->getAttributes()['batchPublicId'] !== '';
        if ($method === 'POST' && !$hasBatch) {
            return 'create-batch';
        }
        if ($hasBatch) {
            return 'get-batch';
        }

        return 'list-batches';
    }
}
