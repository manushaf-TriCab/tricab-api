<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Domain\Exception;

use RuntimeException;

/** The body is not an NPR (wrong shape). Maps to 422. */
final class InvalidNprInput extends RuntimeException
{
}
