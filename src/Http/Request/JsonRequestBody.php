<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request;

use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Exception\HttpRequestValidationFailed;

/**
 * Safely reads application/json request bodies for guest auth endpoints.
 */
final class JsonRequestBody
{
    /**
     * @return array<string, mixed>
     */
    public static function requireObject(ServerRequestInterface $request): array
    {
        $contentType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
        if ($contentType !== 'application/json') {
            throw HttpRequestValidationFailed::invalid('Content-Type must be application/json.');
        }

        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $raw = (string) $body;
        if (trim($raw) === '') {
            throw HttpRequestValidationFailed::invalid('Request body is required.');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw HttpRequestValidationFailed::invalid('Request body must be valid JSON.');
        }

        // json_decode('{}') yields [] in PHP; treat only non-empty lists as JSON arrays.
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw HttpRequestValidationFailed::invalid('Request body must be a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
