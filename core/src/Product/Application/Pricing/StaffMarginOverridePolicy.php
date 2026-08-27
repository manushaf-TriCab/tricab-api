<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

/**
 * Legacy product-page margin options: integer −40 … 50 (single-cable-1.php).
 * Manual override never writes tmktm.
 */
final class StaffMarginOverridePolicy
{
    public const MIN = -40;
    public const MAX = 50;

    /**
     * @return list<int>
     */
    public static function allowedValues(): array
    {
        $out = [];
        for ($i = self::MIN; $i <= self::MAX; ++$i) {
            $out[] = $i;
        }

        return $out;
    }

    public static function normalize(string|int|float $raw): string
    {
        $text = trim((string) $raw);
        if ($text === '' || !preg_match('/^-?\d+$/', $text)) {
            throw new InvalidMarginOverrideException('Margin override must be an integer.');
        }
        $int = (int) $text;
        if ($int < self::MIN || $int > self::MAX) {
            throw new InvalidMarginOverrideException(
                sprintf('Margin override must be between %d and %d inclusive.', self::MIN, self::MAX),
            );
        }

        return (string) $int;
    }
}
