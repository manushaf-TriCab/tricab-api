<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

/**
 * Opaque HMAC-signed staff pricing context (no DB session table required).
 * Client may hold the token; server always re-validates actor + permissions.
 *
 * @phpstan-type Payload array{
 *   v: int,
 *   actorUserPublicId: string,
 *   customerPublicId: string,
 *   overrideMargin: ?string,
 *   contactPublicId: ?string,
 *   exp: int
 * }
 */
final class StaffPricingContextTokenCodec
{
    private const VERSION = 1;
    private const TTL_SECONDS = 28800; // 8h staff working session

    public function __construct(
        private readonly string $signingSecret,
    ) {
    }

    public function issue(
        string $actorUserPublicId,
        string $customerPublicId,
        ?string $overrideMargin = null,
        ?string $contactPublicId = null,
    ): string {
        $payload = [
            'v' => self::VERSION,
            'actorUserPublicId' => $actorUserPublicId,
            'customerPublicId' => $customerPublicId,
            'overrideMargin' => $overrideMargin,
            'contactPublicId' => $contactPublicId !== null && $contactPublicId !== '' ? $contactPublicId : null,
            'exp' => time() + self::TTL_SECONDS,
        ];
        $body = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $sig = $this->sign($body);

        return $body . '.' . $sig;
    }

    /**
     * @return Payload
     */
    public function decode(string $token): array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException('Invalid pricing context token.');
        }
        [$body, $sig] = $parts;
        if (!hash_equals($this->sign($body), $sig)) {
            throw new \InvalidArgumentException('Invalid pricing context token signature.');
        }
        $pad = 4 - (strlen($body) % 4);
        if ($pad < 4) {
            $body .= str_repeat('=', $pad);
        }
        $json = base64_decode(strtr($body, '-_', '+/'), true);
        if ($json === false) {
            throw new \InvalidArgumentException('Invalid pricing context token body.');
        }
        /** @var Payload $payload */
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (($payload['v'] ?? 0) !== self::VERSION) {
            throw new \InvalidArgumentException('Unsupported pricing context token version.');
        }
        if (($payload['exp'] ?? 0) < time()) {
            throw new \InvalidArgumentException('Pricing context token expired.');
        }
        if (!is_string($payload['actorUserPublicId'] ?? null) || !is_string($payload['customerPublicId'] ?? null)) {
            throw new \InvalidArgumentException('Invalid pricing context token payload.');
        }

        return $payload;
    }

    private function sign(string $body): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $this->signingSecret, true)), '+/', '-_'), '=');
    }
}
