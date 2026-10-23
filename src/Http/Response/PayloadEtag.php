<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Response;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Strong ETag over a response payload exactly as sent.
 *
 * Hash the presented payload (after image URLs are signed or stabilised), never a cached
 * pre-signing copy: a 304 then only confirms a client copy whose signatures are still current.
 */
final class PayloadEtag
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function for(array $payload): string
    {
        return '"' . hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) . '"';
    }

    public static function matches(ServerRequestInterface $request, string $etag): bool
    {
        $header = trim($request->getHeaderLine('If-None-Match'));
        if ($header === '') {
            return false;
        }
        foreach (explode(',', $header) as $part) {
            $candidate = trim($part);
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }
            if ($candidate === $etag || $candidate === '*') {
                return true;
            }
        }

        return false;
    }
}
