<?php

declare(strict_types=1);

namespace TriCab\Core\Shared\Mailbox;

use InvalidArgumentException;

/** The folder names {@see FolderMailboxReader} accepts. */
final class MailboxFolders
{
    private function __construct()
    {
    }

    /**
     * The lower-case well-known name, or an exception: a folder name goes into a URL, so only Graph's own aliases pass.
     *
     * @throws InvalidArgumentException
     */
    public static function normalise(string $folder): string
    {
        $name = strtolower(trim($folder));
        if (!in_array($name, FolderMailboxReader::WELL_KNOWN, true)) {
            throw new InvalidArgumentException('The folder must be one of: ' . implode(', ', FolderMailboxReader::WELL_KNOWN) . '.');
        }

        return $name;
    }
}
