<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap2\Domain\Ap2InvoiceLine;

/**
 * Typed reading of a JSON body, collecting every problem so a refusal can list them all (`error.details.issues`).
 * A key that is absent is left alone; a key that is present must be valid.
 */
final class Ap2Input
{
    /** @var list<string> */
    private array $issues = [];

    /** @var array<string, mixed> */
    private array $out = [];

    /**
     * @param array<string, mixed> $body
     */
    public function __construct(private readonly array $body)
    {
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body);
    }

    /** @return list<string> */
    public function issues(): array
    {
        return $this->issues;
    }

    /** @return array<string, mixed> property => value, for the keys that were present and valid */
    public function values(): array
    {
        return $this->out;
    }

    public function text(string $key, int $max, bool $required = false): void
    {
        if (!$this->has($key)) {
            return;
        }
        $value = $this->body[$key];
        if ($value === null) {
            $value = '';
        }
        if (!is_string($value)) {
            $this->issues[] = $key . ' must be text';

            return;
        }
        $value = trim($value);
        if ($required && $value === '') {
            $this->issues[] = $key . ' is required';

            return;
        }
        if (mb_strlen($value) > $max) {
            $this->issues[] = $key . ' must be at most ' . $max . ' characters';

            return;
        }
        $this->out[$key] = $value;
    }

    /** @param list<string> $allowed */
    public function choice(string $key, array $allowed): void
    {
        if (!$this->has($key)) {
            return;
        }
        $value = $this->body[$key];
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            $this->issues[] = $key . ' must be one of ' . implode(', ', $allowed);

            return;
        }
        $this->out[$key] = $value;
    }

    public function money(string $key): void
    {
        $this->number($key, 2);
    }

    public function quantity(string $key): void
    {
        $this->number($key, 4);
    }

    private function number(string $key, int $places): void
    {
        if (!$this->has($key)) {
            return;
        }
        $value = $this->body[$key];
        if (is_string($value) && is_numeric($value)) {
            $value = (float) $value;
        }
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || (float) $value < 0 || (float) $value > 9999999999999.0) {
            $this->issues[] = $key . ' must be a number of zero or more';

            return;
        }
        $this->out[$key] = round((float) $value, $places);
    }

    /** A `yyyy-MM-dd` calendar date; null or '' clears it. */
    public function date(string $key): void
    {
        if (!$this->has($key)) {
            return;
        }
        $value = $this->body[$key];
        if ($value === null || $value === '') {
            $this->out[$key] = null;

            return;
        }
        $parsed = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            $this->issues[] = $key . ' must be a date as yyyy-mm-dd';

            return;
        }
        $this->out[$key] = $value;
    }

    public function flag(string $key): void
    {
        if (!$this->has($key)) {
            return;
        }
        $value = $this->body[$key];
        if (!is_bool($value)) {
            $this->issues[] = $key . ' must be true or false';

            return;
        }
        $this->out[$key] = $value;
    }

    /** A positive integer or null (to clear). */
    public function userId(string $key): void
    {
        if (!$this->has($key)) {
            return;
        }
        $value = $this->body[$key];
        if ($value === null || $value === '') {
            $this->out[$key] = null;

            return;
        }
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 1) {
            $this->issues[] = $key . ' must be a user id';

            return;
        }
        $this->out[$key] = $value;
    }

    /** A 32-character lower-case hex id. */
    public function id(string $key): void
    {
        if (!$this->has($key)) {
            return;
        }
        $value = $this->body[$key];
        if (!is_string($value) || preg_match('/^[0-9a-f]{32}$/', $value) !== 1) {
            $this->issues[] = $key . ' must be an id';

            return;
        }
        $this->out[$key] = $value;
    }

    /** A non-negative whole number up to `$max`. */
    public function whole(string $key, int $max): void
    {
        if (!$this->has($key)) {
            return;
        }
        $value = $this->body[$key];
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 0 || $value > $max) {
            $this->issues[] = $key . ' must be a whole number from 0 to ' . $max;

            return;
        }
        $this->out[$key] = $value;
    }

    /** A bare address or a list separated by commas or semicolons; '' is none. */
    public function addresses(string $key, int $max): void
    {
        $this->text($key, $max);
        if (!isset($this->out[$key]) || !is_string($this->out[$key]) || $this->out[$key] === '') {
            return;
        }
        foreach (preg_split('/[;,]/', $this->out[$key]) ?: [] as $one) {
            $one = trim($one);
            if ($one !== '' && preg_match('/^[^\s@<>]+@[^\s@<>]+\.[^\s@<>]+$/', $one) !== 1) {
                $this->issues[] = $key . ' has an invalid email address: ' . $one;
                unset($this->out[$key]);

                return;
            }
        }
    }

    /**
     * Odoo bill lines: a list of `{label, account, quantity, unitPrice, taxes}`, stored under the property `$as`.
     * An empty list goes back to the default line. Quantity is above zero; a unit price may be negative (a discount
     * line). Any bad line refuses the whole list, naming the line.
     */
    public function lines(string $key, string $as, int $max): void
    {
        if (!$this->has($key)) {
            return;
        }
        $raw = $this->body[$key];
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > $max) {
            $this->issues[] = $key . ' must be a list of at most ' . $max . ' lines';

            return;
        }
        $lines = [];
        $bad = false;
        foreach ($raw as $i => $line) {
            $n = $key . '[' . ($i + 1) . ']';
            if (!is_array($line)) {
                $this->issues[] = $n . ' must be an object';
                $bad = true;
                continue;
            }
            $text = function (string $field, int $limit) use ($line, $n, &$bad): string {
                $value = $line[$field] ?? '';
                if (!is_string($value) || mb_strlen(trim($value)) > $limit) {
                    $this->issues[] = $n . '.' . $field . ' must be text of at most ' . $limit . ' characters';
                    $bad = true;

                    return '';
                }

                return trim($value);
            };
            $number = function (string $field, bool $positive) use ($line, $n, &$bad): float {
                $value = $line[$field] ?? null;
                if (is_string($value) && is_numeric($value)) {
                    $value = (float) $value;
                }
                if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || abs((float) $value) > 9999999999999.0 || ($positive && (float) $value <= 0)) {
                    $this->issues[] = $n . '.' . $field . ($positive ? ' must be a number above zero' : ' must be a number');
                    $bad = true;

                    return 0.0;
                }

                return round((float) $value, 4);
            };
            $lines[] = new Ap2InvoiceLine($text('label', 200), $text('account', 50), $number('quantity', true), $number('unitPrice', false), $text('taxes', 50));
        }
        if (!$bad) {
            $this->out[$as] = $lines;
        }
    }
}
