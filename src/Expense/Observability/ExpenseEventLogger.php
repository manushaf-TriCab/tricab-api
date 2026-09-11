<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Observability;

/**
 * Structured TriExpense event log.
 *
 * Mail and OCR both handle material that must never reach a log line: Graph
 * access tokens, SAS URLs, API keys, and document bytes. Keys whose name looks
 * like any of those are dropped, values that look like a token or a SAS-bearing
 * URL are dropped, and long values are truncated so a stray body cannot be
 * pasted into the log wholesale.
 */
final class ExpenseEventLogger
{
    private const MAX_VALUE_LENGTH = 200;

    /** @var list<array<string, scalar|null>> */
    private array $recorded = [];

    public function __construct(private readonly bool $retainForTests = false)
    {
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function log(string $event, array $context = []): void
    {
        $safe = ['event' => $event];
        foreach ($context as $key => $value) {
            if (self::isForbiddenKey($key)) {
                continue;
            }
            [$keep, $scalar] = self::sanitizeValue($value);
            if (!$keep) {
                continue;
            }
            $safe[$key] = $scalar;
        }

        if ($this->retainForTests) {
            // Tests assert on recorded(); emitting as well would only bury the
            // real PHPUnit output in noise.
            $this->recorded[] = $safe;

            return;
        }

        error_log(json_encode($safe, JSON_UNESCAPED_SLASHES) ?: '{"event":"expense_log_encode_failed"}');
    }

    /**
     * Recipient addresses are personal data and a mailing-list leak if logged in
     * bulk. Keep enough to correlate a complaint with a send, not enough to
     * reconstruct the address.
     */
    public static function maskEmail(string $email): string
    {
        $normalized = strtolower(trim($email));
        $at = strrpos($normalized, '@');
        if ($at === false || $at === 0) {
            return 'invalid';
        }

        $local = substr($normalized, 0, $at);
        $domain = substr($normalized, $at + 1);

        return substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 1)) . '@' . $domain;
    }

    /** @return list<array<string, scalar|null>> */
    public function recorded(): array
    {
        return $this->recorded;
    }

    private static function isForbiddenKey(string $key): bool
    {
        $normalized = strtolower($key);
        foreach ([
            'token',
            'authorization',
            'secret',
            'password',
            'key',
            'sas',
            'signature',
            'bytes',
            'content',
            'body',
            'image',
            'attachment_data',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: bool, 1: scalar|null} first element false means drop the entry
     */
    private static function sanitizeValue(mixed $value): array
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return [true, $value];
        }
        if (!is_string($value)) {
            return [false, null];
        }

        $lower = strtolower($value);
        if (str_starts_with($lower, 'bearer ')) {
            return [false, null];
        }
        // SAS query signatures: sig=, or the sp=/se= permission-plus-expiry pair.
        if (str_contains($lower, 'sig=') || (str_contains($lower, 'se=') && str_contains($lower, 'sp='))) {
            return [false, null];
        }
        if (preg_match('/^[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]+$/', $value) === 1) {
            return [false, null];
        }

        return [
            true,
            strlen($value) > self::MAX_VALUE_LENGTH
                ? substr($value, 0, self::MAX_VALUE_LENGTH) . '…'
                : $value,
        ];
    }
}
