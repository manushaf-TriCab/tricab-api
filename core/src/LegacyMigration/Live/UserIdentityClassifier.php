<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Live;

use TriCab\Core\LegacyMigration\Support\UserClassification;

final class UserIdentityClassifier
{
    /**
     * @param list<string> $roles
     * @param list<array<string, mixed>> $contactMatches
     * @return array{primary: string, tags: list<string>}
     */
    public function classify(
        string $login,
        string $email,
        bool $employeeMatch,
        array $contactMatches,
        array $roles,
        bool $hasAliFlex,
        int $legacyStatus,
        bool $duplicateEmail,
        bool $ambiguousContacts,
    ): array {
        $tags = [];
        $loginHasAt = str_contains($login, '@');
        $emailLower = strtolower($email);
        $tricabEmail = str_ends_with($emailLower, '@tricab.com');
        $customerRole = in_array('customer', $roles, true)
            || in_array('electrician', $roles, true)
            || in_array('distributor', $roles, true);

        if ($legacyStatus !== 0) {
            $tags[] = UserClassification::INACTIVE;
        }
        if ($duplicateEmail) {
            $tags[] = UserClassification::DUPLICATE;
        }
        if ($ambiguousContacts) {
            $tags[] = UserClassification::AMBIGUOUS;
            $tags[] = UserClassification::REQUIRES_REVIEW;
        }
        if ($hasAliFlex) {
            $tags[] = UserClassification::ALI_FLEX_USER;
        }
        if ($contactMatches !== []) {
            $tags[] = UserClassification::CUSTOMER_CONTACT;
        }

        // @tricab.com corporate email is the signal for microsoft_entra staff authentication.
        $staffMicrosoft = !$loginHasAt && $tricabEmail;
        $staffOther = !$loginHasAt && !$staffMicrosoft;
        if ($staffMicrosoft) {
            $primary = UserClassification::STAFF_MICROSOFT;
            $tags[] = UserClassification::STAFF_MICROSOFT;
        } elseif ($staffOther) {
            $primary = UserClassification::STAFF_OTHER;
            $tags[] = UserClassification::STAFF_OTHER;
            $tags[] = UserClassification::REQUIRES_REVIEW;
        } elseif ($loginHasAt || $customerRole || $contactMatches !== []) {
            $primary = UserClassification::CUSTOMER;
            $tags[] = UserClassification::CUSTOMER;
        } elseif ($hasAliFlex) {
            $primary = UserClassification::ALI_FLEX_USER;
        } else {
            $primary = UserClassification::LEGACY_ONLY;
            $tags[] = UserClassification::LEGACY_ONLY;
            $tags[] = UserClassification::REQUIRES_REVIEW;
        }

        return ['primary' => $primary, 'tags' => array_values(array_unique($tags))];
    }
}
