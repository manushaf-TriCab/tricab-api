<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\LegacyMigration;

use PHPUnit\Framework\TestCase;
use TriCab\Core\LegacyMigration\Plugin\NullExistingNormalizedEmailLookup;
use TriCab\Core\LegacyMigration\Plugin\UserContactValidator;
use TriCab\Core\LegacyMigration\Plugin\UserEmailValidator;
use TriCab\Core\LegacyMigration\Plugin\UserEntraValidator;
use TriCab\Core\LegacyMigration\Plugin\UserRoleValidator;
use TriCab\Core\LegacyMigration\Plugin\UserStaffValidator;
use TriCab\Core\LegacyMigration\Support\NormalizedRecord;

final class UserValidatorsTest extends TestCase
{
    public function test_email_staff_contact_role_and_entra_codes(): void
    {
        $records = [
            new NormalizedRecord('1', 'users', [
                'email' => '',
                'normalizedEmail' => '',
                'legacyLogin' => 'staff1',
                'classification' => 'STAFF_MICROSOFT',
                'classificationTags' => ['STAFF_MICROSOFT'],
                'employeeMatch' => null,
                'contactMatches' => [['matchType' => 'NO_CONTACT']],
                'unknownRoles' => ['customrole'],
                'entraLinkRequired' => true,
            ], [], 'h1'),
            new NormalizedRecord('2', 'users', [
                'email' => 'dup@example.com',
                'normalizedEmail' => 'dup@example.com',
                'legacyLogin' => 'dup1',
                'classification' => 'CUSTOMER',
                'contactMatches' => [
                    ['matchType' => 'UNIQUE_EMAIL', 'legacyCustomerId' => 1],
                    ['matchType' => 'EXPLICIT_WP_ID'],
                ],
            ], [], 'h2'),
            new NormalizedRecord('3', 'users', [
                'email' => 'dup@example.com',
                'normalizedEmail' => 'dup@example.com',
                'legacyLogin' => 'dup2',
                'classification' => 'CUSTOMER',
                'contactMatches' => [['matchType' => 'MULTIPLE_EMAIL_MATCH', 'legacyCustomerId' => 2]],
            ], [], 'h3'),
            new NormalizedRecord('__inventory__', 'users', [
                'unmatchedEmployeeLogins' => ['ghost'],
                'unmatchedCustomerContactWpIds' => [99],
            ], [], 'h4'),
        ];

        $emailCodes = array_map(static fn ($f) => $f->errorCode, (new UserEmailValidator(new NullExistingNormalizedEmailLookup()))->validate($records));
        self::assertContains('MISSING_EMAIL', $emailCodes);
        self::assertContains('DUPLICATE_NORMALIZED_EMAIL', $emailCodes);
        self::assertContains('MULTIPLE_CONTACT_EMAIL_MATCH', $emailCodes);

        $staffCodes = array_map(static fn ($f) => $f->errorCode, (new UserStaffValidator())->validate($records));
        self::assertContains('STAFF_WITHOUT_EMPLOYEE_MATCH', $staffCodes);
        self::assertContains('EMPLOYEE_WITHOUT_WP_USER', $staffCodes);

        $contactCodes = array_map(static fn ($f) => $f->errorCode, (new UserContactValidator())->validate($records));
        self::assertContains('WP_USER_WITH_NO_CONTACT', $contactCodes);
        self::assertContains('WP_USER_WITH_MULTIPLE_CONTACTS', $contactCodes);
        self::assertContains('CONTACT_WITH_NO_WP_USER', $contactCodes);

        $roleCodes = array_map(static fn ($f) => $f->errorCode, (new UserRoleValidator())->validate($records));
        self::assertContains('UNKNOWN_ROLE', $roleCodes);

        $entraCodes = array_map(static fn ($f) => $f->errorCode, (new UserEntraValidator())->validate($records));
        self::assertContains('ENTRA_LINK_REQUIRED', $entraCodes);
    }
}
