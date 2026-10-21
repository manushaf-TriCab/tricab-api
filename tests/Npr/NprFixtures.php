<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Npr;

use DateTimeImmutable;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Npr\Application\Service\NprAccessPolicy;
use TriCab\Core\Npr\Application\Service\NprRequestService;
use TriCab\Core\Npr\Application\Service\NprValidationService;
use TriCab\Core\Npr\Contract\NprRequesterDirectory;
use TriCab\Core\Npr\Domain\NprRequest;
use TriCab\Core\Npr\Domain\NprRequesterContact;
use TriCab\Core\Npr\Domain\RequestStatus;
use TriCab\Core\Npr\Infrastructure\InMemoryNprRequestRepository;
use TriCab\Core\Npr\Infrastructure\NullNprRequesterDirectory;
use TriCab\Core\Npr\Infrastructure\RosterNprBranchDirectory;
use TriCab\Core\Npr\Module;

/** Shared builders for the NPR tests. */
final class NprFixtures
{
    public static function resource(string $name): string
    {
        return dirname(__DIR__, 2) . '/resources/npr/' . $name;
    }

    public static function validator(): NprValidationService
    {
        return NprValidationService::fromFile(self::resource('field-dictionary.json'));
    }

    /** @param list<string> $permissions */
    public static function user(int $id, array $permissions, string $email = 'amandaw@tricab.com', string $name = 'Amanda Williams'): AuthenticatedUser
    {
        return new AuthenticatedUser(
            $id,
            sprintf('00000000-0000-4000-8000-%012d', $id),
            $email,
            $name,
            'ACTIVE',
            ['EMPLOYEE'],
            $permissions,
        );
    }

    public static function requester(int $id = 10): AuthenticatedUser
    {
        return self::user($id, Module::ROLE_PERMISSIONS[Module::ROLE_REQUESTER]);
    }

    public static function tsd(int $id = 20): AuthenticatedUser
    {
        return self::user($id, Module::ROLE_PERMISSIONS[Module::ROLE_TSD_ENGINEER], 'chabilals@tricab.com', 'TSD Engineer');
    }

    public static function service(InMemoryNprRequestRepository $repository, ?NprRequesterDirectory $directory = null): NprRequestService
    {
        return new NprRequestService(
            $repository,
            new RosterNprBranchDirectory(self::resource('sales-roster.json')),
            new NprAccessPolicy(),
            $directory ?? new NullNprRequesterDirectory(),
        );
    }

    /** A staff directory that knows everyone as the given office and phone. */
    public static function directory(?string $officeLocation, ?string $phone): NprRequesterDirectory
    {
        return new class ($officeLocation, $phone) implements NprRequesterDirectory {
            public function __construct(private readonly ?string $officeLocation, private readonly ?string $phone)
            {
            }

            public function contactFor(string $email): NprRequesterContact
            {
                return new NprRequesterContact($this->officeLocation, $this->phone);
            }
        };
    }

    /**
     * @param array<string, mixed>       $answers
     * @param list<array<string, mixed>> $variants
     */
    public static function record(string $publicId, int $ownerId, RequestStatus $status, int $revision = 1, array $answers = [], array $variants = [], ?DateTimeImmutable $tsdReceivedAt = null): NprRequest
    {
        return new NprRequest(
            $publicId,
            'NPR-2026-' . substr($publicId, -4),
            $revision,
            $status,
            'Mining trailing cable',
            'BHP',
            'standard',
            $ownerId,
            'amandaw@tricab.com',
            'Amanda Williams',
            'Brisbane',
            $answers,
            $variants,
            '0x00000000000000AA',
            new DateTimeImmutable('2026-10-01 00:00:00'),
            new DateTimeImmutable('2026-10-02 00:00:00'),
            null,
            null,
            $tsdReceivedAt,
        );
    }

