<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

final readonly class UpsertCatalogueNodeRequest
{
    /** @param list<string>|null $regionCodes */
    private function __construct(
        public string $tabPublicId,
        public string $nodeName,
        public string $nodeCode,
        public string $slug,
        public ?string $parentPublicId,
        public ?string $description,
        public string $nodeTypeCode,
        public ?string $imageUrl,
        public ?string $imageAltText,
        public ?string $imageMediaPublicId,
        public int $displayOrder,
        public bool $isActive,
        public bool $isPublic,
        public ?string $seoTitle,
        public ?string $seoDescription,
        public ?array $regionCodes,
        public ?string $expectedRowVersion,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        $regionCodes = null;
        if (array_key_exists('regionCodes', $body)) {
            $raw = $body['regionCodes'];
            if ($raw === null) {
                $regionCodes = null;
            } elseif (is_array($raw)) {
                $regionCodes = [];
                foreach ($raw as $code) {
                    if (is_string($code) && trim($code) !== '') {
                        $regionCodes[] = $code;
                    }
                }
            }
        }

        return new self(
            tabPublicId: RequestFields::requireString($body, 'tabPublicId'),
            nodeName: RequestFields::requireString($body, 'nodeName'),
            nodeCode: RequestFields::requireString($body, 'nodeCode'),
            slug: RequestFields::requireString($body, 'slug'),
            parentPublicId: RequestFields::stringOrNull($body, 'parentPublicId'),
            description: RequestFields::stringOrNull($body, 'description'),
            nodeTypeCode: RequestFields::stringOrNull($body, 'nodeTypeCode') ?? 'category',
            imageUrl: RequestFields::stringOrNull($body, 'imageUrl'),
            imageAltText: RequestFields::stringOrNull($body, 'imageAltText'),
            imageMediaPublicId: RequestFields::stringOrNull($body, 'imageMediaPublicId'),
            displayOrder: RequestFields::intOrNull($body, 'displayOrder') ?? 0,
            isActive: RequestFields::boolOrDefault($body, 'isActive', true),
            isPublic: RequestFields::boolOrDefault($body, 'isPublic', true),
            seoTitle: RequestFields::stringOrNull($body, 'seoTitle'),
            seoDescription: RequestFields::stringOrNull($body, 'seoDescription'),
            regionCodes: $regionCodes,
            expectedRowVersion: RequestFields::stringOrNull($body, 'expectedRowVersion'),
        );
    }
}
