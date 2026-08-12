<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Generation;

use TriCab\Core\Product\Contract\Product3DGenerationProvider;

/**
 * Spec-driven cable generation is executed in the browser (Three.js) for Phase 2.
 * This provider records the request boundary for a future worker that exports GLB.
 * It does not call external services.
 */
final class ProceduralCable3DGenerationProvider implements Product3DGenerationProvider
{
    public function providerCode(): string
    {
        return 'procedural-cable';
    }

    public function enqueue(string $generationMode, array $input): array
    {
        if ($generationMode !== 'specification') {
            return [
                'accepted' => false,
                'status' => 'failed',
                'errorCode' => 'UNSUPPORTED_MODE',
                'errorMessage' => 'Procedural provider only accepts specification mode.',
            ];
        }

        // Sanitize: never persist secrets if callers accidentally pass them.
        unset($input['sas'], $input['token'], $input['password'], $input['authorization']);

        return [
            'accepted' => true,
            'status' => 'ready',
            'output' => [
                'provider' => $this->providerCode(),
                'renderMode' => 'client-procedural',
                'specSnapshot' => $input,
            ],
        ];
    }

    public function status(string $providerJobId): array
    {
        unset($providerJobId);

        return [
            'status' => 'ready',
            'output' => ['provider' => $this->providerCode()],
        ];
    }

    public function cancel(string $providerJobId): void
    {
        unset($providerJobId);
    }
}
