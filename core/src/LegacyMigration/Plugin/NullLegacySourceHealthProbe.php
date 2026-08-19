<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Plugin;

use TriCab\Core\LegacyMigration\Contract\LegacySourceHealthProbe;

final class NullLegacySourceHealthProbe implements LegacySourceHealthProbe
{
    public function probe(string $code): ?array
    {
        unset($code);

        return null;
    }
}
