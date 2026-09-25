<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Migration\Form;

/**
 * Source form payloads → the platform's field paths (Phase 7.2).
 *
 * The source stored each form in its own TypeScript shape
 * (`api/_lib/forms/*.ts`); the platform's forms (5.4, `src/Onboarding/FormImport`)
 * ask the same questions under flat, dotted paths, and split them by who fills
 * each part. One mapping per TriCab form, written out rather than inferred:
 *
 *  - `employee-details` — `mealPreferences` → `meals.*`, `bankAccounts[0|1]` →
 *    `bank1|2.*`, `emergencyContacts[0|1]` → `emergency1|2.*`.
 *  - `whs-medical-questionnaire` — `responses[{id: "time-off-work"}]` →
 *    `timeOffWork.answer|details`; `signatureDataUrl` → `signature`.
 *  - `deed-of-confidentiality` — `employee|witnessSignatureDataUrl` →
 *    `employee|witnessSignature`.
 *  - `new-starter-induction-checklist` — HR fills the header and the items
 *    (`dayOne[{id: "site-tour"}]` → `dayOne.siteTour.state|responsible|completedOn`,
 *    a `pending` item being unanswered); the manager part is
 *    `managerAcknowledged(On)`; the trainee part is `employeeAcknowledged`.
 *  - `vehicle-condition-report` — HR fills the vehicle and photos; the
 *    trainee part is `driver.*` flattened (`driverPosition`, `licenceNumber`…).
 *
 * Any other slug is read as already being in the platform's shape (nested
 * objects standing for dotted paths) and is all the trainee's — which is what
 * the synthetic rehearsal's `demo-` forms are.
 *
 * Pure: no database, no request, no clock.
 */
final class SourceFormTranslator
{
    public const EMPLOYEE_DETAILS = 'employee-details';
    public const MEDICAL = 'whs-medical-questionnaire';
    public const DEED = 'deed-of-confidentiality';
    public const CHECKLIST = 'new-starter-induction-checklist';
    public const VEHICLE = 'vehicle-condition-report';

    private const EMPLOYEE_SCALARS = [
        'surname', 'givenName', 'preferredName', 'dateOfBirth', 'address', 'postcode', 'mobile', 'homePhone',
        'aboriginalOrTorresStraitIslander', 'culturalIdentity', 'genderIdentity', 'medicalConditions',
        'immigrationStatus', 'immigrationOtherDetails', 'passportNumber', 'visaNumberAndSubclass', 'visaExpiry',
        'payslipEmail', 'payslipPin', 'declarationAccepted',
    ];
    private const BANK_KEYS = ['bankName', 'branch', 'bsb', 'accountNumber', 'accountName', 'allocationType', 'allocationValue'];
    private const CONTACT_KEYS = ['fullName', 'relationship', 'phone'];
    private const MEDICAL_QUESTIONS = [
        'time-off-work' => 'timeOffWork',
        'job-restrictions' => 'jobRestrictions',
        'treatment-or-medication' => 'treatmentOrMedication',
        'special-requirements' => 'specialRequirements',
        'other-support-condition' => 'otherSupportCondition',
    ];
    private const CHECKLIST_HEADER = ['employeeName', 'startDate', 'position', 'managerName', 'department', 'branch', 'dayOneNotes', 'submittedToHrBy', 'submittedToHrOn'];
    private const VEHICLE_HR = ['inspectionDate', 'inspectedBy', 'registration', 'year', 'make', 'model', 'bodyType', 'colour', 'transmission', 'odometer', 'nextServiceDue', 'firstAidKit', 'logBook', 'overallCondition', 'comments'];
    private const VEHICLE_DRIVER = [
        'position' => 'driverPosition',
        'licenceNumber' => 'licenceNumber',
        'licenceExpiry' => 'licenceExpiry',
        'licenceCopyAttached' => 'licenceCopyAttached',
        'acknowledged' => 'acknowledged',
        'handoverDate' => 'handoverDate',
    ];

    /**
     * @param array<array-key, mixed> $data the source's `draft_data` or `submitted_data`
     */
    public static function translate(string $slug, array $data): TranslatedForm
    {
        return match ($slug) {
            self::EMPLOYEE_DETAILS => self::employeeDetails($data),
            self::MEDICAL => self::medical($data),
            self::DEED => self::deed($data),
            self::CHECKLIST => self::checklist($data),
            self::VEHICLE => self::vehicle($data),
            default => self::generic($data),
        };
    }

    /** @param array<array-key, mixed> $data */
    private static function employeeDetails(array $data): TranslatedForm
    {
        $out = new Collector($data);
        foreach (self::EMPLOYEE_SCALARS as $key) {
            $out->copy($key, $key);
        }
        foreach (['vegetarian', 'vegan', 'other', 'otherDetails'] as $key) {
            $out->copy('mealPreferences.' . $key, 'meals.' . $key);
        }
        foreach ([0, 1] as $i) {
            foreach (self::BANK_KEYS as $key) {
                $out->copy('bankAccounts.' . $i . '.' . $key, 'bank' . ($i + 1) . '.' . $key);
            }
            foreach (self::CONTACT_KEYS as $key) {
                $out->copy('emergencyContacts.' . $i . '.' . $key, 'emergency' . ($i + 1) . '.' . $key);
            }
        }

        return new TranslatedForm($out->trainee, dropped: $out->leftovers());
    }

