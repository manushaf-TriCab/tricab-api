<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Entity;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\DisplayOrder;
use TriCab\Core\Product\Domain\ValueObject\ProductCategoryId;
use TriCab\Core\Product\Domain\ValueObject\ProductSeriesId;
use TriCab\Core\Product\Domain\ValueObject\ProductSeriesPublicId;

final class ProductSeries
{
    private readonly string $seriesCode;
    private readonly string $seriesName;

    public function __construct(
        private readonly ?ProductSeriesId $id,
        private readonly ProductSeriesPublicId $publicId,
        string $seriesCode,
        string $seriesName,
        private readonly DisplayOrder $displayOrder,
        private readonly ?ProductCategoryId $categoryId = null,
        private readonly ?string $description = null,
        private readonly bool $isActive = true,
    ) {
        $code = trim($seriesCode);
        $name = trim($seriesName);
        if ($code === '') {
            throw InvalidProductValue::for('SeriesCode', $seriesCode);
        }
        if ($name === '') {
            throw InvalidProductValue::for('SeriesName', $seriesName);
        }
        $this->seriesCode = $code;
        $this->seriesName = $name;
    }

    public function id(): ?ProductSeriesId
    {
        return $this->id;
    }

    public function publicId(): ProductSeriesPublicId
    {
        return $this->publicId;
    }

    public function seriesCode(): string
    {
        return $this->seriesCode;
    }

    public function seriesName(): string
    {
        return $this->seriesName;
    }

    public function displayOrder(): DisplayOrder
    {
        return $this->displayOrder;
    }

    public function categoryId(): ?ProductCategoryId
    {
        return $this->categoryId;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }
}
