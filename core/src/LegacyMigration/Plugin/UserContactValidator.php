<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Plugin;

use TriCab\Core\LegacyMigration\Contract\Validator;
use TriCab\Core\LegacyMigration\Support\NormalizedRecord;
use TriCab\Core\LegacyMigration\Support\ValidationFinding;

final class UserContactValidator implements Validator
{
    public function validate(array $records): array
    {
        $findings = [];
        $wpIds = [];
        foreach ($records as $record) {
            if (!$record instanceof NormalizedRecord || str_starts_with($record->sourceIdentifier, '__')) {
                continue;
            }
            $wpId = (int) ($record->canonical['legacyWordPressUserId'] ?? $record->sourceIdentifier);
            $wpIds[$wpId] = true;
            $matches = is_array($record->canonical['contactMatches'] ?? null) ? $record->canonical['contactMatches'] : [];
            $types = [];
            foreach ($matches as $match) {
                if (is_array($match) && isset($match['matchType'])) {
                    $types[] = (string) $match['matchType'];
                }
            }
            $real = array_values(array_filter($types, static fn (string $t): bool => $t !== 'NO_CONTACT'));
            $explicit = in_array('EXPLICIT_WP_ID', $real, true);
            $emailHits = array_values(array_filter(
                $matches,
                static fn (mixed $m): bool => is_array($m) && in_array((string) ($m['matchType'] ?? ''), ['UNIQUE_EMAIL', 'MULTIPLE_EMAIL_MATCH'], true),
            ));

            if ($real === []) {
                $findings[] = new ValidationFinding(
                    severity: 'warning',
                    errorCode: 'WP_USER_WITH_NO_CONTACT',
                    description: 'WordPress user has no tc.customer_contact row and no unique contacts.email match.',
                    sourceIdentifier: $record->sourceIdentifier,
                    recommendation: 'Treat as identity-only until a contact is mapped.',
                );
            } elseif (count($real) === 1) {
                $findings[] = new ValidationFinding(
                    severity: 'info',
                    errorCode: 'WP_USER_WITH_ONE_CONTACT',
                    description: 'Single contact/customer candidate.',
                    sourceIdentifier: $record->sourceIdentifier,
                    recommendation: 'Confirm the candidate before production linking.',
                );
            } else {
                $findings[] = new ValidationFinding(
                    severity: 'error',
                    errorCode: 'WP_USER_WITH_MULTIPLE_CONTACTS',
                    description: 'Ambiguous contact/customer candidates. Do not attach the user to multiple customers.',
                    sourceIdentifier: $record->sourceIdentifier,
                    recommendation: 'Resolve with LINK_CONTACT or MANUAL_MAPPING.',
                    isConflict: true,
                );
            }

            if ($explicit) {
                $findings[] = new ValidationFinding(
                    severity: 'info',
                    errorCode: 'CUSTOMER_CONTACT_WP_ID_MATCH',
                    description: 'tc.customer_contact.contact_id equals this WordPress user ID.',
                    sourceIdentifier: $record->sourceIdentifier,
                    recommendation: 'This is the strongest customer-portal link from the audit.',
                );
            }

            if ($explicit && $emailHits !== []) {
                $wpEmail = strtolower(trim((string) ($record->canonical['normalizedEmail'] ?? '')));
                foreach ($emailHits as $hit) {
                    $contactEmail = strtolower(trim((string) ($hit['email'] ?? '')));
                    if ($contactEmail !== '' && $wpEmail !== '' && $contactEmail !== $wpEmail) {
                        $findings[] = new ValidationFinding(
                            severity: 'error',
                            errorCode: 'CUSTOMER_CONTACT_EMAIL_MISMATCH',
                            description: 'Portal WP ID match exists but contacts.email does not match the WordPress email.',
                            sourceIdentifier: $record->sourceIdentifier,
                            recommendation: 'Do not auto-merge. Record a conflict.',
                            isConflict: true,
                        );
                    }
                }
            }

            $customers = [];
            foreach ($emailHits as $hit) {
                $customerId = $hit['legacyCustomerId'] ?? null;
                if ($customerId !== null && $customerId !== '') {
                    $customers[(string) $customerId] = true;
                }
            }
            if (count($customers) > 1) {
                $findings[] = new ValidationFinding(
                    severity: 'error',
                    errorCode: 'MULTIPLE_CUSTOMERS_FOR_EMAIL',
                    description: 'Email matches contacts on more than one customer.',
                    sourceIdentifier: $record->sourceIdentifier,
                    recommendation: 'Do not attach a user automatically to multiple customers.',
                    isConflict: true,
                );
            }
        }

        $orphanContacts = [];
        foreach ($records as $record) {
            if (!$record instanceof NormalizedRecord) {
                continue;
            }
            $list = $record->canonical['unmatchedCustomerContactWpIds'] ?? [];
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $id) {
                $wpId = (int) $id;
                if ($wpId > 0 && !isset($wpIds[$wpId])) {
                    $orphanContacts[$wpId] = true;
                }
            }
        }
        foreach ($orphanContacts as $wpId => $_) {
            $findings[] = new ValidationFinding(
                severity: 'warning',
                errorCode: 'CONTACT_WITH_NO_WP_USER',
                description: sprintf('tc.customer_contact.contact_id %d has no matching wp_users.ID.', $wpId),
                sourceIdentifier: 'customer_contact:' . $wpId,
                recommendation: 'Do not create a WordPress user automatically.',
            );
        }

        return $findings;
    }
}
