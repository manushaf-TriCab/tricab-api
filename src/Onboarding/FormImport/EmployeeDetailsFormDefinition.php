<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\FormImport;

use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;

/**
 * HR-FOR-004-V007, the Employee Details Form, restated in the platform's form
 * format (5.4). Ported from the source's `employee-details.ts` and
 * `EmployeeDetailsForm.jsx`: same questions, same wording, same order.
 *
 * ## Field paths are the PDF mapper's contract
 *
 * {@see \TriCab\Api\Onboarding\Document\EmployeeDetailsPdfMapper} reads these
 * paths. Rename one here and the mapper stops drawing it, so the mapper's test
 * reads {@see paths()} and fails first.
 *
 * ## Classification, per architecture §5
 *
 *  - C3 payroll: date of birth, home address and postcode, both bank accounts,
 *    payslip email and PIN (the PIN kept knowingly — open question 6).
 *  - C4 restricted: Aboriginal or Torres Strait Islander status, cultural and
 *    gender identity, meal preferences, medical conditions, immigration,
 *    passport and visa, and the emergency contacts (third-party data).
 *  - C2 ordinary: names, phone numbers, and the declaration.
 *
 * ## What the platform does not check that the source did
 *
 * The form engine has required, number bounds and length. The source also
 * checked formats — a four-digit postcode and PIN, a six-digit BSB, phone
 * shapes, an email — and cross-field rules: a second bank account's fields
 * required once any is filled, allocations adding to 100%, "Other" immigration
 * status needing details. Those are left to HR's review until the engine gains
 * pattern and conditional rules. The hints tell the person what is expected.
 */
final class EmployeeDetailsFormDefinition
{
    public const SLUG = 'employee-details';
    public const TITLE = 'Employee Details Form';
    public const ACCESS_GROUP = 'payroll';
    public const DOCUMENT_REFERENCE = 'HR-FOR-004-V007';

