<?php

declare(strict_types=1);

namespace TriCab\Core\Conductor\Process\Application\Query;

/**
 * A validated filter for the conductor item list. Null means "not given". {@see $stages} is already expanded
 * ('finished' becomes conductor and mica_taped); null means every stage.
 */
final readonly class ItemsFilter
{
    /**
     * @param list<string>|null $stages
     */
    public function __construct(
        public ?string $q,
        public ?array $stages,
        public ?string $material,
        public int $page,
        public int $pageSize,
    ) {
    }
}