    /** @param array<array-key, mixed> $data */
    private static function medical(array $data): TranslatedForm
    {
        $out = new Collector($data);
        foreach (['fullName', 'address', 'contactNumber', 'signedDate', 'declarationAccepted'] as $key) {
            $out->copy($key, $key);
        }
        $out->copy('signatureDataUrl', 'signature');
        foreach (is_array($data['responses'] ?? null) ? $data['responses'] : [] as $i => $response) {
            $id = is_array($response) && is_string($response['id'] ?? null) ? $response['id'] : '';
            $target = self::MEDICAL_QUESTIONS[$id] ?? null;
            $out->consume('responses.' . $i . '.id');
            if ($target === null) {
                continue;
            }
            $out->copy('responses.' . $i . '.answer', $target . '.answer');
            $out->copy('responses.' . $i . '.details', $target . '.details');
        }

        return new TranslatedForm($out->trainee, dropped: $out->leftovers());
    }

    /** @param array<array-key, mixed> $data */
    private static function deed(array $data): TranslatedForm
    {
        $out = new Collector($data);
        foreach (['deedDate', 'fullName', 'address', 'employeeSignedDate', 'witnessName', 'declarationAccepted'] as $key) {
            $out->copy($key, $key);
        }
        $out->copy('employeeSignatureDataUrl', 'employeeSignature');
        $out->copy('witnessSignatureDataUrl', 'witnessSignature');

        return new TranslatedForm($out->trainee, dropped: $out->leftovers());
    }

    /** @param array<array-key, mixed> $data */
    private static function checklist(array $data): TranslatedForm
    {
        $out = new Collector($data);
        foreach (self::CHECKLIST_HEADER as $key) {
            $out->copy($key, $key, Collector::HR);
        }
        foreach (['dayOne', 'weekOne'] as $group) {
            foreach (is_array($data[$group] ?? null) ? $data[$group] : [] as $i => $item) {
                $base = $group . '.' . $i;
                $id = is_array($item) && is_string($item['id'] ?? null) ? $item['id'] : '';
                $out->consume($base . '.id');
                if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $id) !== 1) {
                    continue;
                }
                $target = $group . '.' . self::camel($id);
                // "pending" is the source's word for not done yet — unanswered here.
                if (($item['state'] ?? null) === 'pending') {
                    $out->consume($base . '.state');
                } else {
                    $out->copy($base . '.state', $target . '.state', Collector::HR);
                }
                $out->copy($base . '.responsiblePerson', $target . '.responsible', Collector::HR);
                $out->copy($base . '.completedOn', $target . '.completedOn', Collector::HR);
            }
        }
        $out->copy('managerAcknowledgement.accepted', 'managerAcknowledged', Collector::MANAGER);
        $out->copy('managerAcknowledgement.date', 'managerAcknowledgedOn', Collector::MANAGER);
        $out->copy('employeeAcknowledgement.accepted', 'employeeAcknowledged');
        // The platform records when and by whom an acknowledgement was given; the
        // typed name and date the source asked for are not separate answers.
        $out->consume('employeeAcknowledgement.name');
        $out->consume('employeeAcknowledgement.date');

        return new TranslatedForm($out->trainee, $out->hr, $out->manager, dropped: $out->leftovers());
    }

    /** @param array<array-key, mixed> $data */
    private static function vehicle(array $data): TranslatedForm
    {
        $out = new Collector($data);
        foreach (self::VEHICLE_HR as $key) {
            $out->copy($key, $key, Collector::HR);
        }
        foreach (self::VEHICLE_DRIVER as $source => $target) {
            $out->copy('driver.' . $source, $target);
        }

        $photos = [];
        foreach (is_array($data['photos'] ?? null) ? $data['photos'] : [] as $i => $photo) {
            if (!is_array($photo) || !is_string($photo['base64'] ?? null) || $photo['base64'] === '') {
                continue;
            }
            $photos[] = [
                'sourceId' => is_string($photo['id'] ?? null) ? $photo['id'] : (string) $i,
                'filename' => is_string($photo['filename'] ?? null) ? $photo['filename'] : 'photo-' . $i . '.jpg',
                'base64' => $photo['base64'],
            ];
            foreach (['id', 'filename', 'mimeType', 'base64', 'width', 'height', 'uploadedAt'] as $key) {
                $out->consume('photos.' . $i . '.' . $key);
            }
        }

        return new TranslatedForm($out->trainee, $out->hr, $out->manager, $photos, $out->leftovers());
    }

    /** @param array<array-key, mixed> $data */
    private static function generic(array $data): TranslatedForm
    {
        $out = new Collector($data);
        foreach (array_keys($out->remaining()) as $path) {
            $out->copy($path, $path);
        }

        return new TranslatedForm($out->trainee, dropped: $out->leftovers());
    }

    private static function camel(string $kebab): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $kebab))));
    }
}
