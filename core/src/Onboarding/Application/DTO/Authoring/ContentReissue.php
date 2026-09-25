<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Authoring;

/**
 * One person moved onto a newly published version, because its author said it
 * supersedes work in progress: their old assignment withdrawn, a new one made.
 */
final readonly class ContentReissue
{
    public function __construct(
        public int $userId,
        public string $fromAssignmentPublicId,
        public string $toAssignmentPublicId,
        public string $fromStatus,
    ) {
    }
}
