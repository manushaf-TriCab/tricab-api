<?php

declare(strict_types=1);

namespace TriCab\Api\Bootstrap;

/**
 * Loads TriCab\Core classes straight from the in-repo core/src tree.
 *
 * This existed because the core path repository was copied rather than symlinked, so the
 * Composer vendor tree went stale whenever a module was added. It is symlinked now, which
 * means Composer's own mapping is always current and this shim is a fallback rather than
 * the primary route — it still serves entry points that want core/src regardless of vendor
 * state. Do not treat it as licence to leave the vendor tree stale: because it silently
 * succeeds, it hides staleness from everything except a fresh checkout. The vendor tree is
 * checked directly by MeEndpointTest::test_api_depends_on_core_through_composer.
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
