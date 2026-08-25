<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\LegacyStyleOccurrence;
use TriCab\Core\Product\Application\DTO\StyleInventoryDiscoveryReport;
use TriCab\Core\Product\Application\DTO\StyleInventoryItem;
use TriCab\Core\Product\Application\DTO\StyleInventorySourceStat;
use TriCab\Core\Product\Contract\LegacyStyleOccurrenceSource;
use TriCab\Core\Product\Contract\ProductStyleRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductStyleCode;
use TriCab\Core\Product\Migration\Readiness\MigrationReadinessStatus;
use TriCab\Core\Product\Migration\Readiness\StyleCodeNormalizer;

/**
 * Builds a canonical style inventory from legacy sources. Never writes Azure,
 * WordPress, tc MySQL, filesystem, or blob storage.
 */
final class DiscoverLegacyStyleInventoryService
{
    private const KNOWN_SMOKE_STYLE_CODES = ['BVA-X'];

    public function __construct(
        private readonly ProductStyleRepository $styles,
        private readonly LegacyStyleOccurrenceSource $source,
        private readonly StyleCodeNormalizer $normalizer = new StyleCodeNormalizer(),
    ) {
    }

    public function discover(): StyleInventoryDiscoveryReport
    {
        $stats = $this->source->sourceStats();
        $occurrences = $this->source->listOccurrences();
        $targetByCode = [];
        foreach ($this->styles->listAll(false) as $style) {
            $targetByCode[$style->styleCode] = $style;
        }

        $sourceStyleRows = 0;
        $nonEmptyStyleRows = 0;
        foreach ($stats as $stat) {
            $sourceStyleRows += $stat->rowCount;
            $nonEmptyStyleRows += $stat->nonEmptyStyleCount;
        }

        $blank = 0;
        $invalid = 0;
        $review = 0;
        $valid = 0;
        $rawDistinct = [];
        /** @var array<string, array<string, mixed>> $groups */
        $groups = [];
        $ungroupedReview = [];
        $identityCollisions = 0;

        foreach ($occurrences as $occurrence) {
            $classified = $this->classifyOccurrence($occurrence);
            $rawKey = $occurrence->rawStyleCode;
            $rawDistinct[$rawKey] = true;
            if ($classified['status'] === 'INVALID' && in_array('blank_style_code', $classified['warnings'], true)) {
                ++$blank;
            }
            if ($classified['canonical'] === null) {
                if ($classified['status'] === 'INVALID') {
                    ++$invalid;
                } else {
                    ++$review;
                }
                $ungroupedReview[] = $this->itemFromOccurrence($occurrence, $classified, $targetByCode);
                continue;
            }
            $canonical = (string) $classified['canonical'];
            if (!isset($groups[$canonical])) {
                $groups[$canonical] = [
                    'canonical' => $canonical,
                    'raws' => [],
                    'sources' => [],
                    'occurrences' => 0,
                    'productCount' => 0,
                    'samples' => [],
                    'family' => null,
                    'warnings' => $classified['warnings'],
                    'status' => $classified['status'],
                    'reason' => $classified['reason'],
                    'displayName' => $canonical,
                    'displayNameSource' => 'FALLBACK_STYLE_CODE',
                ];
            }
            $groups[$canonical]['raws'][(string) $occurrence->rawStyleCode] = true;
            $groups[$canonical]['sources'][$occurrence->source . ':' . $occurrence->tableEntity . '.' . $occurrence->field] = true;
            $groups[$canonical]['occurrences'] += $occurrence->sourceOccurrences;
            $groups[$canonical]['productCount'] += $occurrence->productCount;
            foreach ($occurrence->sampleProducts as $sample) {
                if ($sample !== '' && count($groups[$canonical]['samples']) < 5) {
                    $groups[$canonical]['samples'][] = $sample;
                }
            }
            if ($groups[$canonical]['family'] === null && $occurrence->familyEvidence !== null && $occurrence->familyEvidence !== '') {
                $groups[$canonical]['family'] = $occurrence->familyEvidence;
            }
            $groups[$canonical]['warnings'] = array_values(array_unique(array_merge(
                $groups[$canonical]['warnings'],
                $classified['warnings'],
            )));
            if ($classified['status'] === 'REVIEW' || $groups[$canonical]['status'] === 'REVIEW') {
                $groups[$canonical]['status'] = 'REVIEW';
            } elseif ($classified['status'] === 'INVALID') {
                $groups[$canonical]['status'] = 'INVALID';
            }
        }

        $items = [];
        foreach ($groups as $canonical => $group) {
            $canonical = (string) $canonical;
            $target = $targetByCode[$canonical] ?? null;
            $status = (string) $group['status'];
            $raws = array_map(static fn (mixed $raw): string => (string) $raw, array_keys($group['raws']));
            sort($raws);
            $sources = array_keys($group['sources']);
            sort($sources);
            $targetClassification = $this->targetClassification($canonical, $target !== null, true);
            if ($targetClassification === 'POSSIBLE_TEST_DATA' && $status === 'VALID') {
                $status = 'REVIEW';
            }
            $targetMatch = $this->targetMatch($status, $target !== null);
            if ($status === 'VALID') {
                ++$valid;
            } elseif ($status === 'REVIEW') {
                ++$review;
            } else {
                ++$invalid;
            }
            $warnings = $group['warnings'];
            if (count($raws) > 1) {
                $warnings[] = 'Multiple raw forms collapsed: ' . implode(', ', $raws);
            }
            $items[] = new StyleInventoryItem(
                rawStyleCode: $raws[0] ?? $canonical,
                normalizedStyleCode: $canonical,
                normalisationReason: (string) $group['reason'],
                rawStyleCodes: $raws,
                sources: $sources,
                sourceOccurrences: (int) $group['occurrences'],
                productCount: (int) $group['productCount'],
                sampleProducts: array_values(array_unique($group['samples'])),
                familyEvidence: $group['family'],
                existingTargetStyleId: $target?->publicId,
                status: $status,
                targetMatch: $targetMatch,
                targetClassification: $targetClassification,
                displayName: (string) $group['displayName'],
                displayNameSource: (string) $group['displayNameSource'],
                warnings: array_values(array_unique($warnings)),
            );
        }
        foreach ($ungroupedReview as $item) {
            $items[] = $item;
        }
        usort($items, static fn (StyleInventoryItem $a, StyleInventoryItem $b): int => strcmp(
            (string) ($a->normalizedStyleCode ?? $a->rawStyleCode),
            (string) ($b->normalizedStyleCode ?? $b->rawStyleCode),
        ));

        $legacyCanonical = [];
        $possibleTest = [];
        foreach ($items as $item) {
            if ($item->normalizedStyleCode !== null && $item->status !== 'INVALID') {
                $legacyCanonical[$item->normalizedStyleCode] = $item;
            }
            if ($item->targetClassification === 'POSSIBLE_TEST_DATA') {
                $possibleTest[] = $item;
            }
        }

        $targetOnly = [];
        foreach ($targetByCode as $code => $style) {
            if (isset($legacyCanonical[$code])) {
                continue;
            }
            $classification = $this->targetClassification($code, true, false);
            $warnings = ['Present in [product].[ProductStyles] with no legacy evidence.'];
            if ($classification === 'POSSIBLE_TEST_DATA') {
                $warnings[] = 'Possible development/smoke-test style. Do not treat as a confirmed legacy style.';
            }
            $row = new StyleInventoryItem(
                rawStyleCode: $code,
                normalizedStyleCode: $code,
                normalisationReason: 'target_only_no_legacy_evidence',
                rawStyleCodes: [$code],
                sources: ['azure-sql:product.ProductStyles.StyleCode'],
                sourceOccurrences: 0,
                productCount: $style->productCount,
                sampleProducts: [],
                familyEvidence: null,
                existingTargetStyleId: $style->publicId,
                status: 'REVIEW',
                targetMatch: 'TARGET_ONLY',
                targetClassification: $classification,
                displayName: $style->displayName,
                displayNameSource: 'REVIEW',
                warnings: $warnings,
            );
            $targetOnly[] = $row;
            if ($classification === 'POSSIBLE_TEST_DATA') {
                $possibleTest[] = $row;
            }
        }

        $rawVariations = [];
        foreach ($items as $item) {
            if ($item->normalizedStyleCode !== null && count($item->rawStyleCodes) > 1) {
                $rawVariations[$item->normalizedStyleCode] = $item->rawStyleCodes;
            }
        }

        $matched = 0;
        $missing = 0;
        foreach ($items as $item) {
            if ($item->targetMatch === 'EXISTS_MATCH') {
                ++$matched;
            } elseif ($item->targetMatch === 'MISSING_TARGET') {
                ++$missing;
            }
        }

        $duplicatesCollapsed = max(0, count($rawDistinct) - count($groups));
        $extractionSucceeded = $this->extractionSucceeded($stats);
        $canonicalisationDeterministic = $identityCollisions === 0;
        $readyToCreate = $extractionSucceeded && $canonicalisationDeterministic && $identityCollisions === 0;

        $counters = [
            'sourceStyleRows' => $sourceStyleRows,
            'nonEmptyStyleRows' => $nonEmptyStyleRows,
            'rawDistinctStyles' => count($rawDistinct),
            'canonicalDistinctStyles' => count($groups),
            'duplicatesCollapsed' => $duplicatesCollapsed,
            'existingTargetStyles' => count($targetByCode),
            'matchedTargetStyles' => $matched,
            'missingTargetStyles' => $missing,
            'targetOnlyStyles' => count($targetOnly),
            'valid' => $valid,
            'review' => $review + count($targetOnly),
            'invalid' => $invalid,
            'blankStyles' => $blank,
            'conflicts' => $identityCollisions,
            'created' => 0,
            'skipped' => 0,
            'wordpressReads' => $this->source->wordpressReads(),
            'tcReads' => $this->source->tcReads(),
            'filesystemReads' => $this->source->filesystemReads(),
        ];

        return new StyleInventoryDiscoveryReport(
            sourceStats: $stats,
            items: $items,
            rawVariationsByCanonical: $rawVariations,
            targetOnly: $targetOnly,
            possibleTestData: $possibleTest,
            counters: $counters,
            extractionSucceeded: $extractionSucceeded,
            canonicalisationDeterministic: $canonicalisationDeterministic,
            identityCollisions: $identityCollisions,
            readyToCreate: $readyToCreate,
            notes: [
                'Discovery is dry-run. No Azure ProductStyles rows were created.',
                'Normalisation is trim + uppercase only. Hyphens, slashes, digits and suffixes are preserved.',
                'BVA-X is not treated as a confirmed legacy style merely because it exists in Azure.',
                'Family/Category/Series are not Style Hierarchy.',
                'Display names use StyleCode fallback unless an authoritative style name source exists.',
            ],
            writeGuard: [
                'wordpressWrites' => 0,
                'tcWrites' => 0,
                'filesystemWrites' => 0,
                'azureSqlWrites' => 0,
                'blobWrites' => 0,
                'persisted' => false,
                'forbiddenWriteAttempts' => 0,
            ],
        );
    }

