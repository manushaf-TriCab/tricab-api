<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Domain;

use DateTimeImmutable;

/**
 * Row of `[cablefit].research_sources`: a domain the research agent may search and cite. The domain is a
 * bare lowercase hostname and also covers its subdomains.
 */
final readonly class ResearchSource
{
    public const CATEGORY_STANDARDS_BODY = 'standards_body';
    public const CATEGORY_MANUFACTURER = 'manufacturer';
    public const CATEGORY_TECHNICAL_PUBLISHER = 'technical_publisher';
    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [
        self::CATEGORY_STANDARDS_BODY,
        self::CATEGORY_MANUFACTURER,
        self::CATEGORY_TECHNICAL_PUBLISHER,
        self::CATEGORY_OTHER,
    ];

    public function __construct(
        public string $publicId,
        public string $domain,
        public string $name,
        public string $category,
        public bool $enabled,
        public ?string $notes,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public function with(string $name, string $category, bool $enabled, ?string $notes, DateTimeImmutable $at): self
    {
        return new self($this->publicId, $this->domain, $name, $category, $enabled, $notes, $this->createdAt, $at);
    }
}
