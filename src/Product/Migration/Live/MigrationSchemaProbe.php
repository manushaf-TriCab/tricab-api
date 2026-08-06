<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Live;

interface MigrationSchemaProbe
{
    /**
     * @return array{
     *   applied: bool,
     *   missingTables: list<string>,
     *   checkedTables: list<string>,
     *   persistenceMode?: string
     * }
     */
    public function probe(): array;
}
