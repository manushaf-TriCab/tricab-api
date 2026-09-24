<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\FormImport;

use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;

/**
 * The New Starter Induction Checklist (HR-FOR-003-V006), from the source's
 * `checklist.ts`: 10 day-one and 18 week-one items, each completed or marked
 * not applicable with who did it and when, then acknowledged.
 *
 * Who fills what (owner decision, 24 Sep 2026): HR fills the details and every
 * item, the named manager signs off, and the new starter acknowledges. All of
 * it is ordinary HR data — an `hr` form.
 *
 * Differences from the source, both deliberate:
 *  - an item's free-text notes are not carried: the paper form never printed
 *    them, and 28 items × 4 fields would pass the engine's 100-field limit;
 *  - the starter may acknowledge before HR and the manager finish. The form
 *    still cannot be accepted until every required HR and manager part is
 *    filled, which is what the source's ordering protected.
 */
final class ChecklistFormDefinition
{
    public const SLUG = 'new-starter-induction-checklist';
    public const TITLE = 'New Starter Induction Checklist';

    /** item path => wording, in the sheet's order */
    public const DAY_ONE = [
        'paperwork' => 'All new starter paperwork completed and submitted',
        'identityDocuments' => 'Copies of identity, licence and qualification documents submitted',
        'tricabInduction' => 'TriCab induction — company values and behavioural expectations',
        'siteTour' => 'Tour of site and emergency arrangements',
        'headshot' => 'Headshot photo',
        'whseq' => 'WHSEQ induction and assessment',
        'sexualHarassment' => 'Sexual Harassment training',
        'respectAtWork' => 'Respect at Work training',
        'checks' => 'Medical, police and immigration checks (where applicable)',
        'reviewDates' => 'Start and review dates logged',
    ];

    public const WEEK_ONE = [
        'meetTeam' => 'Meet the team',
        'localArea' => 'Local work area',
        'safetyBoard' => 'Safety board and reporting channels',
        'qualityPolicy' => 'Understanding of Quality Policy',
        'uniformPpe' => 'Uniform and PPE requirements',
        'trainingPlan' => 'Training plan overview',
        'competencyDocuments' => 'Competency documents overview',
        'workHours' => 'Work hours, breaks, leave and Fair Work information',
        'positionDescription' => 'Position description and responsibilities',
        'locker' => 'Locker guidelines',
        'swipeCard' => 'Swipe card policy (if applicable)',
        'payroll' => 'Payroll and finger scan setup (if applicable)',
        'tools' => 'Tools of trade (if applicable)',
        'itOnboarding' => 'IT onboarding',
        'corporatePolicies' => 'Corporate policies and conduct standards',
        'vehiclePolicy' => 'Company Vehicle Policy (if applicable)',
        'creditCardPolicy' => 'Company Credit Card Policy (if applicable)',
        'businessCards' => 'Business cards ordered (if applicable)',
    ];

    /** @return array{slug: string, title: string, accessGroup: string, fields: list<array<string, mixed>>} */
    public static function definition(): array
    {
        $fields = [
            self::f('employeeName', 'Employee name', 'text', true, 'hr'),
            self::f('startDate', 'Start date', 'date', true, 'hr'),
            self::f('position', 'Position', 'text', true, 'hr'),
            self::f('managerName', 'Manager', 'text', true, 'hr'),
            self::f('department', 'Department', 'text', false, 'hr'),
            self::f('branch', 'Branch', 'text', false, 'hr'),
        ];
        foreach (['dayOne' => self::DAY_ONE, 'weekOne' => self::WEEK_ONE] as $section => $items) {
            $heading = $section === 'dayOne' ? 'Day one' : 'Week one';
            foreach ($items as $item => $wording) {
                $fields[] = self::f("{$section}.{$item}.state", "{$heading}: {$wording}", 'select', true, 'hr', null, [
                    ['key' => 'completed', 'label' => 'Done'],
                    ['key' => 'not_applicable', 'label' => 'Not applicable'],
                ]);
                $fields[] = self::f("{$section}.{$item}.responsible", "{$heading}: {$wording} — responsible person", 'text', true, 'hr');
                $fields[] = self::f("{$section}.{$item}.completedOn", "{$heading}: {$wording} — date", 'date', true, 'hr');
            }
            if ($section === 'dayOne') {
                $fields[] = self::f('dayOneNotes', 'Day one notes', 'textarea', false, 'hr');
            }
        }
        $fields[] = self::f('managerAcknowledged', 'I, the manager, confirm this induction was completed as recorded.', 'checkbox', true, 'manager');
        $fields[] = self::f('managerAcknowledgedOn', 'Manager sign-off date', 'date', true, 'manager');
        $fields[] = self::f('submittedToHrBy', 'Submitted to HR by', 'text', true, 'hr');
        $fields[] = self::f('submittedToHrOn', 'Submitted to HR on', 'date', true, 'hr');
        $fields[] = self::f('employeeAcknowledged', 'I confirm I received the induction recorded on this checklist.', 'checkbox', true, 'starter');

        return ['slug' => self::SLUG, 'title' => self::TITLE, 'accessGroup' => 'hr', 'fields' => $fields];
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
