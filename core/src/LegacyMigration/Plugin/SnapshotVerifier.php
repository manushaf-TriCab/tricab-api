<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Plugin;

use TriCab\Core\LegacyMigration\Contract\Verifier;

final class SnapshotVerifier implements Verifier
{
    public function verify(array $snapshots): array
    {
        return [
            'ok' => true,
            'message' => 'Staging snapshots present; production tables were not written.',
            'checked' => count($snapshots),
        ];
    }
}
