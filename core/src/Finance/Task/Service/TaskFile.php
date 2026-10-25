<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Service;

/** A task's file ready to serve: the bytes, the stored type and the name safe for a header. */
final readonly class TaskFile
{
    public function __construct(
        public string $bytes,
        public string $contentType,
        public string $downloadName,
    ) {
    }
}
