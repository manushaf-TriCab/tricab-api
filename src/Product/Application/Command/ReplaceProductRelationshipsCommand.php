<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

/**
 * @phpstan-type RelationshipItemInput array{
 *   relatedProductPublicId: string,
 *   displayOrder?: int,
 *   accessoryRequirementCode?: ?string,
 *   notes?: ?string
 * }
 */
final readonly class ReplaceProductRelationshipsCommand
{
    /**
     * @param list<RelationshipItemInput> $items
     */
    public function __construct(
        public string $productPublicId,
        public string $relationshipType,
        public array $items,
    ) {
    }
}
