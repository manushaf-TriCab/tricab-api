<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Plugin;

use TriCab\Core\LegacyMigration\Contract\ExistingNormalizedEmailLookup;
use TriCab\Core\LegacyMigration\Contract\Validator;
use TriCab\Core\LegacyMigration\Support\NormalizedRecord;
use TriCab\Core\LegacyMigration\Support\ValidationFinding;

final class UserEmailValidator implements Validator
{
    public function __construct(
        private readonly ExistingNormalizedEmailLookup $existing = new NullExistingNormalizedEmailLookup(),
    ) {
    }

    public function validate(array $records): array
    {
        $findings = [];
        /** @var array<string, list<string>> $byEmail */
        $byEmail = [];
        $normalized = [];
        foreach ($records as $record) {
            if (!$record instanceof NormalizedRecord || str_starts_with($record->sourceIdentifier, '__')) {
                continue;
            }
            $email = trim((string) ($record->canonical['email'] ?? ''));
            $normalizedEmail = strtolower(trim((string) ($record->canonical['normalizedEmail'] ?? $email)));
            if ($email === '') {
                $findings[] = new ValidationFinding(
                    severity: 'error',
                    errorCode: 'MISSING_EMAIL',
                    description: 'WordPress user has no email address.',
                    sourceIdentifier: $record->sourceIdentifier,
                    recommendation: 'Supply an email or skip the account.',
                    isConflict: true,
                );
                continue;
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $findings[] = new ValidationFinding(
                    severity: 'error',
                    errorCode: 'INVALID_EMAIL',
                    description: 'Email address is not valid.',
                    sourceIdentifier: $record->sourceIdentifier,
                    recommendation: 'Correct the legacy email before migration.',
                    isConflict: true,
                );
                continue;
            }
            $byEmail[$normalizedEmail][] = $record->sourceIdentifier;
            $normalized[] = $normalizedEmail;

            $matches = is_array($record->canonical['contactMatches'] ?? null) ? $record->canonical['contactMatches'] : [];
            $emailMatches = array_values(array_filter(
                $matches,
                static fn (mixed $m): bool => is_array($m) && ($m['matchType'] ?? '') === 'MULTIPLE_EMAIL_MATCH',
            ));
            if ($emailMatches !== []) {
                $findings[] = new ValidationFinding(
                    severity: 'error',
                    errorCode: 'MULTIPLE_CONTACT_EMAIL_MATCH',
                    description: 'More than one tc.contacts row shares this email.',
                    sourceIdentifier: $record->sourceIdentifier,
                    recommendation: 'Resolve which contact should link to this user. Do not attach multiple customers automatically.',
                    isConflict: true,
                );
            }
        }

        foreach ($byEmail as $email => $ids) {
            if (count($ids) < 2) {
                continue;
            }
            foreach ($ids as $id) {
                $findings[] = new ValidationFinding(
                    severity: 'error',
                    errorCode: 'DUPLICATE_NORMALIZED_EMAIL',
                    description: sprintf('Normalized email "%s" is shared by WordPress users %s.', $email, implode(', ', $ids)),
                    sourceIdentifier: $id,
                    recommendation: 'Never silently pick one account. Ignore, merge, or map manually.',
                    isConflict: true,
                );
            }
        }

        $existing = $this->existing->existingNormalizedEmails(array_values(array_unique($normalized)));
        $existingSet = array_fill_keys($existing, true);
        foreach ($records as $record) {
            if (!$record instanceof NormalizedRecord) {
                continue;
            }
            $email = strtolower(trim((string) ($record->canonical['normalizedEmail'] ?? $record->canonical['email'] ?? '')));
            if ($email !== '' && isset($existingSet[$email])) {
                $findings[] = new ValidationFinding(
                    severity: 'error',
                    errorCode: 'EMAIL_ALREADY_EXISTS_IN_NEW_PLATFORM',
                    description: 'Normalized email already exists on identity.Users.',
                    sourceIdentifier: $record->sourceIdentifier,
                    recommendation: 'Use existing, create new only after conflict resolution, or map manually.',
                    isConflict: true,
                );
            }
        }

        return $findings;
    }
}
