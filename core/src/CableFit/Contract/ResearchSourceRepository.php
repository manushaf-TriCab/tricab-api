<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Contract;

use TriCab\Core\CableFit\Domain\ResearchSource;
use TriCab\Core\CableFit\Domain\ResearchSourceExists;

/**
 * Persistence for `[cablefit].research_sources`.
 */
interface ResearchSourceRepository
{
    /**
     * Every source, by category then domain.
     *
     * @return list<ResearchSource>
     */
    public function all(): array;

    public function findByPublicId(string $publicId): ?ResearchSource;

    public function findByDomain(string $domain): ?ResearchSource;

    /**
     * @throws ResearchSourceExists when the domain is already taken
     */
    public function add(ResearchSource $source, int $userId): void;

    /** Replace the editable fields of an existing source. */
    public function save(ResearchSource $source, int $userId): void;

    /**
     * Domains of the enabled sources, sorted.
     *
     * @return list<string>
     */
    public function enabledDomains(): array;
}
