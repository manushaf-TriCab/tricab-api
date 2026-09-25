<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Admin;

/**
 * One thing HR can allocate today: an active module, policy or form, and the
 * single published, live version of it that a new allocation would point at.
 *
 * HR chooses the *item* and the version is resolved here, as the source does
 * for policies. Asking HR to pick a version number would put a choice in front
 * of them that has exactly one right answer.
 */
final readonly class AllocatableItem
{
    public function __construct(
        public string $itemType,
        public string $itemPublicId,
        public string $title,
        public int $versionId,
        public string $versionPublicId,
        public int $versionNumber,
    ) {
    }

    public function key(): string
    {
        return $this->itemType . ':' . strtolower($this->itemPublicId);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'itemType' => $this->itemType,
            'itemPublicId' => $this->itemPublicId,
            'title' => $this->title,
            'versionPublicId' => $this->versionPublicId,
            'versionNumber' => $this->versionNumber,
        ];
    }
}
