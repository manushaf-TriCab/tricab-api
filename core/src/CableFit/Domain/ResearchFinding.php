<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Domain;

use DateTimeImmutable;

/**
 * Row of `[cablefit].research_findings`: one claim a run found, with its source. `publishedOn` is the
 * date as known, `YYYY`, `YYYY-MM` or `YYYY-MM-DD`.
 */
final readonly class ResearchFinding
{
    public const BASIS_PAGE_AGE = 'page_age';
    public const BASIS_STATED = 'stated_on_page';
    public const BASIS_UNDATED = 'undated';

    public const CONFIDENCE_HIGH = 'high';
    public const CONFIDENCE_MEDIUM = 'medium';
    public const CONFIDENCE_LOW = 'low';

    public const STANCE_SUPPORTS = 'supports';
    public const STANCE_CONCERN = 'concern';
    public const STANCE_NEUTRAL = 'neutral';

    public const STANCES = [self::STANCE_SUPPORTS, self::STANCE_CONCERN, self::STANCE_NEUTRAL];

    /** The recent window, in months before the run was requested. */
    public const RECENT_MONTHS = 24;

    public function __construct(
        public string $publicId,
        public string $parameter,
        public string $claim,
        public string $relevance,
        public string $url,
        public string $title,
        public ?string $publisher,
        public ?string $publishedOn,
        public string $dateBasis,
        public string $confidence,
        public int $sortOrder,
        /** For this design in this application; null on runs from before C4. */
        public ?string $stance = null,
    ) {
    }

    public function withSortOrder(int $sortOrder): self
    {
        return new self(
            $this->publicId,
            $this->parameter,
            $this->claim,
            $this->relevance,
            $this->url,
            $this->title,
            $this->publisher,
            $this->publishedOn,
            $this->dateBasis,
            $this->confidence,
            $sortOrder,
            $this->stance,
        );
    }

    /**
     * Whether the source is dated and no more than 24 months before $requestedAt. A partial date counts
     * from the first day of its month or year, so a bare "2024" is recent only if all of 2024 is.
     * Undated findings are never recent.
     */
    public function withinRecentWindow(DateTimeImmutable $requestedAt): bool
    {
        if ($this->publishedOn === null || preg_match('/^(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?$/', $this->publishedOn, $m) !== 1) {
            return false;
        }
        $month = isset($m[2]) ? (int) $m[2] : 1;
        $day = isset($m[3]) ? (int) $m[3] : 1;
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return false;
        }
        $published = $requestedAt->setTimezone(new \DateTimeZone('UTC'))->setDate((int) $m[1], $month, $day)->setTime(0, 0, 0);
        $cutoff = $requestedAt->setTimezone(new \DateTimeZone('UTC'))->modify('-' . self::RECENT_MONTHS . ' months')->setTime(0, 0, 0);

        return $published >= $cutoff;
    }
}
