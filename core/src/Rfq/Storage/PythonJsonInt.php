<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Storage;

/**
 * A JSON integer too large for PHP's int, kept as its digits so that it
 * encodes back unchanged. Python's ints are unbounded; PHP would turn it into
 * a float and lose digits.
 */
final class PythonJsonInt
{
    public function __construct(public readonly string $digits)
    {
    }
}
