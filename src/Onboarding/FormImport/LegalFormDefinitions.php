<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\FormImport;

use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;

/**
 * The WHS Medical Questionnaire (WHSE-FOR-007-V005) and the Deed of
 * Confidentiality and Restraint of Trade (TRI-DOC-HR-010-V003), restated in the
 * platform's form format (5.4) from the source's `legal-forms.ts` and
 * `OnboardingForm.jsx`: same questions, same wording.
 *
 * The medical questionnaire is a restricted form — health information, every
 * field C4. The deed is an ordinary HR form, and its witness signs on the same
 * form, on the same device, as in the source.
 *
 * Not carried over: the source's conditional rule that a "Yes" needs details.
 * The engine has no conditional rules yet; the hint says it, and HR reviews it.
 */
final class LegalFormDefinitions
{
    public const MEDICAL_SLUG = 'whs-medical-questionnaire';
    public const DEED_SLUG = 'deed-of-confidentiality';

    /** path => question, in the sheet's order */
    public const MEDICAL_QUESTIONS = [
        'timeOffWork' => 'Do you suffer, or have you suffered, from any illness, disease or injury which has resulted in time off work?',
        'jobRestrictions' => 'Do any health problems restrict your ability to perform your job role?',
        'treatmentOrMedication' => 'Are you currently on any treatment program and/or medication for illness or injury?',
        'specialRequirements' => 'Have you ever been advised by a registered medical practitioner that you have special requirements, such as an ergonomic chair or anti-glare screen?',
        'otherSupportCondition' => 'Is there any condition which you believe TriCab should be aware of to better support your safety at work, such as mental illness, epilepsy, diabetes, asthma or a pre-existing injury?',
    ];

    /**
     * @return list<array{slug: string, title: string, accessGroup: string, fields: list<array<string, mixed>>}>
     */
    public static function definitions(): array
    {
        return [self::medical(), self::deed()];
    }

    /** @return array{slug: string, title: string, accessGroup: string, fields: list<array<string, mixed>>} */
    public static function medical(): array
    {
        $c4 = DataClass::RESTRICTED_HR;
        $yesNo = [['key' => 'yes', 'label' => 'Yes'], ['key' => 'no', 'label' => 'No']];
        $fields = [
            self::f('fullName', 'Full name', 'text', $c4, true),
            self::f('address', 'Address', 'text', $c4, true),
            self::f('contactNumber', 'Contact number', 'text', $c4, true),
        ];
        foreach (self::MEDICAL_QUESTIONS as $path => $question) {
            $fields[] = self::f("{$path}.answer", $question, 'select', $c4, true, null, $yesNo);
            $fields[] = self::f("{$path}.details", 'Details', 'textarea', $c4, false, 'Required if you answered Yes.');
        }
        $fields[] = self::f('declarationAccepted', 'I confirm that the information I have provided is accurate and complete.', 'checkbox', $c4, true);
        $fields[] = self::f('signature', 'Signature', 'signature', $c4, true);
        $fields[] = self::f('signedDate', 'Date', 'date', $c4, true);

        return ['slug' => self::MEDICAL_SLUG, 'title' => 'WHS Medical Questionnaire', 'accessGroup' => 'restricted_hr', 'fields' => $fields];
    }

    /** @return array{slug: string, title: string, accessGroup: string, fields: list<array<string, mixed>>} */
    public static function deed(): array
    {
        $c2 = DataClass::ORDINARY_HR;

        return ['slug' => self::DEED_SLUG, 'title' => 'Deed of Confidentiality and Restraint of Trade', 'accessGroup' => 'hr', 'fields' => [
            self::f('deedDate', 'Date of deed', 'date', $c2, true),
            self::f('fullName', 'Full legal name', 'text', $c2, true),
            self::f('address', 'Address', 'text', $c2, true),
            self::f('employeeSignature', 'Employee signature', 'signature', $c2, true, 'Sign in the physical presence of the witness named below.'),
            self::f('employeeSignedDate', 'Date signed', 'date', $c2, true),
            self::f('witnessName', 'Witness full name', 'text', $c2, true),
            self::f('witnessSignature', 'Witness signature', 'signature', $c2, true, 'The witness signs here, on this device.'),
            self::f('declarationAccepted', 'I confirm that I am signing and delivering this deed in the presence of the witness named above.', 'checkbox', $c2, true),
        ]];
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
