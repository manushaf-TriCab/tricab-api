<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PublicProduct;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Throwable;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Product\Media\StableMediaUrlSigner;
use TriCab\Core\Product\Contract\ProductBlobStore;

/**
 * Redirects a stable product image address to a freshly signed blob read URL.
 */
final class GetStableMediaImageAction
{
    public function __construct(
        private readonly StableMediaUrlSigner $stableUrls,
        private readonly ProductBlobStore $blobs,
        private readonly Settings $settings,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        $blob = $this->stableUrls->verify(RouteParams::require($request, 'token'));
        if (
            $blob === null
            || $blob['container'] !== $this->settings->blobContainerProducts
            || !$this->stableUrls->isImagePath($blob['path'])
        ) {
            return $this->notFound();
        }

        try {
            $ticket = $this->blobs->issueReadDownload(
                $blob['container'],
                $blob['path'],
                $this->settings->blobDownloadSasTtlSeconds,
            );
        } catch (Throwable) {
            return $this->responder->error('MEDIA_UNAVAILABLE', 'The image is temporarily unavailable.', 503)
                ->withHeader('Cache-Control', 'no-store');
        }

        return (new Response(302))
            ->withHeader('Location', $ticket->uploadUrl)
            ->withHeader('Cache-Control', $this->stableUrls->redirectCacheControl())
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    private function notFound(): ResponseInterface
    {
        return $this->responder->error('MEDIA_NOT_FOUND', 'The image was not found.', 404)
            ->withHeader('Cache-Control', 'no-store');
    }
}
