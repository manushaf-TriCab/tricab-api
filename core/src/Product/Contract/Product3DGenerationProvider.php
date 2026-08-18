<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

/**
 * Isolates 3D generation providers (procedural, img2threejs, future Azure workers).
 * Domain code must not depend on provider-specific APIs.
 */
interface Product3DGenerationProvider
{
    public function providerCode(): string;

    /**
     * @param array<string, mixed> $input
     * @return array{accepted: bool, status: string, output?: array<string, mixed>, errorCode?: string, errorMessage?: string}
     */
    public function enqueue(string $generationMode, array $input): array;

    /**
     * @return array{status: string, output?: array<string, mixed>, errorCode?: string, errorMessage?: string}
     */
    public function status(string $providerJobId): array;

    public function cancel(string $providerJobId): void;
}
