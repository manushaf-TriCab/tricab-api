<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Migration;

use TriCab\Core\Finance\Ar\Domain\ArRecord;

/**
 * What one record needs written, and written together (T5.15): the record itself unless the target already has it,
 * and each of its attachment rows (with their lines) that the target does not have yet.
 */
final readonly class ArMigrationUnit
{
    /**
     * @param string $sourceId the record's id in the source, for the report
     * @param list<array{attachment: ArMigratedAttachment, lines: list<ArMigratedLine>}> $attachments
     */
    public function __construct(
        public string $sourceId,
        public ArRecord $record,
        public bool $recordPresent,
        public array $attachments,
    ) {
    }
}
