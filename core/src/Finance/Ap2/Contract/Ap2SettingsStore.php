<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

use TriCab\Core\Finance\Ap2\Domain\Ap2Settings;

/** `finance.ap2_settings`: stored values over the defaults. */
interface Ap2SettingsStore
{
    public function get(): Ap2Settings;

    /** Stores every editable value (the exchange rates are not touched). */
    public function save(Ap2Settings $settings, int $userId): void;

    /**
     * Stores the exchange rates (AUD per one unit; the `fx_rates` setting) and nothing else. Only the Odoo sync calls it;
     * `$userId` is 0 for the system.
     *
     * @param array<string, float> $rates
     */
    public function saveFxRates(array $rates, int $userId): void;
}
