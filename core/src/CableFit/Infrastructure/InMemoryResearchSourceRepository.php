<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Infrastructure;

use TriCab\Core\CableFit\Contract\ResearchSourceRepository;
use TriCab\Core\CableFit\Domain\ResearchSource;
use TriCab\Core\CableFit\Domain\ResearchSourceExists;

final class InMemoryResearchSourceRepository implements ResearchSourceRepository
{
    /** @var array<string, ResearchSource> by public id */
    private array $sources = [];

    public function all(): array
    {
        $list = array_values($this->sources);
        usort($list, static fn (ResearchSource $a, ResearchSource $b): int => [$a->category, $a->domain] <=> [$b->category, $b->domain]);

        return $list;
    }

    public function findByPublicId(string $publicId): ?ResearchSource
    {
        return $this->sources[$publicId] ?? null;
    }

    public function findByDomain(string $domain): ?ResearchSource
    {
        foreach ($this->sources as $source) {
            if ($source->domain === $domain) {
                return $source;
            }
        }

        return null;
    }

    public function add(ResearchSource $source, int $userId): void
    {
        if ($this->findByDomain($source->domain) !== null) {
            throw new ResearchSourceExists('A source for ' . $source->domain . ' already exists.');
        }
        $this->sources[$source->publicId] = $source;
    }

    public function save(ResearchSource $source, int $userId): void
    {
        $this->sources[$source->publicId] = $source;
    }

    public function enabledDomains(): array
    {
        $domains = [];
        foreach ($this->sources as $source) {
            if ($source->enabled) {
                $domains[] = $source->domain;
            }
        }
        sort($domains);

        return $domains;
    }
}
