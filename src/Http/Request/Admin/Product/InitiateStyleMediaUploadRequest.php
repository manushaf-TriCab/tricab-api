<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

final readonly class InitiateStyleMediaUploadRequest
{
    /**
     * @param array<string, mixed> $metadata
     */
    private function __construct(
        public string $resourceType,
        public string $originalFileName,
        public string $contentType,
        public int $byteSize,
        public string $title,
        public ?string $roleCode,
        public ?string $altText,
        public int $displayOrder,
        public bool $isPrimary,
        public array $metadata,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $byteSize = RequestFields::intOrNull($body, 'byteSize');
        if ($byteSize === null) {
            throw \TriCab\Api\Http\Exception\HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'byteSize is required.');
        }

        $metadata = [];
        if (isset($body['metadata']) && is_array($body['metadata'])) {
            foreach ($body['metadata'] as $key => $value) {
                if (is_string($key)) {
                    $metadata[$key] = $value;
                }
            }
        }

        return new self(
            resourceType: RequestFields::requireString($body, 'resourceType'),
            originalFileName: RequestFields::requireString($body, 'originalFileName'),
            contentType: RequestFields::requireString($body, 'contentType'),
            byteSize: $byteSize,
            title: RequestFields::stringOrDefault($body, 'title', ''),
            roleCode: RequestFields::stringOrNull($body, 'roleCode') ?? RequestFields::stringOrNull($body, 'mediaRole'),
            altText: RequestFields::stringOrNull($body, 'altText'),
            displayOrder: RequestFields::intOrNull($body, 'displayOrder') ?? 0,
            isPrimary: RequestFields::boolOrDefault($body, 'isPrimary', false),
            metadata: $metadata,
        );
    }
}