    /**
     * @param array<string, \TriCab\Core\Product\Application\DTO\ProductStyleRecord> $targetByCode
     */
    private function itemFromOccurrence(
        LegacyStyleOccurrence $occurrence,
        array $classified,
        array $targetByCode,
    ): StyleInventoryItem {
        $canonical = $classified['canonical'];
        $target = is_string($canonical) ? ($targetByCode[$canonical] ?? null) : null;

        return new StyleInventoryItem(
            rawStyleCode: $occurrence->rawStyleCode,
            normalizedStyleCode: $canonical,
            normalisationReason: $classified['reason'],
            rawStyleCodes: [$occurrence->rawStyleCode],
            sources: [$occurrence->source . ':' . $occurrence->tableEntity . '.' . $occurrence->field],
            sourceOccurrences: $occurrence->sourceOccurrences,
            productCount: $occurrence->productCount,
            sampleProducts: $occurrence->sampleProducts,
            familyEvidence: $occurrence->familyEvidence,
            existingTargetStyleId: $target?->publicId,
            status: $classified['status'],
            targetMatch: $this->targetMatch($classified['status'], $target !== null),
            targetClassification: $canonical !== null
                ? $this->targetClassification($canonical, $target !== null, true)
                : 'REVIEW',
            displayName: $canonical ?? $occurrence->rawStyleCode,
            displayNameSource: $classified['status'] === 'VALID' ? 'FALLBACK_STYLE_CODE' : 'REVIEW',
            warnings: $classified['warnings'],
        );
    }