    /**
     * @return list<array<string, mixed>>
     */
    public static function fields(): array
    {
        $c2 = DataClass::ORDINARY_HR;
        $c3 = DataClass::PAYROLL;
        $c4 = DataClass::RESTRICTED_HR;
        $yesNo = [['key' => 'yes', 'label' => 'Yes'], ['key' => 'no', 'label' => 'No'], ['key' => 'prefer-not-to-say', 'label' => 'Prefer not to say']];

        $fields = [
            self::f('surname', 'Surname', 'text', $c2, true),
            self::f('givenName', 'Given name', 'text', $c2, true),
            self::f('preferredName', 'Preferred name', 'text', $c2),
            self::f('dateOfBirth', 'Date of birth', 'date', $c3, true),
            self::f('address', 'Residential address', 'text', $c3, true),
            self::f('postcode', 'Postcode', 'text', $c3, true, 'Four digits.'),
            self::f('mobile', 'Mobile', 'text', $c2, true),
            self::f('homePhone', 'Home phone', 'text', $c2),

            self::f('aboriginalOrTorresStraitIslander', 'Do you identify as Aboriginal or Torres Strait Islander?', 'select', $c4, false, 'Optional.', $yesNo),
            self::f('culturalIdentity', 'Cultural identity', 'text', $c4, false, 'Optional.'),
            self::f('genderIdentity', 'Gender identity', 'select', $c4, false, 'Optional.', [
                ['key' => 'male', 'label' => 'Male'],
                ['key' => 'female', 'label' => 'Female'],
                ['key' => 'nonbinary', 'label' => 'Nonbinary'],
                ['key' => 'prefer-not-to-say', 'label' => 'Prefer not to say'],
            ]),
            self::f('meals.vegetarian', 'Meal preference: vegetarian', 'checkbox', $c4),
            self::f('meals.vegan', 'Meal preference: vegan', 'checkbox', $c4),
            self::f('meals.other', 'Meal preference: other', 'checkbox', $c4),
            self::f('meals.otherDetails', 'Other meal preference', 'text', $c4, false, 'Only if you ticked "other".'),
            self::f('medicalConditions', 'Medical conditions HR should know about', 'textarea', $c4, false, 'Optional. This information is handled as sensitive health information.'),

            self::f('immigrationStatus', 'Immigration status', 'select', $c4, true, 'Supporting identity or visa documents are provided separately.', [
                ['key' => 'australian_citizen', 'label' => 'Australian citizen'],
                ['key' => 'nz_citizen', 'label' => 'New Zealand citizen'],
                ['key' => 'permanent_resident', 'label' => 'Permanent resident'],
                ['key' => 'other', 'label' => 'Other visa/status'],
            ]),
            self::f('immigrationOtherDetails', 'Visa or immigration details', 'textarea', $c4, false, 'Required if you chose "Other visa/status".'),
            self::f('passportNumber', 'Passport number', 'text', $c4),
            self::f('visaNumberAndSubclass', 'Visa number and subclass', 'text', $c4),
            self::f('visaExpiry', 'Visa expiry', 'date', $c4),
        ];

        foreach ([1, 2] as $n) {
            $required = $n === 1;
            $prefix = sprintf('Bank account %d%s: ', $n, $required ? '' : ' (optional)');
            $fields[] = self::f("bank{$n}.bankName", $prefix . 'bank name', 'text', $c3, $required, $required ? 'Payroll-only information.' : 'Add a second account only when wages should be split.');
            $fields[] = self::f("bank{$n}.branch", $prefix . 'branch', 'text', $c3);
            $fields[] = self::f("bank{$n}.bsb", $prefix . 'BSB', 'text', $c3, $required, 'Six digits.');
            $fields[] = self::f("bank{$n}.accountNumber", $prefix . 'account number', 'text', $c3, $required);
            $fields[] = self::f("bank{$n}.accountName", $prefix . 'account name', 'text', $c3, $required);
            $fields[] = self::f("bank{$n}.allocationType", $prefix . 'allocation', 'select', $c3, false, 'Only when wages are split between two accounts.', [
                ['key' => 'percentage', 'label' => 'Percentage'],
                ['key' => 'amount', 'label' => 'Dollar amount'],
            ]);
            $fields[] = self::f("bank{$n}.allocationValue", $prefix . 'allocation value', 'text', $c3, false, 'A percentage (both accounts adding to 100) or a dollar amount.');
        }

        $fields[] = self::f('payslipEmail', 'Payslip email', 'text', $c3, true, 'A personal email address.');
        $fields[] = self::f('payslipPin', 'Payslip PIN', 'text', $c3, true, 'Four digits.');

        foreach ([1, 2] as $n) {
            $required = $n === 1;
            $prefix = sprintf('Emergency contact %d%s: ', $n, $required ? '' : ' (optional)');
            $fields[] = self::f("emergency{$n}.fullName", $prefix . 'full name', 'text', $c4, $required);
            $fields[] = self::f("emergency{$n}.relationship", $prefix . 'relationship', 'text', $c4, $required);
            $fields[] = self::f("emergency{$n}.phone", $prefix . 'phone', 'text', $c4, $required);
        }

        $fields[] = self::f(
            'declarationAccepted',
            'I certify that the information provided above is true and correct.',
            'checkbox',
            $c2,
            true,
            'Submitting while signed in records your acknowledgement and the time you handed it in.',
        );

        return $fields;
    }

    /** @return list<string> */
    public static function paths(): array
    {
        $paths = [];
        foreach (self::fields() as $field) {
            if (is_string($field['path'] ?? null)) {
                $paths[] = $field['path'];
            }
        }

        return $paths;
    }

    /**
     * @param list<array{key: string, label: string}>|null $options
     * @return array<string, mixed>
     */
    private static function f(string $path, string $label, string $type, int $class, bool $required = false, ?string $hint = null, ?array $options = null): array
    {
        $field = ['path' => $path, 'label' => $label, 'type' => $type, 'required' => $required, 'dataClass' => $class];
        if ($hint !== null) {
            $field['hint'] = $hint;
        }
        if ($options !== null) {
            $field['options'] = $options;
        }

        return $field;
    }
}
