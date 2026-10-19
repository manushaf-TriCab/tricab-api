<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Application;

use TriCab\Core\CableFit\Domain\ResearchInputInvalid;

/**
 * The design parameters a research run is about: validation of a caller-supplied object, and the flat
 * "path: value" list the prompt shows (only parameters that are set).
 */
final class ResearchParameters
{
    private const MAX_DEPTH = 4;
    private const MAX_KEYS = 200;
    private const MAX_VALUE_LENGTH = 200;
    private const MAX_LISTED = 60;

    /**
     * A caller-supplied parameters object, checked: string keys, scalar leaves, shallow, bounded.
     *
     * @return array<string, mixed>
     *
     * @throws ResearchInputInvalid
     */
    public static function validate(mixed $value): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ResearchInputInvalid('parameters must be an object.');
        }
        $count = 0;
        /** @var array<string, mixed> $value */
        self::check($value, 1, $count);

        return $value;
    }

    /**
     * Parameters that are set, as `path => text`, in order. Null, empty and `_`-prefixed (internal) entries
     * are left out.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<string, string>
     */
    public static function flatten(array $parameters): array
    {
        $out = [];
        self::walk($parameters, '', $out);

        return array_slice($out, 0, self::MAX_LISTED, true);
    }

    /**
     * @param array<array-key, mixed> $node
     */
    private static function check(array $node, int $depth, int &$count): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new ResearchInputInvalid('parameters are nested too deeply.');
        }
        foreach ($node as $key => $value) {
            if (++$count > self::MAX_KEYS) {
                throw new ResearchInputInvalid('parameters has too many entries.');
            }
            if (!is_string($key) && !array_is_list($node)) {
                throw new ResearchInputInvalid('parameter names must be text.');
            }
            if (is_string($key) && (trim($key) === '' || mb_strlen($key) > 64)) {
                throw new ResearchInputInvalid('parameter names must be 1 to 64 characters.');
            }
            if (is_array($value)) {
                self::check($value, $depth + 1, $count);
            } elseif ($value !== null && !is_scalar($value)) {
                throw new ResearchInputInvalid('parameter values must be text, numbers or yes/no.');
            }
        }
    }

    /**
     * @param array<array-key, mixed> $node
     * @param array<string, string>   $out
     */
    private static function walk(array $node, string $prefix, array &$out): void
    {
        foreach ($node as $key => $value) {
            $name = (string) $key;
            if (str_starts_with($name, '_')) {
                continue;
            }
            $path = $prefix === '' ? $name : $prefix . '.' . $name;
            if (is_array($value)) {
                self::walk($value, $path, $out);
                continue;
            }
            $text = self::text($value);
            if ($text !== null) {
                $out[$path] = $text;
            }
        }
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (!is_scalar($value)) {
            return null;
        }
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value));
        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > self::MAX_VALUE_LENGTH ? mb_substr($text, 0, self::MAX_VALUE_LENGTH) . '...' : $text;
    }
}
