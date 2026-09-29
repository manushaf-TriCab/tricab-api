<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Parser;

use DateTimeImmutable;
use JsonException;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Storage\PythonJson;

/**
 * A parse the browser holds until "Save as Draft" (parity plan D3).
 *
 * In the source, Match Text stores nothing: the rows live in the browser, and
 * "Save as Draft" posts them back whole, so the draft is whatever the browser
 * sent. Here the parse answer is sealed instead. The browser shows the lines
 * and returns the seal untouched with the header and its decisions, and the
 * create opens it: the rows a draft starts from are the parser's answer, byte
 * for byte, and nothing the browser wrote can pass for them.
 *
 * The seal is the parse encoded as the store encodes rows (`PythonJson`), so
 * a float the parser wrote is the float the store writes. It is deflated,
 * then signed with HMAC-SHA256 under a key derived from the parser's internal
 * token, which is configured wherever a parse can run. It is signed, not
 * encrypted, so it names the person it was made for by a keyed hash of their
 * email rather than the address (invariant 11). It lasts {@see MAX_AGE_SECONDS}: a match left open overnight
 * is matched again rather than saved against yesterday's catalogue.
 */
final class ParseSeal
{
    public const VERSION = 'rfq.parse-seal.v1';

    public const MAX_AGE_SECONDS = 12 * 3600;

    /** A deflated, base64 parse of `MAX_LINES_PER_REVISION` rows is far below this. */
    public const MAX_TOKEN_BYTES = 8_000_000;

    private readonly string $key;

    public function __construct(string $parserToken)
    {
        if ($parserToken === '') {
            throw new \InvalidArgumentException('A parse seal needs the parser token.');
        }
        $this->key = hash_hmac('sha256', self::VERSION, $parserToken, true);
    }

    /**
     * @param list<array<string, mixed>> $rows the parser's lines, each with its `line_uid`
     * @param array<string, mixed>       $parse what produced them, as the draft's `parse` meta records it
     */
    public function seal(string $email, DateTimeImmutable $issuedAt, array $rows, array $parse): string
    {
        $body = PythonJson::encode([
            'v' => self::VERSION,
            'who' => $this->who($email),
            'issued' => $issuedAt->getTimestamp(),
            'parse' => $parse,
            'rows' => $rows,
        ]);
        $deflated = gzdeflate($body, 6);
        if ($deflated === false) {
            throw new \RuntimeException('The parse could not be sealed.');
        }
        $packed = self::base64($deflated);

        return $packed . '.' . self::base64(hash_hmac('sha256', $packed, $this->key, true));
    }

    /**
     * @return array{rows: list<array<string, mixed>>, parse: array<string, mixed>}
     *
     * @throws InvalidRfqValue for a seal that is not this server's, not this person's, or too old
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
        $raw = self::unbase64($packed);
        $json = $raw === null ? false : @gzinflate($raw);
        if ($json === false) {
            throw self::refused();
        }
        try {
            $body = PythonJson::decode($json);
        } catch (JsonException) {
            throw self::refused();
        }
        if (!is_array($body) || ($body['v'] ?? null) !== self::VERSION
            || !is_int($body['issued'] ?? null) || !is_array($body['rows'] ?? null) || !is_array($body['parse'] ?? null)) {
            throw self::refused();
        }
        if (!is_string($body['who'] ?? null) || !hash_equals($body['who'], $this->who($email))) {
            throw new InvalidRfqValue('This match was made for someone else. Press Match Text again.');
        }
        $age = $now->getTimestamp() - $body['issued'];
        if ($age < -300 || $age > self::MAX_AGE_SECONDS) {
            throw new InvalidRfqValue('This match is more than 12 hours old. Press Match Text again, then save.');
        }

        $rows = [];
        foreach ($body['rows'] as $row) {
            if (!is_array($row) || ($row !== [] && array_is_list($row))) {
                throw self::refused();
            }
            /** @var array<string, mixed> $row */
            $rows[] = $row;
        }
        /** @var array<string, mixed> $parse */
        $parse = $body['parse'];

        return ['rows' => $rows, 'parse' => $parse];
    }

    private function who(string $email): string
    {
        return self::base64(hash_hmac('sha256', 'who:' . strtolower(trim($email)), $this->key, true));
    }

    private static function refused(): InvalidRfqValue
    {
        return new InvalidRfqValue('This match could not be read back. Press Match Text again, then save.');
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
