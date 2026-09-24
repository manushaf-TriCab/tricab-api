<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\FormImport;

use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;

/**
 * The Vehicle Condition Report (HR-FOR-024-V002), from the source's
 * `vehicle.ts`. HR — the inspector — records the vehicle, its condition and up
 * to four photos; the new starter, as the authorised driver, records their
 * licence and acknowledges the handover. An `hr` form, all C2.
 *
 * The driver's name on the copy is the person's own; their position is
 * theirs to confirm, as the source let them.
 */
final class VehicleReportFormDefinition
{
    public const SLUG = 'vehicle-condition-report';
    public const TITLE = 'Vehicle Condition Report';

    /** @return array{slug: string, title: string, accessGroup: string, fields: list<array<string, mixed>>} */
    public static function definition(): array
    {
        $yesNo = [['key' => 'yes', 'label' => 'Yes'], ['key' => 'no', 'label' => 'No']];

        return ['slug' => self::SLUG, 'title' => self::TITLE, 'accessGroup' => 'hr', 'fields' => [
            self::f('inspectionDate', 'Inspection date', 'date', true, 'hr'),
            self::f('inspectedBy', 'Inspected by', 'text', true, 'hr'),
            self::f('registration', 'Registration', 'text', true, 'hr'),
            self::f('year', 'Year', 'text', false, 'hr'),
            self::f('make', 'Make', 'text', true, 'hr'),
            self::f('model', 'Model', 'text', true, 'hr'),
            self::f('bodyType', 'Body type', 'text', false, 'hr'),
            self::f('colour', 'Colour', 'text', false, 'hr'),
            self::f('transmission', 'Transmission', 'text', false, 'hr'),
            self::f('odometer', 'Odometer (km)', 'text', true, 'hr'),
            self::f('nextServiceDue', 'Next service due', 'text', false, 'hr'),
            self::f('firstAidKit', 'First aid kit in the vehicle', 'select', true, 'hr', null, $yesNo),
            self::f('logBook', 'Log book in the vehicle', 'select', true, 'hr', null, $yesNo),
            self::f('overallCondition', 'Overall condition', 'select', true, 'hr', null, [
                ['key' => 'new', 'label' => 'New'],
                ['key' => 'as_new', 'label' => 'As new'],
                ['key' => 'used', 'label' => 'Used'],
                ['key' => 'poor', 'label' => 'Poor'],
            ]),
            self::f('comments', 'Comments — damage, marks, anything missing', 'textarea', false, 'hr'),
            self::f('photos', 'Condition photos', 'photo', false, 'hr', 'Up to four. The first is printed on the report; each gets its own page.'),
            self::f('driverPosition', 'Your position', 'text', true, 'starter'),
            self::f('licenceNumber', 'Driver licence number', 'text', true, 'starter'),
            self::f('licenceExpiry', 'Licence expiry', 'date', true, 'starter'),
            self::f('licenceCopyAttached', 'A copy of my licence has been given to HR', 'checkbox', false, 'starter'),
            self::f('handoverDate', 'Handover date', 'date', true, 'starter'),
            self::f('acknowledged', 'I accept the vehicle in the condition recorded in this report, and the Company Vehicle Policy.', 'checkbox', true, 'starter'),
        ]];
    }

    /**
     * @param list<array{key: string, label: string}>|null $options
     * @return array<string, mixed>
     */
    private static function f(string $path, string $label, string $type, bool $required, string $filledBy, ?string $hint = null, ?array $options = null): array
    {
        $field = ['path' => $path, 'label' => $label, 'type' => $type, 'required' => $required, 'dataClass' => DataClass::ORDINARY_HR];
        if ($filledBy !== 'starter') {
            $field['filledBy'] = $filledBy;
        }
        if ($hint !== null) {
            $field['hint'] = $hint;
        }
        if ($options !== null) {
            $field['options'] = $options;
        }

        return $field;
    }
}
