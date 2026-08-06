<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Exception\HttpRequestValidationFailed;

/**
 * Reads required Slim route placeholders (attached as request attributes)
 * with a safe validation failure when missing.
 */
final class RouteParams
{
    public static function require(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getAttribute($name);
        if (!is_string($value) || trim($value) === '') {
            throw HttpRequestValidationFailed::invalid(sprintf('"%s" route parameter is required.', $name));
        }

        return $value;
    }

    public static function optional(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getAttribute($name);
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
