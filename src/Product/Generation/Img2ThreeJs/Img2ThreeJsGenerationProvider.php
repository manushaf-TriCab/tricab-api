<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Generation\Img2ThreeJs;

use TriCab\Core\Product\Contract\Product3DGenerationProvider;

/**
 * img2threejs provider boundary (Apache-2.0 upstream concepts).
 *
 * Do NOT iframe img2threejs.org.
 * Do NOT upload proprietary product images to public third parties without
 * an approved architecture decision.
 *
 * This stub keeps TriCab free of upstream coupling until a local/Azure worker
 * is deployed. See docs/product-3d-studio.md attribution section.
 */
final class Img2ThreeJsGenerationProvider implements Product3DGenerationProvider
{
    public function __construct(
        private readonly bool $enabled = false,
        private readonly ?string $workerEndpoint = null,
    ) {
    }

    public function providerCode(): string
    {
        return 'img2threejs';
    }

    public function enqueue(string $generationMode, array $input): array
    {
        unset($input['sas'], $input['token'], $input['password'], $input['authorization']);

        if ($generationMode !== 'image_generation') {
            return [
                'accepted' => false,
                'status' => 'failed',
                'errorCode' => 'UNSUPPORTED_MODE',
                'errorMessage' => 'img2threejs provider only accepts image_generation mode.',
            ];
        }

        if (!$this->enabled || $this->workerEndpoint === null || $this->workerEndpoint === '') {
            return [
                'accepted' => false,
                'status' => 'failed',
                'errorCode' => 'PROVIDER_NOT_CONFIGURED',
                'errorMessage' => 'Image-to-3D generation is not configured. Use GLB upload or procedural cable generation.',
            ];
        }

        return [
            'accepted' => false,
            'status' => 'failed',
            'errorCode' => 'PROVIDER_NOT_IMPLEMENTED',
            'errorMessage' => 'Image-to-3D worker integration is reserved for Phase 3.',
        ];
    }

    public function status(string $providerJobId): array
    {
        unset($providerJobId);

        return [
            'status' => 'failed',
            'errorCode' => 'PROVIDER_NOT_CONFIGURED',
            'errorMessage' => 'Image-to-3D generation is not configured.',
        ];
    }

    public function cancel(string $providerJobId): void
    {
        unset($providerJobId);
    }
}
