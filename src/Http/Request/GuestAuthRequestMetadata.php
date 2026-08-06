<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Captures client IP and User-Agent using the server remote address only.
 * Does not trust X-Forwarded-For without validated proxy configuration.
 */
final class GuestAuthRequestMetadata
{
    public const MAX_IP_LENGTH = 45;
    public const MAX_USER_AGENT_LENGTH = 1000;

    public static function clientIp(ServerRequestInterface $request): ?string
    {
        $params = $request->getServerParams();
        $remote = $params['REMOTE_ADDR'] ?? null;
        if (!is_string($remote)) {
            return null;
        }

        $trimmed = trim($remote);
        if ($trimmed === '') {
            return null;
        }

        return substr($trimmed, 0, self::MAX_IP_LENGTH);
    }

    public static function userAgent(ServerRequestInterface $request): ?string
    {
        $header = trim($request->getHeaderLine('User-Agent'));
        if ($header === '') {
            return null;
        }

        return substr($header, 0, self::MAX_USER_AGENT_LENGTH);
    }
}
