<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use TriCab\Core\Finance\Ap2\Ai\Ap2SupplierRef;
use TriCab\Core\Finance\Ap2\Domain\Ap2Supplier;

/**
 * Which supplier sent a message, from the sender's address alone (no AI): first an exact address match against a
 * supplier's `email` or `cc`, then the sender's domain against the domain of a supplier's addresses (a sub-domain
 * counts). Free mail providers and our own domain never match by domain. A tie is no match: a person decides.
 */
final class Ap2SupplierMatcher
{
    /** Domains that say nothing about who the sender is. */
    private const SHARED_DOMAINS = [
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'yahoo.com', 'yahoo.com.au', 'icloud.com', 'me.com',
        'msn.com', 'bigpond.com', 'bigpond.net.au', 'optusnet.com.au', 'tricab.com', 'tricab.com.au',
    ];

    /**
     * @param list<Ap2Supplier> $suppliers
     */
    public static function match(string $from, array $suppliers): ?string
    {
        $address = self::address($from);
        if ($address === '') {
            return null;
        }
        $exact = [];
        foreach ($suppliers as $s) {
            if (in_array($address, array_merge(self::addresses($s->email), self::addresses($s->cc)), true)) {
                $exact[$s->id] = true;
            }
        }
        if (count($exact) === 1) {
            return (string) array_key_first($exact);
        }
        if ($exact !== []) {
            return null;
        }

        $domain = self::domainOf($address);
        if ($domain === '' || in_array($domain, self::SHARED_DOMAINS, true)) {
            return null;
        }
        $hits = [];
        foreach ($suppliers as $s) {
            foreach (array_merge(self::addresses($s->email), self::addresses($s->cc)) as $known) {
                $d = self::domainOf($known);
                if ($d !== '' && !in_array($d, self::SHARED_DOMAINS, true) && ($domain === $d || str_ends_with($domain, '.' . $d))) {
                    $hits[$s->id] = true;
                }
            }
        }

        return count($hits) === 1 ? (string) array_key_first($hits) : null;
    }

    /**
     * @param list<Ap2Supplier> $suppliers
     * @return list<Ap2SupplierRef>
     */
    public static function refs(array $suppliers): array
    {
        $out = [];
        foreach ($suppliers as $s) {
            $first = self::addresses($s->email)[0] ?? '';
            $out[] = new Ap2SupplierRef($s->id, $s->name, $first, self::domainOf($first));
        }

        return $out;
    }

    public static function ref(Ap2Supplier $s): Ap2SupplierRef
    {
        return self::refs([$s])[0];
    }

    /** The bare lower-case address of `Name <a@b.c>` or `a@b.c`; '' if there is none. */
    public static function address(string $value): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/<([^<>\s]+@[^<>\s]+)>/', $value, $m) === 1) {
            return $m[1];
        }

        return preg_match('/^[^\s@<>]+@[^\s@<>]+$/', $value) === 1 ? $value : '';
    }

    /**
     * @return list<string> the addresses in a list separated by commas or semicolons
     */
    public static function addresses(string $list): array
    {
        $out = [];
        foreach (preg_split('/[;,]/', $list) ?: [] as $one) {
            $a = self::address($one);
            if ($a !== '' && !in_array($a, $out, true)) {
                $out[] = $a;
            }
        }

        return $out;
    }

    public static function domainOf(string $address): string
    {
        $at = strrpos($address, '@');

        return $at === false ? '' : substr($address, $at + 1);
    }
}
