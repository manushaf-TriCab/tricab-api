<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * ANALYSE_GLB / OPTIMISE_GLB / VALIDATE_GLB job (not generation).
 *
 * @phpstan-type JsonMap array<string, mixed>
 */
final readonly class Product3DAssetProcessingJobSummary
{
    /**
     * @param JsonMap $input
     * @param JsonMap|null $output
     */
    public function __construct(
        public string $publicId,
        public string $productPublicId,
        public string $modelPublicId,
        public ?string $assetPublicId,
        public string $operationTypeCode,
        public ?string $profileCode,
        public string $statusCode,
        public array $input,
        public ?array $output,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?string $startedAtUtc,
        public ?string $completedAtUtc,
        public string $rowVersion,
        public string $createdAtUtc,
    ) {
    }
}
