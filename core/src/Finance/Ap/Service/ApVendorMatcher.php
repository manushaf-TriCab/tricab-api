<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use TriCab\Core\Finance\Ap\Domain\ApVendor;
use TriCab\Core\Finance\Ap\Domain\ApVendorInvoice;
use TriCab\Core\Finance\Ap\Domain\ApVendorMatch;
use TriCab\Core\Finance\Ap\Domain\ApVendorSuggestion;

/**
 * Match the vendor read from an invoice to a vendor in Odoo: `vendor_match.py` moved to PHP unchanged
 * (decision Q13), function for function and in the same order. Pure: it reads no store.
 *
 * A match is applied automatically only when it is certain: the tax id (digits only) is the same, the
 * vendor email on the invoice is the vendor's email in Odoo, or the names are equal once case,
 * punctuation and legal suffixes are ignored and exactly one live vendor has that name. Anything
 * weaker is only offered as a suggestion.
 *
 * Where Python's behaviour rests on its `str` and `re` semantics, this reproduces it on purpose:
 *  - `str.lower()` is `mb_strtolower` (full Unicode case mapping, as Python's);
 *  - `\s` is Python's whitespace set, spelled out in {@see self::SPACE} (PCRE's differs at U+001C to U+001F);
 *  - `\b`, `\w`, `\d`, `\D` are Unicode-aware (the `u` modifier), as Python 3 `str` patterns are;
 *  - strings compare by code point, which for UTF-8 is `strcmp`'s byte order;
 *  - `round(x, 2)` is correctly rounded on the exact binary value (`sprintf('%.2f')`), not PHP's `round()`.
 * What cannot be made identical is listed in the report that came with this port, and in the tests' notes.
 */
final class ApVendorMatcher
{
    /** Words that say what kind of company it is, not which one. */
    private const LEGAL_WORDS = [
        'pty', 'ltd', 'limited', 'proprietary', 'inc', 'incorporated', 'llc', 'plc',
        'corp', 'corporation', 'company', 'co', 'the',
    ];

    /**
     * Mailbox providers and invoicing platforms: a shared domain says nothing about which vendor sent
     * the mail (Xero sends from post.xero.com for everyone).
     */
    private const SHARED_DOMAINS = [
        'gmail.com', 'outlook.com', 'hotmail.com', 'live.com', 'yahoo.com', 'icloud.com',
        'bigpond.com', 'bigpond.net.au', 'xtra.co.nz', 'post.xero.com', 'xero.com',
        'myob.com', 'quickbooks.com', 'intuit.com', 'stripe.com', 'tricab.com',
    ];

    public const SUGGESTION_THRESHOLD = 0.55;

    public const MAX_SUGGESTIONS = 5;

    /** The inside of a character class holding what Python's `str.isspace()` (and so `\s` and `strip()`) counts as space. */
    private const SPACE = '\t\n\x0b\x0c\r\x{1c}-\x{1f} \x{85}\x{a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}';

    public static function normalizeName(?string $value): string
    {
        $text = str_replace('&', ' and ', mb_strtolower(self::text($value), 'UTF-8'));
        $text = self::replace('/\bp\/l\b/u', ' ', $text);
        $text = self::replace('/[^a-z0-9]+/', ' ', $text);
        $words = [];
        foreach (explode(' ', $text) as $word) {
            if ($word !== '' && !in_array($word, self::LEGAL_WORDS, true)) {
                $words[] = $word;
            }
        }

        return implode(' ', $words);
    }

    /**
     * The whole name and each side of a "t/as", normalised. "Lochert Bros t/as Sprint Freight & Logistics":
     * Odoo records the legal name and the trading name together, and an invoice usually carries only one.
     *
     * @return list<string> without duplicates or empty names; the order is not significant
     */
    public static function nameVariants(?string $value): array
    {
        $raw = self::text($value);
        $parts = [$raw, ...self::split('/[' . self::SPACE . ']+(?:t\/as|t\/a|trading[' . self::SPACE . ']+as)\b:?[' . self::SPACE . ']*/iu', $raw)];
        $variants = [];
        foreach ($parts as $part) {
            $normalized = self::normalizeName($part);
            if ($normalized !== '' && !in_array($normalized, $variants, true)) {
                $variants[] = $normalized;
            }
        }

        return $variants;
    }