    /**
     * @return array{canonical: ?string, status: string, reason: string, warnings: list<string>}
     */
    private function classifyOccurrence(LegacyStyleOccurrence $occurrence): array
    {
        $normalized = $this->normalizer->normalize($occurrence->rawStyleCode);
        $warnings = $normalized['issues'];
        $reason = 'trim_and_uppercase';
        if ($normalized['isBlank']) {
            return [
                'canonical' => null,
                'status' => 'INVALID',
                'reason' => 'blank',
                'warnings' => $warnings,
            ];
        }
        if ($normalized['status'] === MigrationReadinessStatus::INVALID) {
            return [
                'canonical' => $normalized['normalized'],
                'status' => 'INVALID',
                'reason' => implode(',', $warnings) ?: 'invalid',
                'warnings' => $warnings,
            ];
        }
        $value = $normalized['normalized'] ?? strtoupper(trim($occurrence->rawStyleCode));
        if ($this->isPlaceholder($occurrence->rawStyleCode) || $this->isPlaceholder($value)) {
            $warnings[] = 'placeholder_or_test_value';

            return [
                'canonical' => $value !== '' ? $value : null,
                'status' => 'REVIEW',
                'reason' => 'placeholder',
                'warnings' => $warnings,
            ];
        }
        if ($normalized['status'] === MigrationReadinessStatus::REVIEW) {
            return [
                'canonical' => $normalized['normalized'],
                'status' => 'REVIEW',
                'reason' => implode(',', $warnings) ?: 'review',
                'warnings' => $warnings,
            ];
        }
        if (strlen($value) > 50) {
            $warnings[] = 'exceeds_style_code_max_length';

            return [
                'canonical' => $value,
                'status' => 'INVALID',
                'reason' => 'too_long',
                'warnings' => $warnings,
            ];
        }
        if (strlen($value) > 20) {
            $warnings[] = 'unexpectedly_long_style_code';

            return [
                'canonical' => $value,
                'status' => 'REVIEW',
                'reason' => 'unexpectedly_long',
                'warnings' => $warnings,
            ];
        }
        if ($this->looksLikeDescription($value)) {
            $warnings[] = 'possible_description_stored_as_style_code';

            return [
                'canonical' => $value,
                'status' => 'REVIEW',
                'reason' => 'possible_description',
                'warnings' => $warnings,
            ];
        }
        try {
            $canonical = ProductStyleCode::fromString($value)->toString();
        } catch (\Throwable) {
            $warnings[] = 'rejected_by_product_style_code';

            return [
                'canonical' => $value,
                'status' => 'REVIEW',
                'reason' => 'malformed',
                'warnings' => $warnings,
            ];
        }
        if ($this->isLikelyTestCode($canonical)) {
            $warnings[] = 'possible_development_or_test_style';

            return [
                'canonical' => $canonical,
                'status' => 'REVIEW',
                'reason' => $reason,
                'warnings' => $warnings,
            ];
        }

        return [
            'canonical' => $canonical,
            'status' => 'VALID',
            'reason' => $reason,
            'warnings' => $warnings,
        ];
    }

