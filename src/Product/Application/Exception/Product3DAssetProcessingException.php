<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

/**
 * Product 3D asset analyse / optimise / validate failures.
 * Error codes map 1:1 to API responses.
 */
final class Product3DAssetProcessingException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }

    public static function analysisFailed(string $message): self
    {
        return new self('PRODUCT_3D_ANALYSIS_FAILED', $message);
    }

    public static function invalidGlb(string $message): self
    {
        return new self('PRODUCT_3D_INVALID_GLB', $message);
    }

    public static function optimisationFailed(string $message): self
    {
        return new self('PRODUCT_3D_OPTIMISATION_FAILED', $message);
    }

    public static function optimisedAssetInvalid(string $message): self
    {
        return new self('PRODUCT_3D_OPTIMISED_ASSET_INVALID', $message);
    }

    public static function scaleMismatch(string $message): self
    {
        return new self('PRODUCT_3D_SCALE_MISMATCH', $message);
    }

    public static function assetNotReady(string $message): self
    {
        return new self('PRODUCT_3D_ASSET_NOT_READY', $message, 409);
    }

    public static function fromToolError(array $error): self
    {
        $code = isset($error['code']) && is_string($error['code']) && $error['code'] !== ''
            ? $error['code']
            : 'PRODUCT_3D_OPTIMISATION_FAILED';
        $message = isset($error['message']) && is_string($error['message'])
            ? $error['message']
            : 'Product 3D processing failed.';

        return match ($code) {
            'PRODUCT_3D_ANALYSIS_FAILED' => self::analysisFailed($message),
            'PRODUCT_3D_INVALID_GLB' => self::invalidGlb($message),
            'PRODUCT_3D_OPTIMISED_ASSET_INVALID' => self::optimisedAssetInvalid($message),
            'PRODUCT_3D_SCALE_MISMATCH' => self::scaleMismatch($message),
            'PRODUCT_3D_ASSET_NOT_READY' => self::assetNotReady($message),
            default => self::optimisationFailed($message),
        };
    }
}