    public static function taxDigits(?string $value): string
    {
        $digits = self::replace('/\D/u', '', self::text($value));

        // Shorter strings are fragments (a phone extension, "020-820"), not tax ids.
        return mb_strlen($digits, 'UTF-8') >= 8 ? $digits : '';
    }

    /**
     * Normalised names TriCab itself goes by, from Tri-AP's entity list.
     *
     * @param list<array{name?: string|null, former_names?: string|null}> $companies
     * @return list<string>
     */
    public static function buyerNames(array $companies): array
    {
        $names = ['tricab'];
        foreach ($companies as $company) {
            $values = [$company['name'] ?? null, ...explode(';', self::text($company['former_names'] ?? null))];
            foreach ($values as $value) {
                $normalized = self::normalizeName($value);
                if ($normalized !== '' && !in_array($normalized, $names, true)) {
                    $names[] = $normalized;
                }
            }
        }

        return $names;
    }

    /**
     * The model sometimes reads the bill-to block as the vendor ("tricab").
     *
     * TriCab companies are genuine Odoo vendors for intercompany bills ("Tricab (New Zealand) Ltd",
     * IC0004), so a TriCab name is only a misread when it is the entity the invoice is billed to. When
     * the billed entity is unknown nothing can tell the two apart, so any TriCab name is treated as a
     * misread: a person can still pick the intercompany vendor from suggestions.
     *
     * @param list<string> $buyers
     */
    public static function looksLikeBuyer(?string $vendor, array $buyers, ?string $billedEntity = null): bool
    {
        $normalized = self::normalizeName($vendor);
        if ($normalized === '') {
            return false;
        }
        if ($normalized === 'tricab') {
            return true;
        }
        $billed = self::normalizeName($billedEntity);
        if ($billed !== '') {
            return $normalized === $billed;
        }

        return in_array($normalized, $buyers, true) || explode(' ', $normalized)[0] === 'tricab';
    }

    /**
     * The vendor columns to store on the invoice: a match, or "unmatched" with the reason.
     *
     * @param list<ApVendor> $vendors
     * @param list<string> $buyers
     */
    public static function autoMatch(ApVendorInvoice $invoice, array $vendors, array $buyers): ApVendorMatch
    {
        $live = array_values(array_filter($vendors, static fn (ApVendor $vendor): bool => $vendor->active && !$vendor->deleted));
        $buyerLike = self::looksLikeBuyer($invoice->vendor, $buyers, $invoice->company);

        $digits = self::taxDigits($invoice->taxId);
        if ($digits !== '') {
            $hits = array_values(array_filter($live, static fn (ApVendor $vendor): bool => self::taxDigits($vendor->vat) === $digits));
            if (count($hits) === 1) {
                return self::matched($hits[0], 'tax id');
            }
        }

        $address = self::lower(self::strip(self::text($invoice->vendorEmail)));
        if ($address !== '' && str_contains($address, '@')) {
            $hits = array_values(array_filter($live, static fn (ApVendor $vendor): bool => in_array($address, self::emails($vendor->email), true)));
            if (count($hits) === 1) {
                return self::matched($hits[0], 'email');
            }
        }

        $normalized = self::normalizeName($invoice->vendor);
        if ($normalized !== '' && !$buyerLike) {
            $hits = array_values(array_filter($live, static fn (ApVendor $vendor): bool => self::normalizeName($vendor->name) === $normalized));
            if (count($hits) === 1) {
                return self::matched($hits[0], 'name');
            }
            if ($hits === []) {
                // Either side may carry a trading name; the result must still be a single vendor to count.
                $variants = self::nameVariants($invoice->vendor);
                $hits = array_values(array_filter(
                    $live,
                    static fn (ApVendor $vendor): bool => array_intersect($variants, self::nameVariants($vendor->name)) !== [],
                ));
                if (count($hits) === 1) {
                    return self::matched($hits[0], 'trading name');
                }
            }
        }

        if ($buyerLike) {
            $note = 'The vendor read from the invoice looks like TriCab itself; pick the real vendor';
        } elseif ($normalized === '') {
            $note = 'No vendor name was read from the invoice';
        } else {
            $note = 'No certain match in Odoo; pick a vendor or mark it as new';
        }

        return new ApVendorMatch(null, null, null, ApVendorMatch::UNMATCHED, $note);
    }

