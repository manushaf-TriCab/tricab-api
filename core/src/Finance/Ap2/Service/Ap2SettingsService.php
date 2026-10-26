<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use TriCab\Core\Finance\Ap2\Contract\Ap2SettingsStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Actor;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invalid;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invoice;
use TriCab\Core\Finance\Shared\Odoo\OdooLists;
use TriCab\Core\Finance\Shared\Odoo\OdooRecordStore;

/**
 * `GET` and `PATCH /settings`. The editable values are the defaults and limits; `currencies`, `odooPostingEnabled`
 * and `mailbox` are read-only (they come from the platform's configuration): a page may send them back unchanged, but
 * a different value is refused. Settings belong to no supplier, so a change leaves no ledger line.
 */
final class Ap2SettingsService
{
    public function __construct(
        private readonly Ap2SettingsStore $settings,
        private readonly bool $odooPostingEnabled,
        private readonly string $mailbox,
        private readonly ?OdooRecordStore $odoo = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        return Ap2Presenter::settings($this->settings->get(), $this->odooPostingEnabled, $this->mailbox);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(Ap2Actor $actor, array $body): array
    {
        $input = new Ap2Input($body);
        $input->text('glDefault', 50, true);
        $input->text('taxDefault', 50, true);
        $input->whole('escalationDays', 365);
        $input->money('batchCashLimit');
        $input->whole('approvalExpiryDays', 90);
        $input->flag('paymentProposalsEnabled');
        $input->date('mailCutoffDate');
        $issues = $input->issues();
        $values = $input->values();
        if (isset($values['batchCashLimit']) && $values['batchCashLimit'] <= 0) {
            $issues[] = 'batchCashLimit must be more than zero';
        }
        if (isset($values['approvalExpiryDays']) && $values['approvalExpiryDays'] < 1) {
            $issues[] = 'approvalExpiryDays must be at least 1';
        }
        if (array_key_exists('taxCodeMap', $body)) {
            $map = $this->taxCodeMap($body['taxCodeMap'], $issues);
            if ($map !== null) {
                $values['taxCodeMap'] = $map;
            }
        }
        $fixed = ['currencies' => Ap2Invoice::CURRENCIES, 'odooPostingEnabled' => $this->odooPostingEnabled, 'mailbox' => $this->mailbox];
        foreach ($fixed as $key => $current) {
            if (array_key_exists($key, $body) && $body[$key] !== $current) {
                $issues[] = $key . ' is read-only';
            }
        }
        if ($issues !== []) {
            throw new Ap2Invalid('The settings are invalid', Ap2Failure::details($issues));
        }
        $this->settings->save($this->settings->get()->with(...$values), $actor->userId);

        return $this->get();
    }

    /**
     * @param list<string> $issues
     * @return array<string, int>|null
     */
    private function taxCodeMap(mixed $raw, array &$issues): ?array
    {
        if ($raw === null || $raw === []) {
            return [];
        }
        if (!is_array($raw) || array_is_list($raw)) {
            $issues[] = 'taxCodeMap must be an object of tax code to Odoo tax id';

            return null;
        }
        $known = [];
        foreach ($this->odoo?->list(OdooLists::TAXES, false, null) ?? [] as $tax) {
            $known[$tax->odooId] = true;
        }
        $map = [];
        foreach ($raw as $code => $id) {
            $code = trim((string) $code);
            if ($code === '' || mb_strlen($code) > 50 || !is_int($id) || $id <= 0) {
                $issues[] = 'taxCodeMap: "' . $code . '" needs a tax code (up to 50 characters) and a positive Odoo tax id';
            } elseif (!isset($known[$id])) {
                $issues[] = 'taxCodeMap: Odoo tax ' . $id . ' is not in the synced purchase taxes';
            } else {
                $map[$code] = $id;
            }
        }

        return $map;
    }
}
