<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Generation;

use TriCab\Core\Product\Contract\Product3DGenerationProvider;

/**
 * Safe default: no external AI / worker. Image generation must not silently
 * upload TriCab product media to third parties.
 */
final class DisabledProduct3DGenerationProvider implements Product3DGenerationProvider
{
    public function providerCode(): string
    {
        return 'disabled';
    }

    public function enqueue(string $generationMode, array $input): array
    {
        unset($generationMode, $input);

        return [
            'accepted' => false,
            'status' => 'failed',
            'errorCode' => 'PROVIDER_DISABLED',
            'errorMessage' => '3D generation provider is not configured.',
        ];
    }

    public function status(string $providerJobId): array
    {
        unset($providerJobId);

        return [
            'status' => 'failed',
            'errorCode' => 'PROVIDER_DISABLED',
            'errorMessage' => '3D generation provider is not configured.',
        ];
    }

    public function cancel(string $providerJobId): void
    {
        unset($providerJobId);
    }
}
