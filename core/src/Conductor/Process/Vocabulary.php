<?php

declare(strict_types=1);

namespace TriCab\Core\Conductor\Process;

/**
 * The fixed lists the conductor process store and the API agree on (docs/conductor/CONTRACT.md, sections 3 and 5).
 */
final class Vocabulary
{
    public const SCHEMA_VERSION = 1;

    /** @var list<string> cd_item.stage, in production order (the order every list is sorted by). */
    public const STAGES = ['wire', 'bunch', 'conductor', 'mica_taped'];

    /** The items filter value that selects conductor and mica_taped together. */
    public const STAGE_FINISHED = 'finished';

    /** @var list<string> */
    public const FINISHED_STAGES = ['conductor', 'mica_taped'];

    /** @var list<string> */
    public const MATERIALS = ['plain', 'tinned', 'unknown'];

    public const ITEMS_DEFAULT_PAGE_SIZE = 50;
    public const ITEMS_MAX_PAGE_SIZE = 200;
    public const MACHINE_ITEMS_MAX = 500;
    public const DEFAULT_LENGTH = 1000;
    public const MAX_LENGTH = 10_000_000;
}
