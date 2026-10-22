<?php

declare(strict_types=1);

namespace TriCab\Api\Config;

/**
 * The commits a release package was built from. The release build writes them
 * to release.json at the application root; a local checkout has none.
 */
final class ReleaseRevision
{
    public function __construct(
        public readonly string $api,
        public readonly string $web,
    ) {
    }

    public static function fromFile(string $path): ?self
    {
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            return null;
        }

        $api = $data['api'] ?? null;
        $web = $data['web'] ?? null;
        if (!is_string($api) || $api === '' || !is_string($web) || $web === '') {
            return null;
        }

        return new self($api, $web);
    }
}
