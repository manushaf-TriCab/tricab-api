<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Migration;

use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentRules;

/**
 * The old app's attachments folder (`<record id>/<sanitised name>` under it). A stored name comes from a
 * sender-supplied file name, so a path is followed only if, once the system has resolved it (links and `..`
 * included), it is a file inside the folder. Checks by size without reading; {@see read()} reads.
 */
final class ArAttachmentFolder
{
    private readonly string $root;

    public function __construct(string $folder)
    {
        $real = realpath($folder);
        if ($real === false || !is_dir($real)) {
            throw new \InvalidArgumentException('The attachments folder does not exist.');
        }
        $this->root = rtrim($real, '\/');
    }

    /**
     * @return array{path: string, size: int}
     * @throws ArMigrationBadValue `file_missing`, `file_outside_folder`, `file_empty` or `file_too_large`
     */
    public function inspect(string $storedName): array
    {
        if ($storedName === '' || str_contains($storedName, "\0")) {
            throw new ArMigrationBadValue('file_missing');
        }
        $real = realpath($this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedName));
        if ($real === false || !is_file($real)) {
            // A name that climbs out of the folder and finds nothing is still an outside name.
            throw new ArMigrationBadValue(preg_match('~(^|[\\/])\.\.([\\/]|$)|^[\\/]|^[A-Za-z]:~', $storedName) === 1 ? 'file_outside_folder' : 'file_missing');
        }
        if (!str_starts_with(strtolower($real), strtolower($this->root . DIRECTORY_SEPARATOR))) {
            throw new ArMigrationBadValue('file_outside_folder');
        }
        $size = filesize($real);
        if ($size === false) {
            throw new ArMigrationBadValue('file_missing');
        }
        if ($size === 0) {
            throw new ArMigrationBadValue('file_empty');
        }
        if ($size > FinanceAttachmentRules::MAX_BYTES) {
            throw new ArMigrationBadValue('file_too_large');
        }

        return ['path' => $real, 'size' => $size];
    }

    /** The bytes of a path {@see inspect()} returned. */
    public function read(string $path): string
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException('A file could not be read.');
        }

        return $bytes;
    }
}
