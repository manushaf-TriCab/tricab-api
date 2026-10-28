<?php

declare(strict_types=1);

namespace TriCab\Core\Conductor;

/**
 * Conductor: read-only views of how copper conductor is made on the conductor section of the factory map
 * (poster stages 1 to 4), served from a SQLite store that core/python/conductor_process builds from the Dynamics extract.
 */
final class Module
{
    public const NAME = 'Conductor';

    /** See the conductor pages and their data. */
    public const PERMISSION_READ = 'CONDUCTOR.READ';

    /** @var list<string> */
    public const PERMISSIONS = [
        self::PERMISSION_READ,
    ];
}
