<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\UpsertProductStyleCommand;
use TriCab\Core\Product\Application\DTO\ProductStyleRecord;
use TriCab\Core\Product\Application\DTO\StyleInventoryDiscoveryReport;
use TriCab\Core\Product\Application\DTO\StyleInventoryItem;
use TriCab\Core\Product\Application\Exception\DuplicateProductStyleCode;
use TriCab\Core\Product\Contract\ProductStyleRepository;

/**
 * Creates only VALID + MISSING_TARGET styles as safe LOCAL roots.
 * Requires explicit operator approval. Never writes WordPress or tc MySQL.
 * Restart-safe: existing canonical StyleCodes are skipped, never suffixed.
 */
final class CreateMissingProductStylesFromInventoryService
{
    public const DISCOVERY_BATCH = 'style-inventory-discovery';

    public function __construct(
        private readonly ManageProductStyleService $styles,
        private readonly ProductStyleRepository $repository,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    /**
     * @return array{
     *   created: list<array{styleCode: string, displayName: string, publicId: string}>,
     *   skipped: list<array{styleCode: string, reason: string}>,
     *   results: list<array{styleCode: string, action: string, result: string, reason: string, publicId: ?string}>,
     *   counters: array{
     *     createAttempts: int,
     *     created: int,
     *     alreadyExisted: int,
     *     skippedReview: int,
     *     skippedInvalid: int,
     *     skippedTargetOnly: int,
     *     failed: int,
     *     conflicts: int
     *   },
     *   conflicts: int,
     *   azureSqlWrites: int,
     *   productAssignmentWrites: 0,
     *   hierarchyWrites: 0,
     *   specificationWrites: 0,
     *   mediaWrites: 0
     * }
     */
    /**
     * @param (callable(array{styleCode: string, result: string}): void)|null $onItem
     */
    public function createApprovedMissing(
        ActorContext $actor,
        StyleInventoryDiscoveryReport $report,
        bool $operatorApproved,
        bool $developmentEnvironment,
        ?callable $onItem = null,
    ): array {
        if (!$operatorApproved) {
            throw new \RuntimeException('Operator approval is required before creating missing styles.');
        }
        if (!$developmentEnvironment) {
            throw new \RuntimeException('Missing styles may be created only in the development environment.');
        }
        if (!$report->readyToCreate) {
            throw new \RuntimeException('Style inventory is not ready to create.');
        }

        $created = [];
        $skipped = [];
        $results = [];
        $counters = [
            'createAttempts' => 0,
            'created' => 0,
            'alreadyExisted' => 0,
            'skippedReview' => 0,
            'skippedInvalid' => 0,
            'skippedTargetOnly' => 0,
            'failed' => 0,
            'conflicts' => 0,
        ];

        foreach ($report->items as $item) {
            $this->processItem($actor, $item, $created, $skipped, $results, $counters, $onItem);
        }
        foreach ($report->targetOnly as $item) {
            $this->recordSkip(
                $skipped,
                $results,
                $counters,
                (string) ($item->normalizedStyleCode ?? $item->rawStyleCode),
                'SKIPPED_TARGET_ONLY',
                $item->targetClassification . '/' . $item->targetMatch,
                'skippedTargetOnly',
                null,
                $onItem,
            );
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'results' => $results,
            'counters' => $counters,
            'conflicts' => $counters['conflicts'],
            'azureSqlWrites' => $counters['created'],
            'productAssignmentWrites' => 0,
            'hierarchyWrites' => 0,
            'specificationWrites' => 0,
            'mediaWrites' => 0,
        ];
    }

    /**
     * @param list<array{styleCode: string, displayName: string, publicId: string}> $created
     * @param list<array{styleCode: string, reason: string}> $skipped
     * @param list<array{styleCode: string, action: string, result: string, reason: string, publicId: ?string}> $results
     * @param array<string, int> $counters
     * @param (callable(array{styleCode: string, result: string}): void)|null $onItem
     */
    private function processItem(
        ActorContext $actor,
        StyleInventoryItem $item,
        array &$created,
        array &$skipped,
        array &$results,
        array &$counters,
        ?callable $onItem,
    ): void {
        $code = (string) ($item->normalizedStyleCode ?? $item->rawStyleCode);
        $skip = $this->skipResult($item);
        if ($skip !== null) {
            $this->recordSkip($skipped, $results, $counters, $code, $skip['result'], $skip['reason'], $skip['counter'], null, $onItem);

            return;
        }

        $existing = $this->repository->findByStyleCode($code, false);
        if ($existing !== null) {
            $this->recordSkip(
                $skipped,
                $results,
                $counters,
                $code,
                'SKIPPED_ALREADY_EXISTS',
                'Canonical StyleCode already present in [product].[ProductStyles].',
                'alreadyExisted',
                $existing->publicId,
                $onItem,
            );

            return;
        }

        ++$counters['createAttempts'];
        try {
            $record = $this->styles->create($actor, new UpsertProductStyleCommand(
                styleCode: $code,
                displayName: $item->displayName !== '' ? $item->displayName : $code,
                parentPublicId: null,
            ));
            $this->assertSafeRoot($record);
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-style',
                entityId: null,
                actionCode: 'legacy-style-inventory-created',
                newValues: [
                    'styleCode' => $record->styleCode,
                    'displayName' => $record->displayName,
                    'source' => 'legacy-style-inventory',
                    'sourceEvidence' => $item->sources,
                    'discoveryBatch' => self::DISCOVERY_BATCH,
                    'displayNameSource' => $item->displayNameSource,
                    'parentStyleId' => null,
                    'inheritance' => 'LOCAL',
                ],
                sourceCode: 'legacy-style-inventory',
            );
            ++$counters['created'];
            $created[] = [
                'styleCode' => $record->styleCode,
                'displayName' => $record->displayName,
                'publicId' => $record->publicId,
            ];
            $results[] = [
                'styleCode' => $record->styleCode,
                'action' => 'CREATE',
                'result' => 'CREATED',
                'reason' => 'VALID_MISSING_TARGET imported as LOCAL root.',
                'publicId' => $record->publicId,
            ];
            $this->notify($onItem, $record->styleCode, 'CREATED');
        } catch (DuplicateProductStyleCode $exception) {
            $reread = $this->repository->findByStyleCode($code, false);
            if ($reread !== null && strcasecmp($reread->styleCode, $code) === 0) {
                $this->recordSkip(
                    $skipped,
                    $results,
                    $counters,
                    $code,
                    'SKIPPED_ALREADY_EXISTS',
                    'Unique collision resolved to existing canonical StyleCode. No suffix created.',
                    'alreadyExisted',
                    $reread->publicId,
                    $onItem,
                );

                return;
            }
            ++$counters['conflicts'];
            $results[] = [
                'styleCode' => $code,
                'action' => 'CREATE',
                'result' => 'CONFLICT',
                'reason' => $exception->getMessage(),
                'publicId' => $reread?->publicId,
            ];
            $this->notify($onItem, $code, 'CONFLICT');
        } catch (\Throwable $exception) {
            ++$counters['failed'];
            $results[] = [
                'styleCode' => $code,
                'action' => 'CREATE',
                'result' => 'FAILED',
                'reason' => $exception->getMessage(),
                'publicId' => null,
            ];
            $this->notify($onItem, $code, 'FAILED');
        }
    }

