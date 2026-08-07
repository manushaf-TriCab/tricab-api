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
use TriCab\Core\Product\Domain\ValueObject\ScanStatusCode;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;

/**
 * Development-only binary delivery for BLOB_AUTH_MODE=memory.
 * Looks up by file public id only — never accepts raw storage paths.
 */
final class DevProductFileDeliveryAction
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ProductMediaRepository $media,
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
        $record = $this->media->findByPublicId($filePublicId);
        if ($record === null) {
            return $this->responder->error('NOT_FOUND', 'Not found.', 404);
        }

        $upload = strtolower($record->uploadStatusCode);
        $scan = strtolower($record->scanStatusCode);
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

        $container = $record->storageContainer;
        $path = $record->storagePath;
        if ($container === null || $container === '' || $path === null || $path === '') {
            return $this->responder->error('NOT_FOUND', 'Not found.', 404);
        }

        $object = $this->blobStore->readObject($container, $path);
        if ($object === null) {
            return $this->responder->error('NOT_FOUND', 'Not found.', 404);
        }

        $response->getBody()->write($object['binary']);

        return $response
            ->withHeader('Content-Type', $object['contentType'])
            ->withHeader('Cache-Control', 'private, max-age=60')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withStatus(200);
    }
}
