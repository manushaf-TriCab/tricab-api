<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Http;

/** A client that can POST a body that is not JSON or a form (Graph's MIME `sendMail` takes base64 text). */
interface RawHttpClient
{
    /**
     * @param array<string, string> $headers including the Content-Type
     * @return array{status: int, body: string, headers?: array<string, string>}
     */
    public function postRaw(string $url, array $headers, string $body): array;
}
