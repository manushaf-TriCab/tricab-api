<?php

declare(strict_types=1);

namespace TriCab\Api\Content;

/**
 * Where the static content images live, as the directory that contains `media/`.
 *
 * A checkout keeps them in <project>/public/media. The App Service release ships
 * one copy only, at the site root (<wwwroot>/media, served by nginx), and the
 * application sits one level below it in <wwwroot>/app, so the same files are
 * found at <project>/../media. Symlinks are forbidden in the release archive,
 * which is why the code follows the layout instead of a link.
 */
final class PublicMediaDirectory
{
    public static function forProjectRoot(string $projectRoot): string
    {
        $root = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        $own = $root . '/public';
        if (is_dir($own . '/media')) {
            return $own;
        }
        $parent = dirname($root);
        if (is_dir($parent . '/media')) {
            return $parent;
        }

        return $own;
    }
}
