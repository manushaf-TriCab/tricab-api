<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Migration\Application\Command\CreateMigrationBatchCommand;
use TriCab\Core\Product\Migration\Application\DTO\DryRunReport;
use TriCab\Core\Product\Migration\Application\DTO\MigrationBatchDetails;
use TriCab\Core\Product\Migration\Application\DTO\MigrationIssueDetails;
use TriCab\Core\Product\Migration\Application\Exception\InvalidMigrationBatchState;
use TriCab\Core\Product\Migration\Application\Exception\MigrationBatchNotFound;
use TriCab\Core\Product\Migration\Canonical\CanonicalDocument;
use TriCab\Core\Product\Migration\Canonical\CanonicalMedia;
use TriCab\Core\Product\Migration\Canonical\CanonicalProduct;
use TriCab\Core\Product\Migration\Canonical\CanonicalRelationship;
use TriCab\Core\Product\Migration\Canonical\CanonicalSlug;
use TriCab\Core\Product\Migration\Canonical\CanonicalSpecificationValue;
use TriCab\Core\Product\Migration\Canonical\CanonicalVariant;
use TriCab\Core\Product\Migration\Contract\MigrationBatchRepository;
use TriCab\Core\Product\Migration\Identity\ProductIdentityMatcher;
use TriCab\Core\Product\Migration\Identity\ProductVariantClassifier;
use TriCab\Core\Product\Migration\Mapping\OptionAliasResolver;
use TriCab\Core\Product\Migration\Source\FixtureLegacyProductSource;
use TriCab\Core\Product\Migration\Source\LegacyProductSourceInterface;
use TriCab\Core\Product\Migration\Source\LiveLegacySourceMarker;
use TriCab\Core\Product\Migration\Support\MigrationPayload;
use TriCab\Core\Product\Migration\Transform\SpecificationValueTransformer;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Orchestrates Phase 12 migration pipeline. Dry-run never writes Product domain or Blob.
 */
final class ProductMigrationService
{
    private const ALLOWED_SOURCES = ['wordpress', 'tc', 'files', 'combined'];

    public function __construct(
        private readonly MigrationBatchRepository $batches,
        private readonly ProductIdentityMatcher $identityMatcher,
        private readonly ProductVariantClassifier $variantClassifier,
        private readonly SpecificationValueTransformer $transformer,
        private readonly OptionAliasResolver $optionAliases,
        private readonly ApplicationTransaction $transaction,
        private readonly ?LegacyProductSourceInterface $source = null,
    ) {
    }

    public function createBatch(ActorContext $actor, CreateMigrationBatchCommand $command): MigrationBatchDetails
    {
        $system = strtolower(trim($command->sourceSystem));
        if (!in_array($system, self::ALLOWED_SOURCES, true)) {
            throw new \InvalidArgumentException('Unsupported source system: ' . $command->sourceSystem);
        }
        if (trim($command->name) === '') {
            throw new \InvalidArgumentException('Batch name is required.');
        }

        return $this->transaction->run(function () use ($actor, $command, $system): MigrationBatchDetails {
            $source = $this->source ?? FixtureLegacyProductSource::pilotSample();
            $dryRun = $command->dryRun;
            // live sources force dryRun — never create Product/Blob from live extract
            if ($source instanceof LiveLegacySourceMarker && $source->isLiveReadOnly()) {
                $dryRun = true;
            }
            $batch = $this->batches->createBatch([
                'name' => trim($command->name),
                'description' => $command->description,
                'sourceSystem' => $system,
                'sourceFilter' => $command->sourceFilter,
                'dryRun' => $dryRun,
                'createdByUserId' => $actor->userId,
            ]);
            $this->batches->recordAudit($batch->publicId, 'batch-created', $actor->userId, $actor->correlationId, [
                'name' => $batch->name,
                'sourceSystem' => $batch->sourceSystem,
                'dryRun' => $batch->dryRun,
            ]);

            return $batch;
        });
    }

    /** @return list<MigrationBatchDetails> */
    public function listBatches(?string $statusCode = null): array
    {
        return $this->batches->listBatches($statusCode);
    }

    public function getBatch(string $publicId): MigrationBatchDetails
    {
        return $this->requireBatch($publicId);
    }

