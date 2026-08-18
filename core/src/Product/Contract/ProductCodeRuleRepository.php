<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\ValueObject\ProductCodeValue;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * Future Azure SQL: ProductCodeRuleSets / ProductCodeRuleSegments / ProductCodes
 *
 * @phpstan-type RuleSet array{
 *   publicId: string,
 *   ruleSetCode: string,
 *   ruleSetName: string,
 *   segments: list<array{
 *     segmentCode: string,
 *     segmentName: string,
 *     segmentOrder: int,
 *     segmentTypeCode: string,
 *     literalValue: ?string,
 *     isRequired: bool,
 *     separatorAfter: ?string
 *   }>
 * }
 * @phpstan-type CodePreview array{code: string, explanation: string}
 */
interface ProductCodeRuleRepository
{
    /**
     * @return list<RuleSet>
     */
    public function listRuleSets(bool $activeOnly = true): array;

    /**
     * @param array<string, string> $segmentValues keyed by segmentCode
     * @return CodePreview
     */
    public function preview(
        string $ruleSetPublicId,
        array $segmentValues,
    ): array;

    public function assignPrimaryCode(
        ProductPublicId $productPublicId,
        ProductCodeValue $code,
        ?string $explanation,
        ?string $ruleSetPublicId,
        UserId $actorUserId,
    ): void;
}