    /**
     * Ranked candidates for a person to choose from, each with a reason. Archived vendors are offered
     * (flagged inactive and ranked after live ones on a tie); removed ones are not.
     *
     * @param list<ApVendor> $vendors
     * @param list<string> $buyers
     * @return list<ApVendorSuggestion>
     */
    public static function suggestions(ApVendorInvoice $invoice, array $vendors, array $buyers, int $limit = self::MAX_SUGGESTIONS): array
    {
        $candidates = array_values(array_filter($vendors, static fn (ApVendor $vendor): bool => !$vendor->deleted));
        /** @var array<int, array{0: float, 1: string, 2: ApVendor}> $scored keyed by Odoo id, in the order each was first offered */
        $scored = [];
        $offer = static function (ApVendor $vendor, float $score, string $reason) use (&$scored): void {
            $current = $scored[$vendor->odooId] ?? null;
            if ($current === null || $score > $current[0]) {
                $scored[$vendor->odooId] = [$score, $reason, $vendor];
            }
        };

        $names = [];
        if (!self::looksLikeBuyer($invoice->vendor, $buyers, $invoice->company)) {
            foreach (self::nameVariants($invoice->vendor) as $variant) {
                $names[] = [$variant, 'name'];
            }
        }
        // The sender's display name is often the vendor when the model read the wrong block
        // ("Patricia Cesista" is not, but "Go Logistics Accounts" is).
        $senderName = self::replace('/<[^>]*>/u', '', self::firstNonEmpty($invoice->senderName, $invoice->sender));
        if (self::strip($senderName) !== '' && !str_contains($senderName, '@')) {
            $names[] = [self::normalizeName($senderName), 'sender name'];
        }

        foreach ($names as [$query, $label]) {
            if ($query === '') {
                continue;
            }
            foreach ($candidates as $vendor) {
                $score = 0.0;
                foreach (self::nameVariants($vendor->name) ?: [''] as $variant) {
                    $score = max($score, self::score($query, $variant));
                }
                if ($score >= self::SUGGESTION_THRESHOLD) {
                    $offer($vendor, $label === 'name' ? $score : $score * 0.9, 'Similar ' . $label);
                }
            }
        }

        $digits = self::taxDigits($invoice->taxId);
        $address = self::lower(self::strip(self::text($invoice->vendorEmail)));
        $senderAddress = self::senderAddress($invoice->sender);
        foreach ($candidates as $vendor) {
            if ($digits !== '' && self::taxDigits($vendor->vat) === $digits) {
                $offer($vendor, 1.0, 'Same tax id');
            }
            $vendorEmails = self::emails($vendor->email);
            if ($address !== '' && in_array($address, $vendorEmails, true)) {
                $offer($vendor, 1.0, 'Same email');
            }
            // The source iterates a Python set here, so its order (and which of two matching domains is
            // named) is arbitrary; the invoice's own address comes first here.
            foreach (array_values(array_unique(array_filter([$address, $senderAddress], static fn (string $value): bool => $value !== ''))) as $candidateAddress) {
                $domain = self::domain($candidateAddress);
                if ($domain !== '' && !in_array($domain, self::SHARED_DOMAINS, true)
                    && in_array($domain, array_map(self::domain(...), $vendorEmails), true)) {
                    $offer($vendor, 0.75, 'Email domain ' . $domain);
                }
            }
        }

        $ranked = array_values($scored);
        // Python's sort is stable and so is PHP 8's: equal keys keep the order they were first offered in.
        usort($ranked, static fn (array $left, array $right): int => (-$left[0] <=> -$right[0])
            ?: ((!$left[2]->active) <=> (!$right[2]->active))
            ?: strcmp(self::lower($left[2]->name), self::lower($right[2]->name)));

        $out = [];
        foreach (array_slice($ranked, 0, max(0, $limit)) as [$score, $reason, $vendor]) {
            $out[] = new ApVendorSuggestion($vendor, self::round2($score), $reason);
        }

        return $out;
    }

