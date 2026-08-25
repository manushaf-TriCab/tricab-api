<?php

declare(strict_types=1);

namespace TriCab\Api\Bootstrap;

/**
 * Prefer the in-repo Core tree over a stale Composer path-package copy.
 * Path repository copies with symlink=false are only refreshed on reinstall.
 */
final class CoreAutoload
{
    public static function register(): void
    {
        $coreSrc = dirname(__DIR__, 2) . '/core/src';
        spl_autoload_register(static function (string $class) use ($coreSrc): void {
            if (!str_starts_with($class, 'TriCab\\Core\\')) {
                return;
            }
            $relative = str_replace('\\', '/', substr($class, strlen('TriCab\\Core\\'))) . '.php';
            $path = $coreSrc . '/' . $relative;
            if (is_file($path)) {
                require $path;
            }
        }, true, true);
    }
}
