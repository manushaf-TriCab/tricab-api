<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\ValueObject;

use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\Service\LeaverPolicy;

/**
 * What a new starter fills in when they acknowledge one of the policies that
 * TriCab has them sign (5.4). Owner decision, 24 Sep 2026: this is part of the
 * acknowledgement and is stored on it (`PolicyAcknowledgements.DeclarationJson`),
 * not kept as a separate form.
 *
 * Two kinds, as in the source (`api/_lib/handlers/policies.ts`):
 *
 *  - **sign_off**: full name and a drawn signature. Used by the Employee
 *    Declaration and five policies, found by document reference. The Company
 *    Vehicle Policy also asks the starter to confirm that a copy of their driver
 *    licence is attached.
 *  - **code_of_conduct** (HR-POL-036-V000): role, conflict-of-interest status
 *    with details, two declarations that must both be ticked, name, division or
 *    region, and a final Yes or No. There is no signature.
 *
 * ## One difference from the source: the date
 *
 * The source let the starter type the date. Here the server writes the date of
 * the acknowledgement, in TriCab's timezone. A date the signatory chose is not
 * evidence of when they signed.
 */
final readonly class PolicyDeclaration
{
    public const SIGN_OFF = 'sign_off';
    public const CODE_OF_CONDUCT = 'code_of_conduct';

    public const CODE_OF_CONDUCT_REFERENCE = 'HR-POL-036-V000';
    public const EMPLOYEE_DECLARATION_SLUG = 'employee-declaration';
    public const VEHICLE_POLICY_REFERENCE = 'HR-POL-010-V003';

    /** The policies with a printed name / signature / date block, as the source listed them. */
    public const SIGN_OFF_REFERENCES = [
        'HR-POL-001-V004',
        'HR-POL-009-V004',
        self::VEHICLE_POLICY_REFERENCE,
        'HR-POL-033-V008',
        'WHS-POL-001-V005',
    ];

    private const NAME_MAX = 200;
    private const DETAILS_MAX = 2000;

    /**
     * @param array<string, mixed> $values the stored shape; see {@see toArray()}
     */
    private function __construct(
        public string $kind,
        private array $values,
    ) {
    }

    /** Which declaration this policy asks for, or null when acknowledging is enough. */
    public static function kindFor(?string $documentReference, ?string $slug): ?string
    {
        $reference = trim((string) $documentReference);
        if ($reference === self::CODE_OF_CONDUCT_REFERENCE) {
            return self::CODE_OF_CONDUCT;
        }
        if ($slug === self::EMPLOYEE_DECLARATION_SLUG || in_array($reference, self::SIGN_OFF_REFERENCES, true)) {
            return self::SIGN_OFF;
        }

        return null;
    }

    /**
     * Checks what the browser sent and adds the date.
     *
     * @throws InvalidOnboardingValue naming what is missing, in words a person can act on
     */
    public static function fromInput(string $kind, mixed $input, ?string $documentReference, \DateTimeImmutable $now): self
    {
        if (!is_array($input)) {
            throw InvalidOnboardingValue::required('declaration', 'complete the declaration before you acknowledge this policy.');
        }
        $date = $now->setTimezone(new \DateTimeZone(LeaverPolicy::LOCAL_TIMEZONE))->format('Y-m-d');

        if ($kind === self::SIGN_OFF) {
            $fullName = self::clean($input['fullName'] ?? null, self::NAME_MAX);
            if ($fullName === '') {
                throw InvalidOnboardingValue::required('full name', 'enter your full name.');
            }
            // Throws naming the signature when it is missing or is not a real PNG.
            $signature = $input['signature'] ?? null;
            SignatureImage::fromDataUrl('signature', $signature);
            if (($input['accepted'] ?? null) !== true) {
                throw InvalidOnboardingValue::required('agreement', 'confirm that you agree to this policy.');
            }
            $values = ['fullName' => $fullName, 'signature' => $signature, 'date' => $date, 'accepted' => true];
            if (trim((string) $documentReference) === self::VEHICLE_POLICY_REFERENCE) {
                if (($input['licenceCopyAttached'] ?? null) !== true) {
                    throw InvalidOnboardingValue::required('driver licence copy', 'confirm that a copy of your current driver licence is attached.');
                }
                $values['licenceCopyAttached'] = true;
            }

            return new self(self::SIGN_OFF, $values);
        }

        if ($kind !== self::CODE_OF_CONDUCT) {
            throw InvalidOnboardingValue::forOneOf('declaration kind', $kind, [self::SIGN_OFF, self::CODE_OF_CONDUCT]);
        }

        $role = $input['role'] ?? null;
        if ($role !== 'executive' && $role !== 'employee') {
            throw InvalidOnboardingValue::required('role', 'choose Executive or Employee.');
        }
        $conflictStatus = $input['conflictStatus'] ?? null;
        if ($conflictStatus !== 'declared' && $conflictStatus !== 'none') {
            throw InvalidOnboardingValue::required('conflict-of-interest status', 'say whether you have a conflict of interest to declare.');
        }
        $conflictDetails = $conflictStatus === 'declared' ? self::clean($input['conflictDetails'] ?? null, self::DETAILS_MAX) : '';
        if ($conflictStatus === 'declared' && $conflictDetails === '') {
            throw InvalidOnboardingValue::required('conflict-of-interest details', 'describe the conflict of interest.');
        }
        if (($input['willAvoidUndeclaredConflict'] ?? null) !== true || ($input['understandsConsequences'] ?? null) !== true) {
            throw InvalidOnboardingValue::required('conflict-of-interest declarations', 'tick both declarations.');
        }
        $name = self::clean($input['name'] ?? null, self::NAME_MAX);
        $divisionRegion = self::clean($input['divisionRegion'] ?? null, self::NAME_MAX);
        if ($name === '' || $divisionRegion === '') {
            throw InvalidOnboardingValue::required('name and division or region', 'enter both.');
        }
        if (!is_bool($input['accepted'] ?? null)) {
            throw InvalidOnboardingValue::required('final declaration', 'choose Yes or No.');
        }

        return new self(self::CODE_OF_CONDUCT, [
            'role' => $role,
            'conflictStatus' => $conflictStatus,
            'conflictDetails' => $conflictDetails,
            'willAvoidUndeclaredConflict' => true,
            'understandsConsequences' => true,
            'name' => $name,
            'divisionRegion' => $divisionRegion,
            'date' => $date,
            'accepted' => $input['accepted'],
        ]);
    }

    /** A stored declaration; null for none or for one that no longer reads as either kind. */
    public static function fromStoredJson(?string $json): ?self
    {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !is_array($decoded['values'] ?? null)) {
            return null;
        }
        $kind = $decoded['kind'] ?? null;
        if ($kind !== self::SIGN_OFF && $kind !== self::CODE_OF_CONDUCT) {
            return null;
        }

        return new self($kind, $decoded['values']);
    }

    public function toJson(): string
    {
        return json_encode(['kind' => $this->kind, 'values' => $this->values], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * What a page shows back: everything but the signature image, which is
     * reported only as present. The image goes to paper, not back to a browser.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        $values = $this->values;
        if (array_key_exists('signature', $values)) {
            unset($values['signature']);
            $values['signed'] = true;
        }

        return ['kind' => $this->kind] + $values;
    }

    public function text(string $key): string
    {
        $value = $this->values[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    public function flag(string $key): bool
    {
        return ($this->values[$key] ?? null) === true;
    }

    private static function clean(mixed $value, int $max): string
    {
        if (!is_string($value)) {
            return '';
        }
        $clean = trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_substr($clean, 0, $max);
    }
}
