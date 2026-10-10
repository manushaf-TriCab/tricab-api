<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use DateTimeZone;
use TriCab\Core\Finance\Ap\Contract\ApVendorStore;
use TriCab\Core\Finance\Ap\Domain\ApVendor;
use TriCab\Core\Finance\Ap\Domain\ApVendorInvalid;
use TriCab\Core\Finance\Ap\Domain\ApVendorInvoice;
use TriCab\Core\Finance\Ap\Domain\ApVendorMatch;
use TriCab\Core\Finance\Ap\Domain\ApVendorUnavailable;

/**
 * What the vendor routes of `app.py` do, without HTTP or SQL: the Vendors list, an invoice's suggestions, the
 * three "set a match" bodies, and a run over many invoices. The caller has already found the invoice, checked
 * that the person may see it (the mailbox scope) and read the body; it records the audit event from the
 * returned match (`vendor_match`, `odoo_vendor_id`, `vendor_ref`).
 *
 * Every operation that reads the vendor list answers {@see ApVendorUnavailable} when no vendor is synced.
 * Marking an invoice as a new vendor reads no list and never refuses.
 */
final class ApVendorService
{
    public function __construct(private readonly ApVendorStore $store)
    {
    }

    /**
     * `GET /vendors`: the synced vendors (archived included) with how many invoices each has.
     *
     * @return array{items: list<array{odoo_id: int, name: string, ref: string, email: string, vat: string, company_name: string, active: bool, invoice_count: int}>, synced_at: string|null}
     */
    public function vendors(): array
    {
        $vendors = $this->availableVendors();
        $counts = $this->store->invoiceCountsByVendor();
        $items = [];
        foreach ($vendors as $vendor) {
            $items[] = [
                'odoo_id' => $vendor->odooId,
                'name' => $vendor->name,
                'ref' => $vendor->ref,
                'email' => $vendor->email,
                'vat' => $vendor->vat,
                'company_name' => $vendor->companyName,
                'active' => $vendor->active,
                'invoice_count' => $counts[$vendor->odooId] ?? 0,
            ];
        }

        return [
            'items' => $items,
            'synced_at' => $this->store->vendorsSyncedAt()?->setTimezone(new DateTimeZone('UTC'))->format('c'),
        ];
    }

    /**
     * `GET /invoices/{id}/vendor-suggestions`: up to five ranked candidates, and whether the vendor read
     * from the invoice looks like TriCab itself.
     *
     * @return array{suggestions: list<array{odoo_id: int, name: string, ref: string, active: bool, reason: string}>, buyer_like: bool}
     */
    public function suggestions(ApVendorInvoice $invoice): array
    {
        $vendors = $this->availableVendors();
        $buyers = $this->buyers();
        $suggestions = [];
        foreach (ApVendorMatcher::suggestions($invoice, $vendors, $buyers) as $suggestion) {
            $suggestions[] = [
                'odoo_id' => $suggestion->vendor->odooId,
                'name' => $suggestion->vendor->name,
                'ref' => $suggestion->vendor->ref,
                'active' => $suggestion->vendor->active,
                'reason' => $suggestion->reason,
            ];
        }

        return [
            'suggestions' => $suggestions,
            'buyer_like' => ApVendorMatcher::looksLikeBuyer($invoice->vendor, $buyers, $invoice->company),
        ];
    }

    /**
     * `PUT /invoices/{id}/vendor-match`: a person picks the vendor (`{odoo_vendor_id}`, "confirmed"), marks
     * it as not in Odoo yet (`{status: "new"}`), or hands it back to automatic matching (`{status: "auto"}`,
     * which may leave it "unmatched"). The invoice's vendor columns are written (with `$byUserId`, who chose, on the
     * invoice), and the match returned.
     *
     * @param array<string, mixed> $body
     * @param string $actor who chose, for the note: the person's email, else their id, else "someone"
     * @throws ApVendorInvalid for an id that is not a number, a vendor not in the list, or neither key
     * @throws ApVendorUnavailable
     */
    public function setMatch(ApVendorInvoice $invoice, array $body, string $actor, ?int $byUserId = null): ApVendorMatch
    {
        $chosen = $body['odoo_vendor_id'] ?? null;
        if ($chosen !== null) {
            $odooId = self::integer($chosen) ?? throw new ApVendorInvalid('odoo_vendor_id must be a number');
            $vendor = self::find($this->availableVendors(), $odooId) ?? throw new ApVendorInvalid('That vendor is not in the Odoo list');
            $match = new ApVendorMatch(
                $odooId,
                $vendor->ref !== '' ? $vendor->ref : null,
                $vendor->name !== '' ? $vendor->name : null,
                ApVendorMatch::CONFIRMED,
                'Chosen by ' . $actor,
            );
        } elseif (($body['status'] ?? null) === 'new') {
            $match = new ApVendorMatch(
                null,
                null,
                null,
                ApVendorMatch::NEW,
                'Marked as a new vendor by ' . $actor . '; create it in Odoo',
            );
        } elseif (($body['status'] ?? null) === 'auto') {
            $match = $this->autoMatch($invoice) ?? throw new ApVendorUnavailable();
        } else {
            throw new ApVendorInvalid('Send odoo_vendor_id, or status "new" or "auto"');
        }
        $this->store->setInvoiceVendor($invoice->id, $match, $byUserId);

        return $match;
    }

