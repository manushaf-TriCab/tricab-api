<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Cache;

use InvalidArgumentException;
use TriCab\Api\Config\Settings;

final class ApplicationCacheFactory
{
    private static bool $loggedDriver = false;

    public static function create(Settings $settings): ApplicationCache
    {
        $cache = match ($settings->appCacheDriver) {
            Settings::CACHE_DRIVER_APCU => new ApcuApplicationCache(),
            Settings::CACHE_DRIVER_ARRAY => new ArrayApplicationCache(),
            Settings::CACHE_DRIVER_FILE => new FilesystemApplicationCache(self::resolveDirectory($settings)),
            default => throw new InvalidArgumentException(
                'Unsupported APP_CACHE_DRIVER "' . $settings->appCacheDriver . '".',
            ),
        };

        if (!self::$loggedDriver) {
            self::$loggedDriver = true;
            error_log(sprintf(
                '[cache] driver=%s cross_request=%s',
                $cache->driver(),
                $cache->isCrossRequest() ? '1' : '0',
            ));
        }

        return $cache;
    }

    private static function resolveDirectory(Settings $settings): string
    {
        $path = trim($settings->appCachePath);
        if ($path === '') {
            $path = 'var/cache/app';
        }

        $isAbsolute = str_starts_with($path, '/')
            || (strlen($path) > 1 && ctype_alpha($path[0]) && $path[1] === ':');
        if (!$isAbsolute) {
            $path = dirname(__DIR__, 3) . '/' . ltrim($path, '/\\');
        }

        return $path;
    }
}