    public function extract(ActorContext $actor, string $batchPublicId): MigrationBatchDetails
    {
        $batch = $this->requireBatch($batchPublicId);
        $this->assertStatus($batch, ['draft', 'extracted', 'failed', 'cancelled'], 'extract');

        return $this->transaction->run(function () use ($actor, $batch): MigrationBatchDetails {
            $this->batches->updateBatchStatus($batch->publicId, 'extracting');
            $this->batches->recordAudit($batch->publicId, 'extraction-started', $actor->userId, $actor->correlationId, null);

            $source = $this->source ?? FixtureLegacyProductSource::pilotSample();
            $filter = $batch->sourceFilter ?? [];
            $total = 0;
            foreach ($source->extract($filter) as $record) {
                $this->batches->upsertRaw($batch->publicId, [
                    'sourceSystem' => $record->sourceSystem,
                    'sourceTable' => $record->sourceTable,
                    'sourceIdentifier' => $record->sourceIdentifier,
                    'parentSourceIdentifier' => $record->parentSourceIdentifier,
                    'sourceHash' => $record->sourceHash(),
                    'rawPayload' => $record->payload,
                    'recordType' => $record->recordType,
                    'statusCode' => 'extracted',
                    'extractedAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
                ++$total;
            }

            $updated = $this->batches->updateBatchStatus($batch->publicId, 'extracted', [
                'totalRecords' => $total,
            ]);
            $this->batches->recordAudit($batch->publicId, 'extraction-completed', $actor->userId, $actor->correlationId, [
                'totalRecords' => $total,
            ]);

            return $updated;
        });
    }

    public function normalize(ActorContext $actor, string $batchPublicId): MigrationBatchDetails
    {
        $batch = $this->requireBatch($batchPublicId);
        $this->assertStatus($batch, ['extracted', 'normalized', 'validated', 'ready'], 'normalize');

        return $this->transaction->run(function () use ($actor, $batch): MigrationBatchDetails {
            $this->batches->updateBatchStatus($batch->publicId, 'normalizing');
            $raw = $this->batches->listRaw($batch->publicId);
            /** @var array<string, array{0: array<string, mixed>, 1: array<string, mixed>}> $productsByKey */
            $productsByKey = [];
            /** @var array<string, list<array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}>> $variantsByParent */
            $variantsByParent = [];
            /** @var list<array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}> $specs */
            $specs = [];
            /** @var list<array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}> $media */
            $media = [];
            /** @var list<array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}> $documents */
            $documents = [];
            /** @var list<array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}> $relationships */
            $relationships = [];
            /** @var list<array{0: array<string, mixed>, 1: array<string, mixed>}> $classifications */
            $classifications = [];

            foreach ($raw as $row) {
                $type = MigrationPayload::string($row['recordType'] ?? '');
                $payload = MigrationPayload::assoc($row['rawPayload'] ?? null);
                $sourceKey = MigrationPayload::string($row['sourceSystem'] ?? '')
                    . ':' . MigrationPayload::string($row['sourceTable'] ?? '')
                    . ':' . MigrationPayload::string($row['sourceIdentifier'] ?? '');
                if ($type === 'product') {
                    $productsByKey[$sourceKey] = [$row, $payload];
                } elseif ($type === 'variant') {
                    $parent = MigrationPayload::string($row['parentSourceIdentifier'] ?? '');
                    $variantsByParent[$parent][] = [$row, $payload, $sourceKey];
                } elseif ($type === 'specification') {
                    $specs[] = [$row, $payload, $sourceKey];
                } elseif ($type === 'media') {
                    $media[] = [$row, $payload, $sourceKey];
                } elseif ($type === 'document') {
                    $documents[] = [$row, $payload, $sourceKey];
                } elseif ($type === 'relationship') {
                    $relationships[] = [$row, $payload, $sourceKey];
                } elseif ($type === 'classification') {
                    $classifications[] = [$row, $payload];
                }
            }

            foreach ($classifications as [$row, $payload]) {
                $value = MigrationPayload::string($payload['slug'] ?? $payload['name'] ?? $row['sourceIdentifier'] ?? '');
                $mapped = false;
                foreach ($this->batches->listClassificationMappings() as $m) {
                    if (MigrationPayload::string($m['statusCode'] ?? '') === 'mapped'
                        && strcasecmp(MigrationPayload::string($m['legacyClassificationValue'] ?? ''), $value) === 0) {
                        $mapped = true;
                        break;
                    }
                }
                if (!$mapped) {
                    $this->batches->addIssue($batch->publicId, [
                        'issueTypeCode' => 'unknown-classification',
                        'severityCode' => 'manual-review',
                        'sourceSystem' => MigrationPayload::stringOrNull($row['sourceSystem'] ?? null),
                        'sourceTable' => MigrationPayload::stringOrNull($row['sourceTable'] ?? null),
                        'sourceIdentifier' => MigrationPayload::stringOrNull($row['sourceIdentifier'] ?? null),
                        'message' => 'Unmapped classification: ' . $value,
                    ]);
                }
            }

            foreach ($productsByKey as $sourceKey => [$row, $payload]) {
                $postType = MigrationPayload::string($payload['post_type'] ?? 'cable', 'cable');
                $style = MigrationPayload::stringOrNull($payload['style_code'] ?? null);
                $slug = MigrationPayload::stringOrNull($payload['post_name'] ?? null);
                $name = MigrationPayload::string($payload['post_title'] ?? $sourceKey, $sourceKey);
                $probe = new CanonicalProduct(
                    sourceKey: $sourceKey,
                    legacyStyleCode: $style,
                    name: $name,
                    slug: $slug,
                    familyCode: null,
                    categoryCode: null,
                    seriesCode: null,
                    shortDescription: MigrationPayload::stringOrNull($payload['small_desc'] ?? null),
                    fullDescription: MigrationPayload::stringOrNull($payload['post_content'] ?? null),
                    status: 'draft',
                    regions: MigrationPayload::stringList($payload['regions'] ?? null, ['AU']),
                    variants: [],
                    specifications: [],
                    media: [],
                    documents: [],
                    relationships: [],
                    legacyPostType: $postType,
                );
                $decision = $this->variantClassifier->classify($probe, $postType);

                $variantModels = [];
                foreach ($variantsByParent[$sourceKey] ?? [] as [$vRow, $vPayload, $vKey]) {
                    unset($vRow);
                    $variantModels[] = new CanonicalVariant(
                        sourceKey: $vKey,
                        sku: MigrationPayload::stringOrNull($vPayload['sku'] ?? $vPayload['ida'] ?? null),
                        productCode: MigrationPayload::stringOrNull($vPayload['product_code'] ?? $vPayload['ida'] ?? null),
                        name: null,
                        legacyStyleCode: MigrationPayload::stringOrNull($vPayload['style_code'] ?? $vPayload['style'] ?? $style),
                        specifications: $this->buildSpecsForParent($specs, MigrationPayload::string($vPayload['ida'] ?? $vKey, $vKey)),
                    );
                }

                $mediaModels = [];
                foreach ($media as [$mRow, $mPayload, $mKey]) {
                    $parentOk = MigrationPayload::stringOrNull($mRow['parentSourceIdentifier'] ?? null) === $sourceKey;
                    $styleOk = MigrationPayload::stringOrNull($mPayload['style_code'] ?? null) === $style;
                    if (!$parentOk && !$styleOk) {
                        continue;
                    }
                    $exists = MigrationPayload::bool($mPayload['exists'] ?? false);
                    if (!$exists) {
                        $this->batches->addIssue($batch->publicId, [
                            'issueTypeCode' => 'missing-file',
                            'severityCode' => 'warning',
                            'sourceKey' => $mKey,
                            'message' => 'Missing media file: ' . MigrationPayload::string($mPayload['path'] ?? $mKey, $mKey),
                        ]);
                    }
                    $mediaModels[] = new CanonicalMedia(
                        sourceKey: $mKey,
                        legacyPath: MigrationPayload::stringOrNull($mPayload['path'] ?? null),
                        legacyUrl: MigrationPayload::stringOrNull($mPayload['url'] ?? null),
                        originalFileName: MigrationPayload::stringOrNull($mPayload['fileName'] ?? null),
                        roleCode: MigrationPayload::string($mPayload['role'] ?? 'primary', 'primary'),
                        checksum: MigrationPayload::stringOrNull($mPayload['checksum'] ?? null),
                        sourceExists: $exists,
                    );
                }

                $docModels = [];
                foreach ($documents as [$dRow, $dPayload, $dKey]) {
                    $parentOk = MigrationPayload::stringOrNull($dRow['parentSourceIdentifier'] ?? null) === $sourceKey;
                    $styleOk = MigrationPayload::stringOrNull($dPayload['style_code'] ?? null) === $style;
                    if (!$parentOk && !$styleOk) {
                        continue;
                    }
                    $exists = MigrationPayload::bool($dPayload['exists'] ?? false);
                    $type = MigrationPayload::stringOrNull($dPayload['documentType'] ?? null);
                    $outcome = $type !== null ? 'mapped' : 'manual-review';
                    if ($type === null) {
                        $this->batches->addIssue($batch->publicId, [
                            'issueTypeCode' => 'ambiguous-document-type',
                            'severityCode' => 'manual-review',
                            'sourceKey' => $dKey,
                            'message' => 'Document type ambiguous; not classified by extension alone',
                        ]);
                    }
                    $docModels[] = new CanonicalDocument(
                        sourceKey: $dKey,
                        documentTypeCode: $type,
                        title: MigrationPayload::stringOrNull($dPayload['title'] ?? null),
                        legacyPath: MigrationPayload::stringOrNull($dPayload['path'] ?? null),
                        legacyUrl: MigrationPayload::stringOrNull($dPayload['url'] ?? null),
                        originalFileName: MigrationPayload::stringOrNull($dPayload['fileName'] ?? null),
                        checksum: MigrationPayload::stringOrNull($dPayload['checksum'] ?? null),
                        sourceExists: $exists,
                        classificationOutcome: $outcome,
                    );
                }

                $relModels = [];
                foreach ($relationships as [$rRow, $rPayload, $rKey]) {
                    $from = MigrationPayload::string($rPayload['from'] ?? $sourceKey, $sourceKey);
                    $parent = MigrationPayload::stringOrNull($rRow['parentSourceIdentifier'] ?? null);
                    if ($from !== $sourceKey && $parent !== $sourceKey) {
                        continue;
                    }
                    $to = MigrationPayload::string($rPayload['to'] ?? '');
                    $outcome = isset($productsByKey[$to]) ? 'ready' : 'pending';
                    if ($outcome === 'pending') {
                        $this->batches->addIssue($batch->publicId, [
                            'issueTypeCode' => 'unresolved-relationship',
                            'severityCode' => 'manual-review',
                            'sourceKey' => $rKey,
                            'message' => 'Relationship target not resolved: ' . $to,
                        ]);
                    }
                    $relModels[] = new CanonicalRelationship(
                        sourceKey: $rKey,
                        relationshipTypeCode: MigrationPayload::string($rPayload['relationshipType'] ?? 'related', 'related'),
                        fromSourceKey: $from,
                        toSourceKey: $to,
                        outcome: $outcome,
                    );
                }

                $legacyPath = $postType === 'accessory'
                    ? '/accessory/' . ($slug ?? '') . '/'
                    : '/cable/' . ($slug ?? '') . '/';
                $newPath = '/products/' . ($slug ?? '');
                $slugs = [];
                if ($slug !== null && $slug !== '') {
                    $slugs[] = new CanonicalSlug(
                        legacySlug: $slug,
                        legacyPath: $legacyPath,
                        legacyFullUrl: null,
                        newSlug: $slug,
                        regionCode: 'AU',
                        redirectRequired: $legacyPath !== $newPath . '/' && $legacyPath !== $newPath,
                    );
                }

                $product = new CanonicalProduct(
                    sourceKey: $sourceKey,
                    legacyStyleCode: $style,
                    name: $name,
                    slug: $slug,
                    familyCode: null,
                    categoryCode: null,
                    seriesCode: null,
                    shortDescription: MigrationPayload::stringOrNull($payload['small_desc'] ?? null),
                    fullDescription: MigrationPayload::stringOrNull($payload['post_content'] ?? null),
                    status: 'draft',
                    regions: MigrationPayload::stringList($payload['regions'] ?? null, ['AU']),
                    variants: $variantModels,
                    specifications: [],
                    media: $mediaModels,
                    documents: $docModels,
                    relationships: $relModels,
                    slugs: $slugs,
                    legacyPostType: $postType,
                    productDecisionCode: $decision['decision'],
                    productDecisionReason: $decision['reason'],
                );

                $match = $this->identityMatcher->match($product);
                if ($match->requiresReview) {
                    $this->batches->addIssue($batch->publicId, [
                        'issueTypeCode' => 'ambiguous-product-match',
                        'severityCode' => 'manual-review',
                        'sourceKey' => $sourceKey,
                        'message' => $match->reason,
                        'matchConfidence' => $match->matchConfidence,
                        'suggestedMatch' => [
                            'matchMethod' => $match->matchMethod,
                            'targetProductPublicId' => $match->targetProductPublicId,
                        ],
                    ]);
                }

                $this->batches->upsertCanonical($batch->publicId, $product, [
                    'sourceHash' => MigrationPayload::string($row['sourceHash'] ?? ''),
                    'matchMethod' => $match->matchMethod,
                    'matchConfidence' => $match->matchConfidence,
                    'targetProductPublicId' => $match->targetProductPublicId,
                    'requiresReview' => $match->requiresReview || $decision['decision'] === ProductVariantClassifier::MANUAL_REVIEW,
                ]);
            }

            $manual = count($this->batches->listIssues($batch->publicId, 'open', 10000, 0));
            $updated = $this->batches->updateBatchStatus($batch->publicId, 'normalized', [
                'manualReviewRecords' => $manual,
            ]);
            $this->batches->recordAudit($batch->publicId, 'normalize-completed', $actor->userId, $actor->correlationId, [
                'canonicalProducts' => count($this->batches->listCanonical($batch->publicId)),
            ]);

            return $updated;
        });
    }

    public function validate(ActorContext $actor, string $batchPublicId): MigrationBatchDetails
    {
        $batch = $this->requireBatch($batchPublicId);
        $this->assertStatus($batch, ['normalized', 'validated', 'ready'], 'validate');

        return $this->transaction->run(function () use ($actor, $batch): MigrationBatchDetails {
            /** @var array<string, true> $codes */
            $codes = [];
            /** @var array<string, true> $skus */
            $skus = [];
            /** @var array<string, true> $slugs */
            $slugs = [];
            $blocking = 0;
            foreach ($this->batches->listCanonical($batch->publicId) as $product) {
                if ($product->slug !== null) {
                    $norm = strtolower($product->slug);
                    if (isset($slugs[$norm])) {
                        $this->batches->addIssue($batch->publicId, [
                            'issueTypeCode' => 'duplicate-slug',
                            'severityCode' => 'blocking',
                            'sourceKey' => $product->sourceKey,
                            'message' => 'Duplicate slug: ' . $product->slug,
                        ]);
                        ++$blocking;
                    }
                    $slugs[$norm] = true;
                }
                foreach ($product->variants as $variant) {
                    $code = $variant->productCode;
                    $sku = $variant->sku;
                    if ($code !== null) {
                        if (isset($codes[$code])) {
                            $this->batches->addIssue($batch->publicId, [
                                'issueTypeCode' => 'duplicate-product-code',
                                'severityCode' => 'blocking',
                                'sourceKey' => $variant->sourceKey,
                                'message' => 'Duplicate product code: ' . $code . ' (no silent suffix)',
                            ]);
                            ++$blocking;
                        }
                        $codes[$code] = true;
                    }
                    if ($sku !== null) {
                        if (isset($skus[$sku])) {
                            $this->batches->addIssue($batch->publicId, [
                                'issueTypeCode' => 'duplicate-sku',
                                'severityCode' => 'blocking',
                                'sourceKey' => $variant->sourceKey,
                                'message' => 'Duplicate SKU: ' . $sku,
                            ]);
                            ++$blocking;
                        }
                        $skus[$sku] = true;
                    }
                }
            }

            $status = $blocking > 0 ? 'validated' : 'ready';
            $manual = count($this->batches->listIssues($batch->publicId, 'open', 10000, 0));
            $updated = $this->batches->updateBatchStatus($batch->publicId, $status, [
                'failedRecords' => $blocking,
                'manualReviewRecords' => $manual,
            ]);
            $this->batches->recordAudit($batch->publicId, 'validate-completed', $actor->userId, $actor->correlationId, [
                'blocking' => $blocking,
            ]);

            return $updated;
        });
    }

    public function dryRun(ActorContext $actor, string $batchPublicId): DryRunReport
    {
        $batch = $this->requireBatch($batchPublicId);
        $this->assertStatus($batch, ['normalized', 'validated', 'ready', 'completed', 'completed-with-warnings'], 'dry-run');

        return $this->transaction->run(function () use ($actor, $batch): DryRunReport {
            $canonical = $this->batches->listCanonical($batch->publicId);
            $issues = $this->batches->listIssues($batch->publicId, 'open', 10000, 0);
            $warnings = [];
            $blocking = [];
            $unresolved = [];
            $matched = 0;
            $ready = 0;
            $needsReview = 0;
            $variants = 0;
            $missingFiles = 0;
            $orphanRels = 0;

            foreach ($canonical as $product) {
                $variants += count($product->variants);
                if ($product->productDecisionCode === ProductVariantClassifier::MANUAL_REVIEW) {
                    ++$needsReview;
                } else {
                    ++$ready;
                }
                foreach ($product->media as $m) {
                    if (!$m->sourceExists) {
                        ++$missingFiles;
                        $warnings[] = 'Missing media: ' . $m->sourceKey;
                    }
                }
                foreach ($product->documents as $d) {
                    if (!$d->sourceExists) {
                        ++$missingFiles;
                        $warnings[] = 'Missing document: ' . $d->sourceKey;
                    }
                }
                foreach ($product->relationships as $r) {
                    if ($r->outcome === 'pending') {
                        ++$orphanRels;
                    }
                }
            }

            foreach ($issues as $issue) {
                if ($issue->severityCode === 'blocking') {
                    $blocking[] = $issue->message;
                } elseif ($issue->severityCode === 'manual-review') {
                    $unresolved[] = $issue->toArray();
                    ++$needsReview;
                } else {
                    $warnings[] = $issue->message;
                }
                $suggested = $issue->suggestedMatch;
                if (is_array($suggested) && ($suggested['targetProductPublicId'] ?? null) !== null) {
                    ++$matched;
                }
            }

            $sim = $this->batches->executeImport($batch->publicId, true);
            $unknownClassifications = 0;
            $dupCodes = 0;
            $dupSkus = 0;
            $dupSlugs = 0;
            foreach ($issues as $issue) {
                if ($issue->issueTypeCode === 'unknown-classification') {
                    ++$unknownClassifications;
                } elseif ($issue->issueTypeCode === 'duplicate-product-code') {
                    ++$dupCodes;
                } elseif ($issue->issueTypeCode === 'duplicate-sku') {
                    ++$dupSkus;
                } elseif ($issue->issueTypeCode === 'duplicate-slug') {
                    ++$dupSlugs;
                }
            }

            $report = new DryRunReport(
                batchPublicId: $batch->publicId,
                counts: [
                    'productsDiscovered' => count($canonical),
                    'productsMatched' => $matched,
                    'productsReady' => $ready,
                    'productsNeedingReview' => $needsReview,
                    'variantsDiscovered' => $variants,
                    'variantsReady' => $variants,
                    'unresolvedClassifications' => $unknownClassifications,
                    'unresolvedSpecificationDefinitions' => 0,
                    'unresolvedOptions' => 0,
                    'duplicateProductCodes' => $dupCodes,
                    'duplicateSkus' => $dupSkus,
                    'duplicateSlugs' => $dupSlugs,
                    'invalidValues' => 0,
                    'missingFiles' => $missingFiles,
                    'orphanRelationships' => $orphanRels,
                    'simulatedImported' => $sim['imported'],
                    'simulatedSkipped' => $sim['skipped'],
                ],
                warnings: $warnings,
                blockingErrors: $blocking,
                unresolved: $unresolved,
                wouldWriteProducts: false,
                wouldWriteBlobs: false,
            );
            $this->batches->saveDryRunReport($batch->publicId, $report);
            $this->batches->recordAudit($batch->publicId, 'dry-run-completed', $actor->userId, $actor->correlationId, [
                'productsDiscovered' => count($canonical),
                'blockingErrors' => count($blocking),
            ]);

            return $report;
        });
    }

    public function execute(ActorContext $actor, string $batchPublicId): MigrationBatchDetails
    {
        $batch = $this->requireBatch($batchPublicId);
        $this->assertStatus($batch, ['ready', 'validated', 'completed-with-warnings', 'failed'], 'execute');
        if ($batch->dryRun) {
            throw InvalidMigrationBatchState::for($batch->publicId, 'dryRun=true', 'execute (set dryRun false on a non-dry batch)');
        }
        $source = $this->source ?? FixtureLegacyProductSource::pilotSample();
        if ($source instanceof LiveLegacySourceMarker && $source->isLiveReadOnly()) {
            throw InvalidMigrationBatchState::for(
                $batch->publicId,
                'live-read-only-source',
                'execute (live legacy sources permit DryRun only; Product/Blob writes are blocked)',
            );
        }

        return $this->transaction->run(function () use ($actor, $batch): MigrationBatchDetails {
            $this->batches->updateBatchStatus($batch->publicId, 'running', [], [
                'startedAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            $this->batches->recordAudit($batch->publicId, 'batch-executed', $actor->userId, $actor->correlationId, null);

            $result = $this->batches->executeImport($batch->publicId, false);
            $status = $result['failed'] > 0
                ? 'failed'
                : ($result['warning'] > 0 || $result['manualReview'] > 0 ? 'completed-with-warnings' : 'completed');

            $updated = $this->batches->updateBatchStatus($batch->publicId, $status, [
                'importedRecords' => $result['imported'],
                'updatedRecords' => $result['updated'],
                'skippedRecords' => $result['skipped'],
                'warningRecords' => $result['warning'],
                'failedRecords' => $result['failed'],
                'manualReviewRecords' => $result['manualReview'],
            ], [
                'completedAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            $this->batches->recordAudit($batch->publicId, 'batch-execute-completed', $actor->userId, $actor->correlationId, $result);

            return $updated;
        });
    }

    public function cancel(ActorContext $actor, string $batchPublicId): MigrationBatchDetails
    {
        $batch = $this->requireBatch($batchPublicId);
        $this->assertStatus($batch, ['draft', 'extracting', 'extracted', 'normalizing', 'normalized', 'validated', 'ready', 'running'], 'cancel');

        return $this->transaction->run(function () use ($actor, $batch): MigrationBatchDetails {
            $updated = $this->batches->updateBatchStatus($batch->publicId, 'cancelled', [], [
                'completedAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            $this->batches->recordAudit($batch->publicId, 'batch-cancelled', $actor->userId, $actor->correlationId, null);

            return $updated;
        });
    }

    public function retry(ActorContext $actor, string $batchPublicId): MigrationBatchDetails
    {
        $batch = $this->requireBatch($batchPublicId);
        $this->assertStatus($batch, ['failed', 'completed-with-warnings'], 'retry');

        return $this->execute($actor, $batchPublicId);
    }

    /** @return array{rolledBack: int, protected: int} */
    public function rollback(ActorContext $actor, string $batchPublicId, bool $forceProtected = false): array
    {
        $batch = $this->requireBatch($batchPublicId);
        $this->assertStatus($batch, ['completed', 'completed-with-warnings', 'failed', 'rolled-back'], 'rollback');

        return $this->transaction->run(function () use ($actor, $batch, $forceProtected): array {
            $this->batches->recordAudit($batch->publicId, 'rollback-requested', $actor->userId, $actor->correlationId, [
                'forceProtected' => $forceProtected,
            ]);
            $result = $this->batches->rollbackBatch($batch->publicId, $forceProtected);
            if ($result['protected'] > 0 && !$forceProtected) {
                $this->batches->addIssue($batch->publicId, [
                    'issueTypeCode' => 'rollback-protected',
                    'severityCode' => 'manual-review',
                    'message' => $result['protected'] . ' manually edited record(s) require approval before rollback',
                ]);
            } else {
                $this->batches->updateBatchStatus($batch->publicId, 'rolled-back', [], [
                    'rolledBackAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
                    'rolledBackByUserId' => $actor->userId,
                ]);
            }
            $this->batches->recordAudit($batch->publicId, 'rollback-completed', $actor->userId, $actor->correlationId, $result);

            return $result;
        });
    }

    /** @return array<string, mixed> */
    public function reconcile(ActorContext $actor, string $batchPublicId): array
    {
        $batch = $this->requireBatch($batchPublicId);
        $canonical = $this->batches->listCanonical($batchPublicId);
        $raw = $this->batches->listRaw($batchPublicId);
        $sourceProducts = 0;
        $sourceVariants = 0;
        $sourceMedia = 0;
        $sourceDocs = 0;
        $sourceRels = 0;
        foreach ($raw as $r) {
            $type = MigrationPayload::string($r['recordType'] ?? '');
            if ($type === 'product') {
                ++$sourceProducts;
            } elseif ($type === 'variant') {
                ++$sourceVariants;
            } elseif ($type === 'media') {
                ++$sourceMedia;
            } elseif ($type === 'document') {
                ++$sourceDocs;
            } elseif ($type === 'relationship') {
                ++$sourceRels;
            }
        }

        $importedVariants = 0;
        $copiedMedia = 0;
        $missingMedia = 0;
        $importedDocs = 0;
        $missingDocs = 0;
        $resolvedRels = 0;
        $pendingRels = 0;
        foreach ($canonical as $p) {
            $importedVariants += count($p->variants);
            foreach ($p->media as $m) {
                $m->sourceExists ? ++$copiedMedia : ++$missingMedia;
            }
            foreach ($p->documents as $d) {
                $d->sourceExists ? ++$importedDocs : ++$missingDocs;
            }
            foreach ($p->relationships as $r) {
                $r->outcome === 'ready' ? ++$resolvedRels : ++$pendingRels;
            }
        }

        $report = [
            'batchPublicId' => $batchPublicId,
            'sourceProducts' => $sourceProducts,
            'importedProducts' => $batch->importedRecords,
            'updatedProducts' => $batch->updatedRecords,
            'skippedProducts' => $batch->skippedRecords,
            'failedProducts' => $batch->failedRecords,
            'sourceVariants' => $sourceVariants,
            'importedVariants' => $importedVariants,
            'sourceMedia' => $sourceMedia,
            'copiedMedia' => $copiedMedia,
            'missingMedia' => $missingMedia,
            'sourceDocuments' => $sourceDocs,
            'importedDocuments' => $importedDocs,
            'missingDocuments' => $missingDocs,
            'sourceRelationships' => $sourceRels,
            'resolvedRelationships' => $resolvedRels,
            'pendingRelationships' => $pendingRels,
            'publicationDefault' => 'draft',
        ];
        $saved = $this->batches->saveReconciliation($batchPublicId, $report);
        $this->batches->recordAudit($batchPublicId, 'reconcile-completed', $actor->userId, $actor->correlationId, [
            'sourceProducts' => $sourceProducts,
            'importedProducts' => $batch->importedRecords,
        ]);

        return MigrationPayload::assoc($saved);
    }

    /** @return list<MigrationIssueDetails> */
    public function listIssues(?string $batchPublicId = null, ?string $statusCode = 'open', int $limit = 100, int $offset = 0): array
    {
        return $this->batches->listIssues($batchPublicId, $statusCode, $limit, $offset);
    }

    /** @param array<string, mixed> $resolution */
    public function resolveIssue(ActorContext $actor, string $issuePublicId, array $resolution): MigrationIssueDetails
    {
        return $this->transaction->run(function () use ($actor, $issuePublicId, $resolution): MigrationIssueDetails {
            $issue = $this->batches->resolveIssue($issuePublicId, $resolution, $actor->userId);
            $this->batches->recordAudit($issue->batchPublicId, 'manual-review-resolved', $actor->userId, $actor->correlationId, [
                'issuePublicId' => $issuePublicId,
                'issueTypeCode' => $issue->issueTypeCode,
            ]);

            return $issue;
        });
    }

    /** @return list<array<string, mixed>> */
    public function listClassificationMappings(): array
    {
        return $this->batches->listClassificationMappings();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function upsertClassificationMapping(ActorContext $actor, string $publicId, array $data): array
    {
        return $this->transaction->run(function () use ($actor, $publicId, $data): array {
            $row = $this->batches->upsertClassificationMapping($publicId, $data);
            $this->batches->recordAudit(null, 'classification-mapping-changed', $actor->userId, $actor->correlationId, [
                'publicId' => MigrationPayload::string($row['publicId'] ?? $publicId, $publicId),
            ]);

            return $row;
        });
    }

    /** @return list<array<string, mixed>> */
    public function listSpecificationMappings(): array
    {
        return $this->batches->listSpecificationMappings();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function upsertSpecificationMapping(ActorContext $actor, string $publicId, array $data): array
    {
        return $this->transaction->run(function () use ($actor, $publicId, $data): array {
            $row = $this->batches->upsertSpecificationMapping($publicId, $data);
            $this->batches->recordAudit(null, 'specification-mapping-changed', $actor->userId, $actor->correlationId, [
                'publicId' => MigrationPayload::string($row['publicId'] ?? $publicId, $publicId),
            ]);

            return $row;
        });
    }

    /** @return list<array<string, mixed>> */
    public function listOptionAliases(): array
    {
        return $this->batches->listOptionAliases();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function upsertOptionAlias(ActorContext $actor, string $publicId, array $data): array
    {
        return $this->transaction->run(function () use ($actor, $publicId, $data): array {
            if (isset($data['definitionCode'], $data['legacyValue'], $data['targetOptionCode'])) {
                $this->optionAliases->register(
                    MigrationPayload::string($data['definitionCode']),
                    MigrationPayload::string($data['legacyValue']),
                    MigrationPayload::string($data['targetOptionCode']),
                );
            }
            $row = $this->batches->upsertOptionAlias($publicId, $data);
            $this->batches->recordAudit(null, 'option-alias-changed', $actor->userId, $actor->correlationId, [
                'publicId' => MigrationPayload::string($row['publicId'] ?? $publicId, $publicId),
            ]);

            return $row;
        });
    }

    public function getLatestDryRunReport(string $batchPublicId): ?DryRunReport
    {
        return $this->batches->getLatestDryRunReport($batchPublicId);
    }

    /** @return array<string, mixed>|null */
    public function getLatestReconciliation(string $batchPublicId): ?array
    {
        return $this->batches->getLatestReconciliation($batchPublicId);
    }

    private function requireBatch(string $publicId): MigrationBatchDetails
    {
        $batch = $this->batches->getBatchByPublicId($publicId);
        if ($batch === null) {
            throw MigrationBatchNotFound::forPublicId($publicId);
        }

        return $batch;
    }

    /** @param list<string> $allowed */
    private function assertStatus(MigrationBatchDetails $batch, array $allowed, string $action): void
    {
        if (!in_array($batch->statusCode, $allowed, true)) {
            throw InvalidMigrationBatchState::for($batch->publicId, $batch->statusCode, $action);
        }
    }

    /**
     * @param list<array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}> $specs
     * @return list<CanonicalSpecificationValue>
     */
    private function buildSpecsForParent(array $specs, string $parentId): array
    {
        $out = [];
        foreach ($specs as [$row, $payload, $sourceKey]) {
            $parent = MigrationPayload::string($payload['parent'] ?? $row['parentSourceIdentifier'] ?? '');
            if ($parent !== $parentId && !str_ends_with($sourceKey, $parentId)) {
                continue;
            }
            $field = MigrationPayload::string($payload['field'] ?? 'unknown', 'unknown');
            $value = MigrationPayload::string($payload['value'] ?? '');
            $unit = MigrationPayload::stringOrNull($payload['unit'] ?? null);
            $definitionCode = null;
            $rule = 'text';
            $scope = 'variant';
            foreach ($this->batches->listSpecificationMappings() as $m) {
                if (MigrationPayload::string($m['statusCode'] ?? '') === 'mapped'
                    && strcasecmp(MigrationPayload::string($m['sourceField'] ?? ''), $field) === 0) {
                    $definitionCode = MigrationPayload::stringOrNull($m['targetDefinitionCode'] ?? null);
                    $rule = MigrationPayload::string($m['transformationRule'] ?? 'text', 'text');
                    $scope = MigrationPayload::string($m['targetScopeCode'] ?? 'variant', 'variant');
                    $unit = $unit ?? MigrationPayload::stringOrNull($m['targetUnit'] ?? null);
                    break;
                }
            }
            $out[] = $this->transformer->transform(
                $field,
                $value,
                $field === 'od' ? 'decimal' : $rule,
                $definitionCode ?? ($field === 'od' ? 'OUTER_DIAMETER' : null),
                $unit ?? ($field === 'od' ? 'MM' : null),
                $scope,
                fn (?string $def, string $legacy): ?string => $this->optionAliases->resolve($def, $legacy),
            );
        }

        return $out;
    }
}
