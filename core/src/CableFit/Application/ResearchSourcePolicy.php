<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Application;

/**
 * Whether a URL belongs to an enabled source: an http(s) URL whose host is a source domain or a subdomain of
 * one. `iec.ch` allows `www.iec.ch` and `webstore.iec.ch`, not `iec.ch.evil.com` and not `eviliec.ch`.
 */
final class ResearchSourcePolicy
{
    /**
     * @param list<string> $domains lowercase bare hostnames
     */
    public static function allows(string $url, array $domains): bool
    {
        $host = self::host($url);
        if ($host === null) {
            return false;
        }
        foreach ($domains as $domain) {
            $domain = strtolower(trim($domain, ". \t"));
            if ($domain !== '' && ($host === $domain || str_ends_with($host, '.' . $domain))) {
                return true;
            }
        }

        return false;
    }

    /** The lowercase host of an http(s) URL, or null. */
    public static function host(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            return null;
        }

        return rtrim(strtolower($host), '.');
    }

    /**
     * A comparison key for matching a model's URL to a search result's: host without a leading "www.",
     * path without a trailing slash, query kept, fragment dropped.
     */
    public static function key(string $url): ?string
    {
        $parts = parse_url(trim($url));
        $host = self::host($url);
        if (!is_array($parts) || $host === null) {
            return null;
        }
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        $path = rtrim($parts['path'] ?? '', '/');

        return $host . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
