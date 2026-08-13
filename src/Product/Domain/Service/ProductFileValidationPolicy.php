<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Service;

use TriCab\Core\Product\Domain\Exception\InvalidProductFileException;
use TriCab\Core\Product\Domain\ValueObject\ProductFileKind;

/**
 * Phase 9 business allowlists for product file uploads.
 * Validates extension, MIME, size; sanitizes original filename; normalizes extension.
 */
final class ProductFileValidationPolicy
{
    private const IMAGE_MAX_BYTES = 20 * 1024 * 1024;
    private const DOCUMENT_MAX_BYTES = 50 * 1024 * 1024;
    private const CAD_MAX_BYTES = 200 * 1024 * 1024;
    private const AR_MAX_BYTES = 200 * 1024 * 1024;
    private const MODEL_3D_MAX_BYTES = 200 * 1024 * 1024;
    private const FRAME_360_MAX_BYTES = 20 * 1024 * 1024;

    /** @var list<string> */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** @var list<string> */
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /** @var array<string, list<string>> extension => allowed mimes */
    private const IMAGE_EXT_MIME = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
    ];

    /** @var list<string> */
    private const DOCUMENT_EXTENSIONS = ['pdf'];

    /** @var list<string> */
    private const DOCUMENT_MIMES = ['application/pdf'];

    /** @var array<string, list<string>> */
    private const DOCUMENT_EXT_MIME = [
        'pdf' => ['application/pdf'],
    ];

    /** @var list<string> */
    private const CAD_EXTENSIONS = ['dwg', 'dxf', 'step', 'stp', 'iges', 'igs'];

    /** @var list<string> */
    private const CAD_MIMES = [
        'application/octet-stream',
        'application/acad',
        'application/x-acad',
        'application/autocad_dwg',
        'image/vnd.dwg',
        'image/x-dwg',
        'application/dxf',
        'image/vnd.dxf',
        'application/step',
        'model/step',
        'application/iges',
        'model/iges',
    ];

    /** @var array<string, list<string>> */
    private const CAD_EXT_MIME = [
        'dwg' => ['application/octet-stream', 'application/acad', 'application/x-acad', 'application/autocad_dwg', 'image/vnd.dwg', 'image/x-dwg'],
        'dxf' => ['application/octet-stream', 'application/dxf', 'image/vnd.dxf'],
        'step' => ['application/octet-stream', 'application/step', 'model/step'],
        'stp' => ['application/octet-stream', 'application/step', 'model/step'],
        'iges' => ['application/octet-stream', 'application/iges', 'model/iges'],
        'igs' => ['application/octet-stream', 'application/iges', 'model/iges'],
    ];

    /** @var list<string> */
    private const AR_EXTENSIONS = ['glb', 'usdz'];

    /** @var list<string> */
    private const AR_MIMES = [
        'model/gltf-binary',
        'model/vnd.usdz+zip',
        'application/octet-stream',
    ];

    /** @var array<string, list<string>> */
    private const AR_EXT_MIME = [
        'glb' => ['model/gltf-binary', 'application/octet-stream'],
        'usdz' => ['model/vnd.usdz+zip', 'application/octet-stream'],
    ];

    /**
     * Phase 1: GLB-only (self-contained). Multi-file GLTF packages are deferred.
     *
     * @var list<string>
     */
    private const MODEL_3D_EXTENSIONS = ['glb'];

    /** @var list<string> */
    private const MODEL_3D_MIMES = [
        'model/gltf-binary',
        'application/octet-stream',
    ];

    /** @var array<string, list<string>> */
    private const MODEL_3D_EXT_MIME = [
        'glb' => ['model/gltf-binary', 'application/octet-stream'],
    ];

    /**
     * @return array{safeFileName: string, extension: string, contentType: string, byteSize: int, fileKind: string}
     */
    public function validateImage(string $originalFileName, string $contentType, int $byteSize): array
    {
        return $this->validate(
            ProductFileKind::IMAGE,
            $originalFileName,
            $contentType,
            $byteSize,
            self::IMAGE_EXTENSIONS,
            self::IMAGE_MIMES,
            self::IMAGE_EXT_MIME,
            self::IMAGE_MAX_BYTES,
        );
    }

    /**
     * @return array{safeFileName: string, extension: string, contentType: string, byteSize: int, fileKind: string}
     */
    public function validateDocument(string $originalFileName, string $contentType, int $byteSize): array
    {
        return $this->validate(
            ProductFileKind::DOCUMENT,
            $originalFileName,
            $contentType,
            $byteSize,
            self::DOCUMENT_EXTENSIONS,
            self::DOCUMENT_MIMES,
            self::DOCUMENT_EXT_MIME,
            self::DOCUMENT_MAX_BYTES,
        );
    }

    /**
     * @return array{safeFileName: string, extension: string, contentType: string, byteSize: int, fileKind: string}
     */
    public function validateCad(string $originalFileName, string $contentType, int $byteSize): array
    {
        return $this->validate(
            ProductFileKind::CAD,
            $originalFileName,
            $contentType,
            $byteSize,
            self::CAD_EXTENSIONS,
            self::CAD_MIMES,
            self::CAD_EXT_MIME,
            self::CAD_MAX_BYTES,
        );
    }

    /**
     * @return array{safeFileName: string, extension: string, contentType: string, byteSize: int, fileKind: string}
     */
    public function validateAr(string $originalFileName, string $contentType, int $byteSize): array
    {
        return $this->validate(
            ProductFileKind::AR,
            $originalFileName,
            $contentType,
            $byteSize,
            self::AR_EXTENSIONS,
            self::AR_MIMES,
            self::AR_EXT_MIME,
            self::AR_MAX_BYTES,
        );
    }

    /**
     * @return array{safeFileName: string, extension: string, contentType: string, byteSize: int, fileKind: string}
     */
    public function validateModel3d(string $originalFileName, string $contentType, int $byteSize): array
    {
        return $this->validate(
            ProductFileKind::MODEL_3D,
            $originalFileName,
            $contentType,
            $byteSize,
            self::MODEL_3D_EXTENSIONS,
            self::MODEL_3D_MIMES,
            self::MODEL_3D_EXT_MIME,
            self::MODEL_3D_MAX_BYTES,
        );
    }

    /**
     * @return array{safeFileName: string, extension: string, contentType: string, byteSize: int, fileKind: string}
     */
    public function validateModel3dPreview(string $originalFileName, string $contentType, int $byteSize): array
    {
        return $this->validate(
            ProductFileKind::MODEL_3D_PREVIEW,
            $originalFileName,
            $contentType,
            $byteSize,
            self::IMAGE_EXTENSIONS,
            self::IMAGE_MIMES,
            self::IMAGE_EXT_MIME,
            self::IMAGE_MAX_BYTES,
        );
    }

    /**
     * @return array{safeFileName: string, extension: string, contentType: string, byteSize: int, fileKind: string}
     */
    public function validate360Frame(string $originalFileName, string $contentType, int $byteSize): array
    {
        return $this->validate(
            ProductFileKind::FRAME_360,
            $originalFileName,
            $contentType,
            $byteSize,
            self::IMAGE_EXTENSIONS,
            self::IMAGE_MIMES,
            self::IMAGE_EXT_MIME,
            self::FRAME_360_MAX_BYTES,
        );
    }

    /**
     * @param list<string> $allowedExtensions
     * @param list<string> $allowedMimes
     * @param array<string, list<string>> $extMimeMap
     * @return array{safeFileName: string, extension: string, contentType: string, byteSize: int, fileKind: string}
     */
    private function validate(
        string $fileKind,
        string $originalFileName,
        string $contentType,
        int $byteSize,
        array $allowedExtensions,
        array $allowedMimes,
        array $extMimeMap,
        int $maxBytes,
    ): array {
        if ($byteSize < 1) {
            throw InvalidProductFileException::for('Byte size must be at least 1.');
        }

        if ($byteSize > $maxBytes) {
            throw InvalidProductFileException::forSize($byteSize, $maxBytes, $fileKind);
        }

        $safeFileName = $this->sanitizeOriginalFileName($originalFileName);
        $extension = $this->normalizeExtension($safeFileName);

        if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
            throw InvalidProductFileException::forExtension($extension === '' ? '(none)' : $extension, $fileKind);
        }

        $normalizedMime = strtolower(trim($contentType));
        if ($normalizedMime === '' || !in_array($normalizedMime, $allowedMimes, true)) {
            throw InvalidProductFileException::forMimeType($contentType, $fileKind);
        }

        $mapped = $extMimeMap[$extension] ?? [];
        if ($mapped !== [] && !in_array($normalizedMime, $mapped, true)) {
            throw InvalidProductFileException::forIncompatible($extension, $normalizedMime);
        }

        return [
            'safeFileName' => $safeFileName,
            'extension' => $extension,
            'contentType' => $normalizedMime,
            'byteSize' => $byteSize,
            'fileKind' => $fileKind,
        ];
    }

    public function sanitizeOriginalFileName(string $originalFileName): string
    {
        $base = basename(str_replace(['\\', "\0"], ['/', ''], $originalFileName));
        $base = trim($base);

        if ($base === '' || $base === '.' || $base === '..') {
            throw InvalidProductFileException::for('Original file name is empty or invalid after sanitization.');
        }

        // Strip path remnants and control characters; keep a conservative filename set.
        $safe = preg_replace('/[^\w.\- ()[\]]+/u', '_', $base) ?? '';
        $safe = trim($safe, "._ \t\n\r\0\x0B");

        if ($safe === '') {
            throw InvalidProductFileException::for('Original file name is empty or invalid after sanitization.');
        }

        return $safe;
    }

    public function normalizeExtension(string $fileNameOrExtension): string
    {
        $value = strtolower(trim($fileNameOrExtension));
        if ($value === '') {
            return '';
        }

        if (str_contains($value, '/')) {
            $value = basename($value);
        }

        if (str_contains($value, '.')) {
            $value = substr($value, (int) strrpos($value, '.') + 1);
        }

        return ltrim($value, '.');
    }
}
