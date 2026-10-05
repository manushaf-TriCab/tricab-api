<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Parser;

use DateTimeImmutable;
use JsonException;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Storage\PythonJson;

/**
 * An accessory the engine proposed, held by the browser until "Add selected"
 * (parity plan E7.2).
 *
 * In the source the candidate rows live in the browser and are spliced into the
 * quote as sent, so a browser could add any accessory at any price. Here the
 * candidate row is sealed as {@see ParseSeal} seals a parse: signed with HMAC
 * under a key derived from the parser's internal token (with a purpose of its
 * own, so one kind of seal never opens as the other), tied to the person by a
 * keyed hash of their email, and good for {@see MAX_AGE_SECONDS}. The browser
 * names the row it wants by its token and says how many units; the row, its
 * parent product and its pack size are the server's.
 */
final class AccessorySeal
{
    public const VERSION = 'rfq.accessory-seal.v1';

    public const MAX_AGE_SECONDS = 12 * 3600;

    public const MAX_TOKEN_BYTES = 64_000;

    private readonly string $key;

    public function __construct(string $parserToken)
    {
        if ($parserToken === '') {
            throw new \InvalidArgumentException('An accessory seal needs the parser token.');
        }
        $this->key = hash_hmac('sha256', self::VERSION, $parserToken, true);
    }

    /** @param array<string, mixed> $row the candidate row, without a `line_uid` */
    public function seal(string $email, DateTimeImmutable $issuedAt, array $row): string
    {
        $body = PythonJson::encode(['v' => self::VERSION, 'who' => $this->who($email), 'issued' => $issuedAt->getTimestamp(), 'row' => $row]);
        $packed = self::base64($body);

        return $packed . '.' . self::base64(hash_hmac('sha256', $packed, $this->key, true));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidRfqValue for a token that is not this server's, not this person's, or too old
     */
    public function open(string $token, string $email, DateTimeImmutable $now): array
    {
        $parts = explode('.', $token);
        if (strlen($token) > self::MAX_TOKEN_BYTES || count($parts) !== 2) {
            throw self::refused();
        }
        [$packed, $signature] = $parts;
        if (!hash_equals(self::base64(hash_hmac('sha256', $packed, $this->key, true)), $signature)) {
            throw self::refused();
        }
        $json = self::unbase64($packed);
        if ($json === null) {
            throw self::refused();
        }
        try {
            $body = PythonJson::decode($json);
        } catch (JsonException) {
            throw self::refused();
        }
        if (!is_array($body) || ($body['v'] ?? null) !== self::VERSION || !is_int($body['issued'] ?? null)
            || !is_array($body['row'] ?? null) || ($body['row'] !== [] && array_is_list($body['row']))) {
            throw self::refused();
        }
        if (!is_string($body['who'] ?? null) || !hash_equals($body['who'], $this->who($email))) {
            throw new InvalidRfqValue('This accessory was matched for someone else. Match accessories again.');
        }
        $age = $now->getTimestamp() - $body['issued'];
        if ($age < -300 || $age > self::MAX_AGE_SECONDS) {
            throw new InvalidRfqValue('This accessory match is more than 12 hours old. Match accessories again.');
        }

        /** @var array<string, mixed> $row */
        $row = $body['row'];

        return $row;
    }

    private function who(string $email): string
    {
        return self::base64(hash_hmac('sha256', 'who:' . strtolower(trim($email)), $this->key, true));
    }

    private static function refused(): InvalidRfqValue
    {
        return new InvalidRfqValue('This accessory could not be read back. Match accessories again, then add it.');
    }

    private static function base64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function unbase64(string $text): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/', $text) !== 1) {
            return null;
        }
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }
}
