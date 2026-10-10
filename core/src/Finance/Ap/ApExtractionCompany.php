<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap;

/**
 * One row of Tri-AP's companies list, as extraction reads it: the TriCab entities an invoice can be billed to.
 *
 * Two jobs, both the source's (`server/app.py`):
 *
 * - {@see self::promptList()} is `_active_company_names()`: the active companies with a name, each with its sites and
 *   former names, which the extraction prompt names so the model can choose one.
 * - {@see self::match()} is `_match_company()`: what the model wrote is never trusted. It is mapped to a configured
 *   company by name or code, or by a single containment match once punctuation and spacing are ignored, and is
 *   otherwise dropped so a person picks. Sites and former names are for the prompt only; the source did not match on them.
 */
final readonly class ApExtractionCompany
{
    public function __construct(
        public string $name,
        public ?string $code = null,
        public string $sites = '',
        public string $formerNames = '',
        public bool $active = true,
    ) {
    }

    /**
     * `_active_company_names()`: the `companies` the service's prompt takes.
     *
     * @param list<self> $companies
     * @return list<array{name: string, sites: string, former_names: string}>
     */
    public static function promptList(array $companies): array
    {
        $out = [];
        foreach ($companies as $company) {
            if ($company->active && trim($company->name) !== '') {
                $out[] = ['name' => $company->name, 'sites' => $company->sites, 'former_names' => $company->formerNames];
            }
        }

        return $out;
    }

    /**
     * `_match_company()`. Returns the company's name, or null when the value is empty, unknown or ambiguous.
     *
     * @param list<self> $companies every row of the companies list; inactive ones are skipped here as in the source
     */
    public static function match(?string $extracted, array $companies): ?string
    {
        $candidate = mb_strtolower(self::collapse($extracted ?? ''));
        if ($candidate === '') {
            return null;
        }
        $active = array_values(array_filter($companies, static fn (self $c): bool => $c->active));
        foreach ($active as $company) {
            $name = self::collapse($company->name);
            $code = trim($company->code ?? '');
            if ($candidate === mb_strtolower($name) || ($code !== '' && $candidate === mb_strtolower($code))) {
                return $name;
            }
        }
        // Tolerate "PTY LTD" / punctuation drift, but only when exactly one entity is a containment match: two
        // matches means we cannot tell them apart. (A company with an empty name would match everything, as in the source.)
        $target = self::squeeze($candidate);
        $hits = [];
        foreach ($active as $company) {
            $squeezed = self::squeeze($company->name);
            if ($target !== '' && (str_contains($target, $squeezed) || str_contains($squeezed, $target))) {
                $hits[] = $company->name;
            }
        }

        return count($hits) === 1 ? $hits[0] : null;
    }

    /** `" ".join(value.split()).strip()`. */
    private static function collapse(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** Lower-case letters and digits only. */
    private static function squeeze(string $value): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($value));
    }
}
