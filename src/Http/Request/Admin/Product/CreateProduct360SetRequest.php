<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Request\Admin\RequestFields;

final readonly class CreateProduct360SetRequest
{
    private function __construct(
        public string $title,
        public int $expectedFrameCount,
        public int $displayOrder,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $expected = RequestFields::intOrNull($body, 'expectedFrameCount');
        if ($expected === null) {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'expectedFrameCount is required.');
        }

        return new self(
            title: RequestFields::requireString($body, 'title'),
            expectedFrameCount: $expected,
            displayOrder: RequestFields::intOrNull($body, 'displayOrder') ?? 0,
        );
    }
}
