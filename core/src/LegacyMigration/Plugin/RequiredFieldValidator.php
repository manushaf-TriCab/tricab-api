<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Plugin;

use TriCab\Core\LegacyMigration\Contract\Validator;
use TriCab\Core\LegacyMigration\Support\NormalizedRecord;
use TriCab\Core\LegacyMigration\Support\ValidationFinding;

final class RequiredFieldValidator implements Validator
{
    /**
     * @param list<string> $required
     */
    public function __construct(private readonly array $required)
    {
    }

    public function validate(array $records): array
    {
        $findings = [];
        foreach ($records as $record) {
            if (!$record instanceof NormalizedRecord) {
                continue;
            }
            if (str_starts_with($record->sourceIdentifier, '__')) {
                continue;
            }
            foreach ($this->required as $field) {
                $value = $record->canonical[$field] ?? null;
                if ($value === null || $value === '') {
                    $findings[] = new ValidationFinding(
                        severity: 'error',
                        errorCode: 'MISSING_' . strtoupper($field),
                        description: sprintf('Missing required field "%s".', $field),
                        sourceIdentifier: $record->sourceIdentifier,
                        recommendation: 'Supply the field in the legacy source or skip the record.',
                    );
                }
            }
        }

        return $findings;
    }
}
