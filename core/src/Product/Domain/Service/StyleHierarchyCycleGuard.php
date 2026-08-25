<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Service;

use TriCab\Core\Product\Application\Exception\InvalidProductStyleHierarchy;

/**
 * Rejects self-parent and any ancestor cycle when assigning a parent style.
 * Ancestor public ids must be ordered from immediate parent toward the root.
 */
final class StyleHierarchyCycleGuard
{
    /**
     * @param list<string> $ancestorPublicIdsFromParentTowardRoot
     */
    public function assertCanSetParent(
        string $stylePublicId,
        ?string $parentPublicId,
        array $ancestorPublicIdsFromParentTowardRoot,
    ): void {
        if ($parentPublicId === null || $parentPublicId === '') {
            return;
        }

        if (strcasecmp($stylePublicId, $parentPublicId) === 0) {
            throw InvalidProductStyleHierarchy::selfParent($stylePublicId);
        }

        foreach ($ancestorPublicIdsFromParentTowardRoot as $ancestorPublicId) {
            if (strcasecmp($stylePublicId, $ancestorPublicId) === 0) {
                throw InvalidProductStyleHierarchy::cycle($stylePublicId, $parentPublicId);
            }
        }
    }
}
