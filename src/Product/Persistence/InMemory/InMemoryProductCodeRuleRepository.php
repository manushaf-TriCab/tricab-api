<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Contract\ProductCodeRuleRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductCodeValue;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * @phpstan-import-type RuleSet from ProductCodeRuleRepository
 */
final class InMemoryProductCodeRuleRepository implements ProductCodeRuleRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function listRuleSets(bool $activeOnly = true): array
    {
        $items = [];
        foreach ($this->store->ruleSets() as $row) {
            if ($activeOnly && !($row['isActive'] ?? true)) {
                continue;
            }
            $items[] = $this->hydrateRuleSet($row);
        }

        return $items;
    }

    public function preview(
        string $ruleSetPublicId,
        array $segmentValues,
    ): array {
        $ruleSet = null;
        foreach ($this->store->ruleSets() as $row) {
            if (($row['publicId'] ?? '') === $ruleSetPublicId) {
                $ruleSet = $this->hydrateRuleSet($row);
                break;
            }
        }
        if ($ruleSet === null) {
            throw new \RuntimeException(sprintf('Product code rule set "%s" was not found.', $ruleSetPublicId));
        }

        $assembled = '';
        $explanationParts = [];
        foreach ($ruleSet['segments'] as $segment) {
            $value = match ($segment['segmentTypeCode']) {
                'literal' => $segment['literalValue'],
                default => $segmentValues[$segment['segmentCode']] ?? null,
            };
            if (($value === null || $value === '') && $segment['isRequired']) {
                throw new \RuntimeException(sprintf('Required segment "%s" is missing.', $segment['segmentCode']));
            }
            if ($value === null || $value === '') {
                continue;
            }
            $assembled .= $value;
            if ($segment['separatorAfter'] !== null) {
                $assembled .= $segment['separatorAfter'];
            }
            $explanationParts[] = sprintf('%s: %s', $segment['segmentName'], $value);
        }

        return [
            'code' => $assembled,
            'explanation' => implode('; ', $explanationParts),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return RuleSet
     */
    private function hydrateRuleSet(array $row): array
    {
        $segments = [];
        $segmentsRaw = $row['segments'] ?? [];
        if (is_array($segmentsRaw)) {
            foreach ($segmentsRaw as $segmentRow) {
                if (!is_array($segmentRow)) {
                    continue;
                }
                $segments[] = [
                    'segmentCode' => AzureSqlValueMapper::stringValue($segmentRow['segmentCode'] ?? ''),
                    'segmentName' => AzureSqlValueMapper::stringValue($segmentRow['segmentName'] ?? ''),
                    'segmentOrder' => AzureSqlValueMapper::intValue($segmentRow['segmentOrder'] ?? 0),
                    'segmentTypeCode' => AzureSqlValueMapper::stringValue($segmentRow['segmentTypeCode'] ?? ''),
                    'literalValue' => AzureSqlValueMapper::nullableString($segmentRow['literalValue'] ?? null),
                    'isRequired' => AzureSqlValueMapper::boolValue($segmentRow['isRequired'] ?? true),
                    'separatorAfter' => AzureSqlValueMapper::nullableString($segmentRow['separatorAfter'] ?? null),
                ];
            }
        }

        return [
            'publicId' => AzureSqlValueMapper::stringValue($row['publicId'] ?? ''),
            'ruleSetCode' => AzureSqlValueMapper::stringValue($row['ruleSetCode'] ?? ''),
            'ruleSetName' => AzureSqlValueMapper::stringValue($row['ruleSetName'] ?? ''),
            'segments' => $segments,
        ];
    }

    public function assignPrimaryCode(
        ProductPublicId $productPublicId,
        ProductCodeValue $code,
        ?string $explanation,
        ?string $ruleSetPublicId,
        UserId $actorUserId,
    ): void {
        $this->store->clearPrimaryCodes($productPublicId->toString());
        $this->store->addProductCode([
            'productPublicId' => $productPublicId->toString(),
            'code' => $code->toString(),
            'explanation' => $explanation,
            'ruleSetPublicId' => $ruleSetPublicId,
            'isPrimary' => true,
        ]);
    }
}
