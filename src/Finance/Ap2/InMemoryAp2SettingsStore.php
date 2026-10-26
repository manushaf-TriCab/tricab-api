<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use TriCab\Core\Finance\Ap2\Contract\Ap2SettingsStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Settings;

/** The defaults, or what a test sets. */
final class InMemoryAp2SettingsStore implements Ap2SettingsStore
{
    private Ap2Settings $settings;

    public function __construct(?Ap2Settings $settings = null)
    {
        $this->settings = $settings ?? new Ap2Settings();
    }

    public function get(): Ap2Settings
    {
        return $this->settings;
    }

    public function save(Ap2Settings $settings, int $userId): void
    {
        $this->settings = $settings;
    }

    public function saveFxRates(array $rates, int $userId): void
    {
        $this->settings = $this->settings->with(fxRates: $rates);
    }
}