    /**
     * Similarity in [0, 1]. The first word carries the most weight: it is usually the distinctive one
     * ("Capital Transport" vs "Civic Transport").
     */
    public static function score(string $query, string $candidate): float
    {
        if ($query === '' || $candidate === '') {
            return 0.0;
        }
        $ratio = ApVendorSimilarity::ratio($query, $candidate);
        // Python's str.split(): runs of spaces separate, and none leaves an empty word.
        $queryWords = array_values(array_filter(explode(' ', $query), static fn (string $word): bool => $word !== ''));
        $candidateWords = array_values(array_filter(explode(' ', $candidate), static fn (string $word): bool => $word !== ''));
        if ($queryWords === [] || $candidateWords === []) {
            return 0.0; // the source raises here; names are normalised first, so it cannot be reached
        }
        $overlap = count(array_intersect(array_unique($queryWords), array_unique($candidateWords)))
            / count(array_unique([...$queryWords, ...$candidateWords]));
        $score = max($ratio, $overlap);
        if (str_starts_with($candidate, $query) || str_starts_with($query, $candidate)) {
            $score = max($score, 0.8);
        }
        if ($queryWords[0] === $candidateWords[0]) {
            $score = min(1.0, $score + 0.15);
        } else {
            $score -= 0.15;
        }

        return $score;
    }

    private static function matched(ApVendor $vendor, string $method): ApVendorMatch
    {
        return new ApVendorMatch(
            $vendor->odooId,
            $vendor->ref !== '' ? $vendor->ref : null,
            $vendor->name !== '' ? $vendor->name : null,
            ApVendorMatch::MATCHED,
            'Matched automatically by ' . $method,
        );
    }

    /**
     * @return list<string> the addresses in an email field (separated by semicolons, commas or space), lower case
     */
    private static function emails(string $value): array
    {
        $found = [];
        foreach (self::split('/[;,' . self::SPACE . ']+/u', $value) as $part) {
            if (str_contains($part, '@')) {
                $address = self::lower(self::strip($part));
                if (!in_array($address, $found, true)) {
                    $found[] = $address;
                }
            }
        }

        return $found;
    }

    private static function domain(string $address): string
    {
        if (!str_contains($address, '@')) {
            return '';
        }

        return self::lower(self::strip(substr($address, (int) strrpos($address, '@') + 1)));
    }

    private static function senderAddress(?string $sender): string
    {
        if (preg_match("/[\\w.+'-]+@[\\w-]+(?:\\.[\\w-]+)+/u", self::text($sender), $found) !== 1) {
            return '';
        }

        return self::lower($found[0]);
    }

    /** Python's `a or b or ""` over two optional strings. */
    private static function firstNonEmpty(?string $first, ?string $second): string
    {
        return $first !== null && $first !== '' ? $first : ($second ?? '');
    }

    /** `str(value or "")` for a string that may be missing; invalid UTF-8 (which Python would have refused) is repaired. */
    private static function text(?string $value): string
    {
        return mb_scrub($value ?? '', 'UTF-8');
    }

    private static function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    /** Python's `str.strip()`. */
    private static function strip(string $value): string
    {
        return self::replace('/^[' . self::SPACE . ']+|[' . self::SPACE . ']+$/u', '', $value);
    }

    /** `round(x, 2)` as Python does it: the nearest two-place decimal to the exact binary value, ties to even. */
    private static function round2(float $value): float
    {
        return (float) sprintf('%.2f', $value);
    }

    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        return preg_replace($pattern, $replacement, $subject) ?? '';
    }

    /**
     * @return list<string>
     */
    private static function split(string $pattern, string $subject): array
    {
        $parts = preg_split($pattern, $subject);

        return $parts === false ? [$subject] : $parts;
    }
}