    /**
     * Every applicable Sales answer for a 3-core 70mm2 request. From the portal's test_backend.php.
     *
     * @return array<string, mixed>
     */
    public static function validRequestAnswers(): array
    {
        return [
            'job_title' => ['state' => 'provided', 'value' => 'Internal Sales'],
            'branch' => ['state' => 'provided', 'value' => 'Brisbane'],
            'contact_number' => ['state' => 'provided', 'value' => '+61 7 3000 0000'],
            'customer_name' => ['state' => 'provided', 'value' => 'BHP'],
            'customer_contact' => ['state' => 'provided', 'value' => 'John Smith'],
            'customer_contact_role' => ['state' => 'provided', 'value' => 'Chief Electrical Engineer'],
            'end_user' => ['state' => 'provided', 'value' => 'Olympic Dam Mine Site'],
            'customer_types' => ['state' => 'provided', 'value' => ['mining'], 'selectionMode' => 'all_required'],
            'existing_account' => ['state' => 'provided', 'value' => 'no'],
            'request_types' => ['state' => 'provided', 'value' => ['A'], 'selectionMode' => 'all_required'],
            'customer_need' => ['state' => 'provided', 'value' => 'High flexibility reeling cable for mobile substation'],
            'volume_12_months' => ['state' => 'provided', 'value' => 5000, 'basis' => 'Customer forecast', 'confidence' => 'estimate'],
            'volume_three_years' => ['state' => 'provided', 'value' => 15000, 'basis' => 'Mine expansion plan', 'confidence' => 'estimate'],
            'target_price' => ['state' => 'provided', 'value' => ['amount' => 45.5, 'currency' => 'AUD', 'per' => 'm'], 'basis' => 'Target budget', 'confidence' => 'indicative'],
            'current_supplier' => ['state' => 'provided', 'value' => 'Competitor X', 'basis' => 'Verbal confirmation', 'confidence' => 'confirmed'],
            'competitor_price' => ['state' => 'not_stated', 'reason' => 'Customer withheld price', 'basis' => 'N/A', 'confidence' => 'indicative'],
            'supply_frequency' => ['state' => 'provided', 'value' => 'repeating', 'basis' => 'Annual supply contract', 'confidence' => 'confirmed'],
            'other_customer_interest' => ['state' => 'provided', 'value' => 'no', 'basis' => 'Mine-specific construction', 'confidence' => 'confirmed'],
            'win_probability' => ['state' => 'provided', 'value' => 'high', 'basis' => 'Sole supplier RFQ', 'confidence' => 'confirmed'],
            'revenue_at_risk' => ['state' => 'provided', 'value' => ['amount' => 200000, 'currency' => 'AUD'], 'basis' => 'Annual spend at risk', 'confidence' => 'confirmed'],
            'relationship_at_risk' => ['state' => 'provided', 'value' => 'no', 'basis' => 'Preferred supplier status', 'confidence' => 'confirmed'],
            'document_types' => ['state' => 'provided', 'value' => ['verbal_only'], 'selectionMode' => 'all_required'],
            'budget_quote_date' => ['state' => 'provided', 'value' => '2026-10-01'],
            'budget_quote_consequence' => ['state' => 'provided', 'value' => 'Loss of tender opportunity'],
            'firm_quote_date' => ['state' => 'provided', 'value' => '2026-10-15'],
            'firm_quote_consequence' => ['state' => 'provided', 'value' => 'Disqualification from procurement cycle'],
            'sample_date' => ['state' => 'not_applicable', 'reason' => 'Sample not requested by customer'],
            'tender_date' => ['state' => 'not_applicable', 'reason' => 'Direct RFQ, not a public tender'],
            'first_delivery_date' => ['state' => 'provided', 'value' => '2026-12-01'],
            'first_delivery_consequence' => ['state' => 'provided', 'value' => 'Project commissioning delayed'],
            'urgency' => ['state' => 'provided', 'value' => 'urgent'],
            'sales_declaration' => ['state' => 'provided', 'value' => true],
        ];
    }

    /** @return array<string, mixed> */
    public static function validVariantAnswers(): array
    {
        return [
            'voltage_rating' => ['state' => 'provided', 'value' => ['originalNotation' => '3.3kV', 'voltage_kV' => 3.3, 'currentType' => 'AC']],
            'main_core_count' => ['state' => 'provided', 'value' => 3],
            'additional_core_requirements' => ['state' => 'not_applicable', 'reason' => 'Standard power cores only'],
            'conductor_size' => ['state' => 'provided', 'value' => 70],
            'conductor_material' => ['state' => 'provided', 'value' => ['copper'], 'selectionMode' => 'acceptable_alternatives'],
            'conductor_class' => ['state' => 'provided', 'value' => ['class_5'], 'selectionMode' => 'acceptable_alternatives'],
            'insulation_material' => ['state' => 'provided', 'value' => ['EPR'], 'selectionMode' => 'acceptable_alternatives'],
            'armour' => ['state' => 'provided', 'value' => ['none'], 'selectionMode' => 'acceptable_alternatives'],
            'sheath_material' => ['state' => 'provided', 'value' => ['CPE'], 'selectionMode' => 'acceptable_alternatives'],
            'screening_required' => ['state' => 'provided', 'value' => 'yes'],
            'water_blocking_required' => ['state' => 'provided', 'value' => 'no'],
            'sheath_colours' => ['state' => 'provided', 'value' => ['black'], 'selectionMode' => 'all_required'],
            'fire_performance' => ['state' => 'provided', 'value' => ['HFFR'], 'selectionMode' => 'all_required'],
            'operating_temperature' => ['state' => 'provided', 'value' => ['originalText' => '-25C to 90C', 'minimum_C' => -25, 'maximum_C' => 90]],
            'installation_methods' => ['state' => 'provided', 'value' => ['tray'], 'selectionMode' => 'all_required'],
            'special_requirements' => ['state' => 'provided', 'value' => ['UV', 'oil'], 'selectionMode' => 'all_required'],
            'standards' => ['state' => 'provided', 'value' => [['number' => 'AS/NZS 2802', 'edition' => '2000', 'editionState' => 'provided', 'clauses' => []]]],
            'customer_specification' => ['state' => 'not_applicable', 'reason' => 'No separate spec sheet supplied'],
            'approval_required' => ['state' => 'provided', 'value' => 'no'],
            'drum_length' => ['state' => 'provided', 'value' => 500],
            'prototype_length' => ['state' => 'not_applicable', 'reason' => 'Production cable only'],
            'marking' => ['state' => 'provided', 'value' => 'Standard TriCab meter marking'],
            'packaging' => ['state' => 'provided', 'value' => 'Timber drum'],
            'other_information' => ['state' => 'not_applicable', 'reason' => 'None'],
            'intended_application' => ['state' => 'provided', 'value' => 'Open-cut mining mobile substation trailing power'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function validVariants(): array
    {
        return [['id' => 'var_1', 'label' => 'Standard 3x70mm2', 'answers' => self::validVariantAnswers()]];
    }
}
