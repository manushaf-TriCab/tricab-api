<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Response;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

final class JsonResponder
{
    /**
     * @param array<string, mixed> $data
     */
    public function success(array $data, int $status = 200): ResponseInterface
    {
        return $this->write([
            'success' => true,
            'data' => $data,
        ], $status);
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
     */
    private function write(array $payload, int $status): ResponseInterface
    {
        $response = new Response($status);
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $response->getBody()->write($json);

        return $response->withHeader('Content-Type', 'application/json');
    }
}
