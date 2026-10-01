<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain;

use DateTimeImmutable;

/**
 * cc/YYYY/MM/{server-name}. The caller never chooses the path.
 */
final class CustomerContactReportBlobPath
{
    public static function evidence(DateTimeImmutable $at, string $extension): string
    {
        return self::folder($at) . bin2hex(random_bytes(16)) . '.' . self::extension($extension);
    }

    public static function creditNote(DateTimeImmutable $at, string $extension): string
    {
        return self::folder($at) . 'note-' . bin2hex(random_bytes(16)) . '.' . self::extension($extension);
    }

    public static function signature(DateTimeImmutable $at, string $publicId): string
    {
        $id = strtolower(trim($publicId));
        if (!preg_match('/^[0-9a-f-]{36}$/', $id)) {
            $id = bin2hex(random_bytes(16));
        }

        return self::folder($at) . 'sig-' . $id . '-' . bin2hex(random_bytes(8)) . '.png';
    }

    private static function folder(DateTimeImmutable $at): string
    {
        return 'cc/' . $at->format('Y') . '/' . $at->format('m') . '/';
    }

    private static function extension(string $extension): string
    {
        $clean = strtolower(trim($extension));
        if (!preg_match('/^[a-z0-9]{2,5}$/', $clean)) {
            return 'bin';
        }

        return $clean;
    }
}
