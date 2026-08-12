<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Dev;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Product\Storage\InMemoryProductBlobStore;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Contract\ProductMediaRepository;
use TriCab\Core\Product\Contract\Product3DModelRepository;
use TriCab\Core\Product\Domain\ValueObject\ScanStatusCode;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;

/**
 * Development-only binary delivery for BLOB_AUTH_MODE=memory.
 * Looks up by file public id only — never accepts raw storage paths.
 * Supports Product Media and Product 3D model entities.
 */
final class DevProductFileDeliveryAction
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ProductMediaRepository $media,
        private readonly Product3DModelRepository $threeDModels,
        private readonly ProductBlobStore $blobStore,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->settings->isDevMemoryProductFileDeliveryEnabled()) {
            return $this->responder->error('NOT_FOUND', 'Not found.', 404);
        }

        if (!$this->blobStore instanceof InMemoryProductBlobStore) {
            return $this->responder->error('NOT_FOUND', 'Not found.', 404);
        }

        $filePublicId = RouteParams::require($request, 'filePublicId');
        $container = null;
        $path = null;
        $contentTypeHint = null;
        $upload = '';
        $scan = '';

        $mediaRecord = $this->media->findByPublicId($filePublicId);
        if ($mediaRecord !== null) {
            $upload = strtolower($mediaRecord->uploadStatusCode);
            $scan = strtolower($mediaRecord->scanStatusCode);
            $container = $mediaRecord->storageContainer;
            $path = $mediaRecord->storagePath;
            $contentTypeHint = $mediaRecord->contentType;
        } else {
            $model = $this->threeDModels->findByPublicId($filePublicId);
            if ($model === null) {
                return $this->responder->error('NOT_FOUND', 'Not found.', 404);
            }
            $upload = strtolower($model->uploadStatusCode);
            $scan = strtolower($model->scanStatusCode);
            $container = $model->storageContainer;
            $path = $model->storagePath;
            $contentTypeHint = $model->mimeType;
        }

        if (
            $upload === UploadStatusCode::DELETED
            || $upload === UploadStatusCode::PENDING_UPLOAD
            || $upload === UploadStatusCode::FAILED
            || $scan === ScanStatusCode::REJECTED
            || $scan === ScanStatusCode::FAILED
            || $scan === ScanStatusCode::PENDING_UPLOAD
        ) {
            return $this->responder->error('NOT_FOUND', 'Not found.', 404);
        }

        if ($container === null || $container === '' || $path === null || $path === '') {
            return $this->responder->error('NOT_FOUND', 'Not found.', 404);
        }

        $object = $this->blobStore->readObject($container, $path);
        if ($object === null) {
            return $this->responder->error('NOT_FOUND', 'Not found.', 404);
        }

        $response->getBody()->write($object['binary']);

        return $response
            ->withHeader('Content-Type', $object['contentType'] !== '' ? $object['contentType'] : ($contentTypeHint ?? 'application/octet-stream'))
            ->withHeader('Cache-Control', 'private, max-age=60')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withStatus(200);
    }
}
