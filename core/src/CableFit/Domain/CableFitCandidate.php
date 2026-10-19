<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Domain;

use DateTimeImmutable;

/**
 * Row of `[cablefit].candidates`. The version is the optimistic-concurrency
 * guard and always equals the latest revision number.
 */
final readonly class CableFitCandidate
{
    public function __construct(
        public string $publicId,
        public ?string $nprRequestPublicId,
        public ?string $nprVariantId,
        public string $name,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public int $createdByUserId,
        public int $updatedByUserId,
    ) {
    }

    public function withNewVersion(int $version, DateTimeImmutable $at, int $userId): self
    {
        return new self(
            $this->publicId,
            $this->nprRequestPublicId,
            $this->nprVariantId,
            $this->name,
            $version,
            $this->createdAt,
            $at,
            $this->createdByUserId,
            $userId,
        );
    }
}
