<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Response;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

final class JsonResponder
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function success(array $data, int $status = 200, array $headers = []): ResponseInterface
    {
        return $this->write([
            'success' => true,
            'data' => $data,
        ], $status, $headers);
    }

    public function notModified(
        string $etag,
        string $cacheControl = 'public, max-age=60, stale-while-revalidate=120',
    ): ResponseInterface {
        $response = new Response(304);

        return $response->withHeader('ETag', $etag)->withHeader('Cache-Control', $cacheControl);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function payload(array $payload, int $status = 200): ResponseInterface
    {
        return $this->write($payload, $status);
    }

    public function error(string $code, string $message, int $status): ResponseInterface
    {
        return $this->write([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    private function write(array $payload, int $status, array $headers = []): ResponseInterface
    {
        $response = new Response($status);
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $response->getBody()->write($json);

        $response = $response->withHeader('Content-Type', 'application/json');
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