    /**
     * The automatic match for an invoice as it stands, or null when no vendor is synced (the invoice is
     * then left untried, so a later run picks it up). Writes nothing: capture and an edit of the vendor
     * fields use this, and store the result themselves.
     */
    public function autoMatch(ApVendorInvoice $invoice): ?ApVendorMatch
    {
        $vendors = $this->store->vendors();
        if ($vendors === []) {
            return null;
        }

        return ApVendorMatcher::autoMatch($invoice, $vendors, $this->buyers());
    }

    /**
     * `POST /vendor-matching/run`: matches every invoice nobody has decided on, never tried or tried
     * without a certain match. Invoices a person confirmed or marked new, and ones already matched, are
     * skipped and not counted. With `$invoiceIds` only those invoices are considered.
     *
     * @param list<ApVendorInvoice> $invoices the live invoices the caller may see
     * @param list<int|string> $invoiceIds empty for all
     * @return array{checked: int, matched: int, unmatched: int}
     * @throws ApVendorUnavailable
     */
    public function run(array $invoices, array $invoiceIds = []): array
    {
        $vendors = $this->availableVendors();
        $buyers = $this->buyers();
        $wanted = array_fill_keys(array_map('strval', $invoiceIds), true);
        $checked = 0;
        $matched = 0;
        foreach ($invoices as $invoice) {
            if ($wanted !== [] && !isset($wanted[$invoice->id])) {
                continue;
            }
            if (in_array($invoice->vendorMatch, ApVendorMatch::DECIDED, true) || $invoice->vendorMatch === ApVendorMatch::MATCHED) {
                continue;
            }
            $checked++;
            $match = ApVendorMatcher::autoMatch($invoice, $vendors, $buyers);
            if ($match->vendorMatch === ApVendorMatch::MATCHED) {
                $matched++;
            }
            if (!$invoice->carries($match)) {
                $this->store->setInvoiceVendor($invoice->id, $match);
            }
        }

        return ['checked' => $checked, 'matched' => $matched, 'unmatched' => $checked - $matched];
    }

    /**
     * @return non-empty-list<ApVendor>
     */
    private function availableVendors(): array
    {
        $vendors = $this->store->vendors();

        return $vendors === [] ? throw new ApVendorUnavailable() : $vendors;
    }

    /**
     * @return list<string>
     */
    private function buyers(): array
    {
        return ApVendorMatcher::buyerNames($this->store->activeCompanies());
    }

    /**
     * @param list<ApVendor> $vendors
     */
    private static function find(array $vendors, int $odooId): ?ApVendor
    {
        foreach ($vendors as $vendor) {
            if ($vendor->odooId === $odooId) {
                return $vendor;
            }
        }

        return null;
    }

    /**
     * Python's `int(value)`, null where it raises `ValueError` or `TypeError`: whole numbers, floats (cut
     * towards zero), booleans (as 0 and 1) and strings of digits with an optional sign, surrounding
     * space and single underscores between digits. A number too big for an integer becomes the largest
     * one, which no vendor has (so it is "not in the Odoo list", as the source says).
     */
    private static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return (int) $value;
        }
        if (is_float($value)) {
            return is_finite($value) ? (int) $value : null;
        }
        if (!is_string($value)) {
            return null;
        }
        $text = trim($value, " \t\n\r\x0b\x0c\x1c\x1d\x1e\x1f");
        if (preg_match('/^([+-]?)([0-9]+(?:_[0-9]+)*)$/', $text, $found) !== 1) {
            return null;
        }
        $digits = ltrim(str_replace('_', '', $found[2]), '0');
        if ($digits === '') {
            return 0;
        }
        $number = filter_var($found[1] . $digits, FILTER_VALIDATE_INT);

        return is_int($number) ? $number : PHP_INT_MAX;
    }
}
