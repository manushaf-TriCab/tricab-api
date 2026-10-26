<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap2;

use Psr\Http\Message\ResponseInterface;
use TriCab\Core\Finance\Ap2\Service\Ap2File;

/**
 * A stored file as a response. The type came from the sender, so only a PDF or a common image is shown in the page
 * (`inline`); anything else is a plain download. Scripts never run from it (CSP sandbox, no sniffing).
 */
trait Ap2FileResponse
{
    private function fileResponse(ResponseInterface $response, Ap2File $file): ResponseInterface
    {
        $type = strtolower(trim(explode(';', $file->contentType)[0]));
        $shown = in_array($type, ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/tiff'], true);
        $name = str_replace(['"', '\\', "\r", "\n"], '_', $file->name);
        $response->getBody()->write($file->bytes);

        return $response
            ->withHeader('Content-Type', $shown ? $type : 'application/octet-stream')
            ->withHeader('Content-Disposition', ($shown ? 'inline' : 'attachment') . '; filename="' . $name . '"')
            ->withHeader('Content-Security-Policy', 'sandbox; default-src \'none\'')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'no-store, private');
    }
}
