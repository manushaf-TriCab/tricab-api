<?php

declare(strict_types=1);

namespace TriCab\Core\TelDirectory\Application\Exception;

use RuntimeException;

final class TelDirectoryUnavailable extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('Tel Directory is not configured.');
    }

    public static function graphFailure(string $detail = ''): self
    {
        $suffix = $detail !== '' ? ' ' . $detail : '';

        return new self('Unable to load Tel Directory from Microsoft Graph.' . $suffix);
    }
}