    /**
     * @return array{result: string, reason: string, counter: string}|null
     */
    private function skipResult(StyleInventoryItem $item): ?array
    {
        if ($item->targetMatch === 'TARGET_ONLY' || in_array($item->targetClassification, ['TARGET_ONLY', 'POSSIBLE_TEST_DATA'], true)) {
            return [
                'result' => 'SKIPPED_TARGET_ONLY',
                'reason' => $item->targetClassification . '/' . $item->targetMatch,
                'counter' => 'skippedTargetOnly',
            ];
        }
        if ($item->status === 'INVALID') {
            return [
                'result' => 'SKIPPED_INVALID',
                'reason' => $item->status . '/' . $item->targetMatch,
                'counter' => 'skippedInvalid',
            ];
        }
        if ($item->status === 'REVIEW' || $item->targetMatch === 'REVIEW') {
            return [
                'result' => 'SKIPPED_REVIEW',
                'reason' => $item->status . '/' . $item->targetMatch . ' — numeric cable_style and other REVIEW values are not approved ProductStyles.',
                'counter' => 'skippedReview',
            ];
        }
        if ($item->status === 'VALID' && $item->targetMatch === 'EXISTS_MATCH') {
            return [
                'result' => 'SKIPPED_ALREADY_EXISTS',
                'reason' => 'EXISTS_MATCH',
                'counter' => 'alreadyExisted',
            ];
        }
        if ($item->status === 'VALID' && $item->targetMatch === 'MISSING_TARGET' && $item->normalizedStyleCode !== null) {
            return null;
        }

        return [
            'result' => 'SKIPPED_REVIEW',
            'reason' => $item->status . '/' . $item->targetMatch,
            'counter' => 'skippedReview',
        ];
    }

    /**
     * @param list<array{styleCode: string, reason: string}> $skipped
     * @param list<array{styleCode: string, action: string, result: string, reason: string, publicId: ?string}> $results
     * @param array<string, int> $counters
     */
    /**
     * @param (callable(array{styleCode: string, result: string}): void)|null $onItem
     */
    private function recordSkip(
        array &$skipped,
        array &$results,
        array &$counters,
        string $code,
        string $result,
        string $reason,
        string $counter,
        ?string $publicId = null,
        ?callable $onItem = null,
    ): void {
        ++$counters[$counter];
        $skipped[] = ['styleCode' => $code, 'reason' => $reason];
        $results[] = [
            'styleCode' => $code,
            'action' => 'SKIP',
            'result' => $result,
            'reason' => $reason,
            'publicId' => $publicId,
        ];
        $this->notify($onItem, $code, $result);
    }

    /**
     * @param (callable(array{styleCode: string, result: string}): void)|null $onItem
     */
    private function notify(?callable $onItem, string $code, string $result): void
    {
        if ($onItem === null) {
            return;
        }
        $onItem(['styleCode' => $code, 'result' => $result]);
    }

    private function assertSafeRoot(ProductStyleRecord $record): void
    {
        if ($record->parentPublicId !== null) {
            throw new \RuntimeException('Imported styles must be created as roots (ParentStyleId = NULL).');
        }
        $map = $record->inheritance->toMap();
        foreach ($map as $mode) {
            if ($mode !== 'LOCAL') {
                throw new \RuntimeException('Imported styles must use LOCAL inheritance defaults.');
            }
        }
    }
}
