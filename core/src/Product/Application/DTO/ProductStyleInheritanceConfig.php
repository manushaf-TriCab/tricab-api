<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

use TriCab\Core\Product\Domain\ValueObject\StyleInheritanceMode;
use TriCab\Core\Product\Domain\ValueObject\StyleResourceType;

final readonly class ProductStyleInheritanceConfig
{
    public function __construct(
        public string $specifications,
        public string $images,
        public string $documents,
        public string $spin360,
        public string $models3d,
        public string $arAssets,
    ) {
    }

    public static function localAll(): self
    {
        $local = StyleInheritanceMode::LOCAL;

        return new self($local, $local, $local, $local, $local, $local);
    }

    public static function inheritAll(): self
    {
        $inherit = StyleInheritanceMode::INHERIT;

        return new self($inherit, $inherit, $inherit, $inherit, $inherit, $inherit);
    }

    /**
     * @param array<string, string> $modes
     */
    public static function fromMap(array $modes, self $defaults): self
    {
        return new self(
            self::normalize($modes['specifications'] ?? $modes[StyleResourceType::SPECIFICATIONS] ?? $defaults->specifications),
            self::normalize($modes['images'] ?? $modes[StyleResourceType::IMAGES] ?? $defaults->images),
            self::normalize($modes['documents'] ?? $modes[StyleResourceType::DOCUMENTS] ?? $defaults->documents),
            self::normalize($modes['spin360'] ?? $modes[StyleResourceType::SPIN_360] ?? $defaults->spin360),
            self::normalize($modes['models3d'] ?? $modes[StyleResourceType::MODELS_3D] ?? $defaults->models3d),
            self::normalize($modes['arAssets'] ?? $modes[StyleResourceType::AR_ASSETS] ?? $defaults->arAssets),
        );
    }

    public function modeFor(StyleResourceType $type): string
    {
        return match ($type->toString()) {
            StyleResourceType::SPECIFICATIONS => $this->specifications,
            StyleResourceType::IMAGES => $this->images,
            StyleResourceType::DOCUMENTS => $this->documents,
            StyleResourceType::SPIN_360 => $this->spin360,
            StyleResourceType::MODELS_3D => $this->models3d,
            default => $this->arAssets,
        };
    }

    /**
     * @return array<string, string>
     */
    public function toMap(): array
    {
        return [
            StyleResourceType::SPECIFICATIONS => $this->specifications,
            StyleResourceType::IMAGES => $this->images,
            StyleResourceType::DOCUMENTS => $this->documents,
            StyleResourceType::SPIN_360 => $this->spin360,
            StyleResourceType::MODELS_3D => $this->models3d,
            StyleResourceType::AR_ASSETS => $this->arAssets,
        ];
    }

    private static function normalize(string $mode): string
    {
        return StyleInheritanceMode::fromString($mode)->toString();
    }
}
