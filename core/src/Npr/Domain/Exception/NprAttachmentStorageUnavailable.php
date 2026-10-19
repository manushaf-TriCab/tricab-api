<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Domain\Exception;

use RuntimeException;

/** The Blob container could not be written or read (not configured, or the storage call failed). */
final class NprAttachmentStorageUnavailable extends RuntimeException
{
}