    private function targetMatch(string $status, bool $existsOnTarget): string
    {
        if ($status === 'INVALID') {
            return 'REVIEW';
        }
        if ($existsOnTarget) {
            return $status === 'VALID' ? 'EXISTS_MATCH' : 'REVIEW';
        }

        return $status === 'VALID' ? 'MISSING_TARGET' : 'REVIEW';
    }

    private function targetClassification(string $code, bool $existsOnTarget, bool $hasLegacyEvidence): string
    {
        if ($this->isLikelyTestCode($code)) {
            return 'POSSIBLE_TEST_DATA';
        }
        if ($hasLegacyEvidence) {
            return 'CONFIRMED_LEGACY';
        }
        if ($existsOnTarget) {
            return 'TARGET_ONLY';
        }

        return 'REVIEW';
    }

    private function isPlaceholder(string $raw): bool
    {
        $value = strtolower(trim($raw));

        return in_array($value, [
            'n/a', 'na', 'none', 'null', 'test', 'tbd', 'todo', 'xxx', 'xxxx',
            'unknown', '-', '—', '.', 'placeholder', 'dummy', 'smoke', 'dev',
        ], true);
    }

    private function looksLikeDescription(string $value): bool
    {
        return str_contains($value, ' ') || strlen($value) > 20;
    }

    private function isLikelyTestCode(string $code): bool
    {
        if (in_array($code, self::KNOWN_SMOKE_STYLE_CODES, true)) {
            return true;
        }

        return preg_match('/^(TEST|SMOKE|DUMMY|DEV)([._-].*)?$/', $code) === 1;
    }

    /**
     * @param list<StyleInventorySourceStat> $stats
     */
    private function extractionSucceeded(array $stats): bool
    {
        $legacy = array_filter(
            $stats,
            static fn (StyleInventorySourceStat $s): bool => in_array($s->source, ['wordpress', 'tc-mysql'], true),
        );
        if ($legacy === []) {
            return false;
        }
        foreach ($legacy as $stat) {
            if ($stat->available) {
                return true;
            }
        }

        return false;
    }
}
