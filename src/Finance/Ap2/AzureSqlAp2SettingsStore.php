<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use TriCab\Core\Finance\Ap2\Contract\Ap2SettingsStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Settings;

/** `finance.ap2_settings` over the defaults. A stored value that does not parse is ignored. */
final class AzureSqlAp2SettingsStore extends AzureSqlAp2Support implements Ap2SettingsStore
{
    public function get(): Ap2Settings
    {
        $stored = [];
        foreach ($this->connection->fetchAll('SELECT [setting_key], [setting_value] FROM [finance].[ap2_settings]') as $row) {
            $stored[self::text($row['setting_key'])] = self::text($row['setting_value']);
        }

        return self::from($stored);
    }

    public function save(Ap2Settings $s, int $userId): void
    {
        $values = [
            'gl_default' => $s->glDefault,
            'tax_default' => $s->taxDefault,
            'escalation_days' => (string) $s->escalationDays,
            'batch_cash_limit' => number_format($s->batchCashLimit, 2, '.', ''),
            'approval_expiry_days' => (string) $s->approvalExpiryDays,
            'payment_proposals_enabled' => $s->paymentProposalsEnabled ? '1' : '0',
            'tax_code_map' => json_encode((object) $s->taxCodeMap, JSON_THROW_ON_ERROR),
        ];
        $this->transactional(function () use ($values, $userId, $s): null {
            if ($s->mailCutoffDate === null) {
                $this->connection->execute('DELETE FROM [finance].[ap2_settings] WHERE [setting_key] = :key', ['key' => 'mail_cutoff_date']);
            } else {
                $values['mail_cutoff_date'] = $s->mailCutoffDate;
            }
            foreach ($values as $key => $value) {
                $this->connection->execute('DELETE FROM [finance].[ap2_settings] WHERE [setting_key] = :key', ['key' => $key]);
                $this->connection->execute(
                    'INSERT INTO [finance].[ap2_settings] ([setting_key], [setting_value], [updated_by_user_id]) VALUES (:key, :value, :user)',
                    ['key' => $key, 'value' => $value, 'user' => $userId],
                );
            }

            return null;
        });
    }

    public function saveFxRates(array $rates, int $userId): void
    {
        $json = json_encode($rates, JSON_THROW_ON_ERROR);
        $this->transactional(function () use ($json, $userId): null {
            $this->connection->execute('DELETE FROM [finance].[ap2_settings] WHERE [setting_key] = :key', ['key' => 'fx_rates']);
            $this->connection->execute(
                'INSERT INTO [finance].[ap2_settings] ([setting_key], [setting_value], [updated_by_user_id]) VALUES (:key, :value, :user)',
                ['key' => 'fx_rates', 'value' => $json, 'user' => $userId > 0 ? $userId : null],
            );

            return null;
        });
    }

    /**
     * @param array<string, string> $stored
     */
    public static function from(array $stored): Ap2Settings
    {
        $s = new Ap2Settings();
        $changes = [];
        foreach (['glDefault' => 'gl_default', 'taxDefault' => 'tax_default'] as $property => $key) {
            if (isset($stored[$key]) && $stored[$key] !== '') {
                $changes[$property] = $stored[$key];
            }
        }
        foreach (['escalationDays' => 'escalation_days', 'approvalExpiryDays' => 'approval_expiry_days'] as $property => $key) {
            if (isset($stored[$key]) && ctype_digit($stored[$key])) {
                $changes[$property] = (int) $stored[$key];
            }
        }
        if (isset($stored['payment_proposals_enabled'])) {
            $changes['paymentProposalsEnabled'] = $stored['payment_proposals_enabled'] === '1';
        }
        if (isset($stored['mail_cutoff_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $stored['mail_cutoff_date']) === 1) {
            $changes['mailCutoffDate'] = $stored['mail_cutoff_date'];
        }
        if (isset($stored['batch_cash_limit']) && is_numeric($stored['batch_cash_limit'])) {
            $changes['batchCashLimit'] = (float) $stored['batch_cash_limit'];
        }
        if (isset($stored['tax_code_map'])) {
            $decoded = json_decode($stored['tax_code_map'], true);
            if (is_array($decoded)) {
                $map = [];
                foreach ($decoded as $code => $odooId) {
                    if (is_string($code) && $code !== '' && is_int($odooId) && $odooId > 0) {
                        $map[$code] = $odooId;
                    }
                }
                $changes['taxCodeMap'] = $map;
            }
        }
        if (isset($stored['fx_rates'])) {
            $decoded = json_decode($stored['fx_rates'], true);
            if (is_array($decoded)) {
                $rates = ['AUD' => 1.0];
                foreach ($decoded as $code => $rate) {
                    if (is_string($code) && (is_int($rate) || is_float($rate)) && $rate > 0) {
                        $rates[$code] = (float) $rate;
                    }
                }
                $changes['fxRates'] = $rates;
            }
        }

        return $s->with(...$changes);
    }
}
