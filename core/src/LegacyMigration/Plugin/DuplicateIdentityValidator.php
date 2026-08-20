<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Plugin;

use TriCab\Core\LegacyMigration\Contract\Validator;
use TriCab\Core\LegacyMigration\Support\NormalizedRecord;
use TriCab\Core\LegacyMigration\Support\ValidationFinding;

final class DuplicateIdentityValidator implements Validator
{
    public function __construct(private readonly string $field = 'email')
    {
    }

    public function validate(array $records): array
    {
        /** @var array<string, list<string>> $byValue */
        $byValue = [];
        foreach ($records as $record) {
            if (!$record instanceof NormalizedRecord) {
                continue;
            }
            $value = $record->canonical[$this->field] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $key = strtolower(trim($value));
            $byValue[$key][] = $record->sourceIdentifier;
        }

        $findings = [];
        foreach ($byValue as $value => $ids) {
            if (count($ids) < 2) {
                continue;
            }
            $findings[] = new ValidationFinding(
                severity: 'error',
                errorCode: 'DUPLICATE_' . strtoupper($this->field),
                description: sprintf('Shared %s "%s" on records %s.', $this->field, $value, implode(', ', $ids)),
                sourceIdentifier: $ids[0],
                recommendation: 'Ignore, merge, create new, replace existing, or map manually.',
                isConflict: true,
            );
        }

        return $findings;
    }
}
