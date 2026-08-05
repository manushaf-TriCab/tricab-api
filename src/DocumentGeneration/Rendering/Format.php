<?php

declare(strict_types=1);

namespace TriCab\Api\DocumentGeneration\Rendering;

use DateTimeImmutable;

final class Format
{
    public static function html(string $value): string
    {
        return htmlspecialchars(trim($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function shortDate(string $value): string
    {
        return self::date($value, 'd M y');
    }

    public static function longDate(string $value): string
    {
        return self::date($value, 'D d M y');
    }

    private static function date(string $value, string $format): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $date = DateTimeImmutable::createFromFormat('!d/m/y', $value);
        return $date instanceof DateTimeImmutable ? $date->format($format) : $value;
    }
}
