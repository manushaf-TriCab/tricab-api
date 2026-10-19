<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Npr\Support;

use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Npr\NprFixtures;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Npr\Application\Service\ManagerRoutingService;
use TriCab\Core\Npr\Application\Service\NprAccessPolicy;
use TriCab\Core\Npr\Application\Service\NprPeople;
use TriCab\Core\Npr\Application\Service\NprRequestService;
use TriCab\Core\Npr\Application\Service\NprSigningPolicy;
use TriCab\Core\Npr\Application\Service\NprWorkflowService;
use TriCab\Core\Npr\Domain\NewManagerRoute;
use TriCab\Core\Npr\Infrastructure\InMemoryManagerRouteRepository;
use TriCab\Core\Npr\Infrastructure\InMemoryNprRequestRepository;
use TriCab\Core\Npr\Infrastructure\RosterNprBranchDirectory;
use TriCab\Core\Npr\Module;

/**
 * A small company for the workflow tests: Amanda (Brisbane Sales), Shane (the
 * Brisbane manager), another manager, two TSD engineers and one nominated signatory.
 */
final class NprWorkflowWorld
{
    public const SALES = 10;
    public const SHANE = 30;
    public const OTHER_MANAGER = 31;
    public const TSD = 20;
    public const TSD_TWO = 21;
    public const SIGNATORY = 40;

    public InMemoryNprRequestRepository $requests;
    public InMemoryManagerRouteRepository $routes;
    public NprUserDirectory $directory;
    public RecordingNprEventSink $events;
    public FixedClock $clock;
    public NprRequestService $drafts;
    public NprWorkflowService $workflow;
    public ManagerRoutingService $routing;

    /** @param list<string> $nominated signatory emails; empty leaves the signing policy unconfigured */
    public function __construct(bool $brisbaneRouted = true, array $nominated = ['signatory@tricab.com'])
    {
        $this->clock = FixedClock::utc('2026-10-19 03:00:00');
        $this->requests = new InMemoryNprRequestRepository($this->clock);
        $this->routes = new InMemoryManagerRouteRepository();
        $this->events = new RecordingNprEventSink();
        $this->directory = (new NprUserDirectory())
            ->add(self::SALES, 'amandaw@tricab.com', 'Amanda Williams', Module::ROLE_PERMISSIONS[Module::ROLE_REQUESTER])
            ->add(self::SHANE, 'shanep@tricab.com', 'Shane Plumridge', Module::ROLE_PERMISSIONS[Module::ROLE_MANAGER])
            ->add(self::OTHER_MANAGER, 'othermanager@tricab.com', 'Other Manager', Module::ROLE_PERMISSIONS[Module::ROLE_MANAGER])
            ->add(self::TSD, 'chabilals@tricab.com', 'TSD Engineer', Module::ROLE_PERMISSIONS[Module::ROLE_TSD_ENGINEER])
            ->add(self::TSD_TWO, 'tsdtwo@tricab.com', 'TSD Two', Module::ROLE_PERMISSIONS[Module::ROLE_TSD_ENGINEER])
            ->add(self::SIGNATORY, 'signatory@tricab.com', 'Sign Atory', Module::ROLE_PERMISSIONS[Module::ROLE_TSD_SIGNATORY]);

        $people = new NprPeople($this->directory);
        $this->routing = new ManagerRoutingService($this->routes, $people, $this->clock);
        $branches = new RosterNprBranchDirectory(NprFixtures::resource('sales-roster.json'));
        $policy = new NprAccessPolicy();
        $this->drafts = new NprRequestService($this->requests, $branches, $policy);
        $this->workflow = new NprWorkflowService(
            $this->requests,
            $branches,
            $policy,
            NprFixtures::validator(),
            $this->routing,
            $people,
            new NprSigningPolicy($nominated),
            $this->events,
            $this->clock,
        );

        if ($brisbaneRouted) {
            $this->route('Brisbane', self::SHANE);
        }
    }

    public function route(string $branch, int $managerId, bool $delegate = false, string $from = '2026-01-01', ?string $to = null): void
    {
        $user = $this->directory->findById(UserId::fromInt($managerId)) ?? throw new \LogicException('Unknown test user.');
        $this->routes->create(new NewManagerRoute(
            $branch,
            NprPeople::person($user),
            $delegate,
            new \DateTimeImmutable($from, new \DateTimeZone('UTC')),
            $to === null ? null : new \DateTimeImmutable($to, new \DateTimeZone('UTC')),
            null,
        ));
    }

    public function user(int $id): AuthenticatedUser
    {
        $user = $this->directory->findById(UserId::fromInt($id)) ?? throw new \LogicException('Unknown test user.');

        return AuthenticatedUser::fromUser($user);
    }

    /**
     * A complete Brisbane draft by Amanda: [publicId, version].
     *
     * @return array{string, string}
     */
    public function completeDraft(): array
    {
        $created = $this->drafts->createDraft($this->user(self::SALES), [], null)->request;
        $saved = $this->drafts->saveDraft(
            $this->user(self::SALES),
            $created->publicId,
            $created->version,
            NprFixtures::validRequestAnswers(),
            NprFixtures::validVariants(),
            null,
        ) ?? throw new \LogicException('Draft not saved.');

        return [$saved->request->publicId, $saved->request->version];
    }

    /**
     * Amanda submits a complete draft: [publicId, version].
     *
     * @return array{string, string}
     */
    public function submitted(): array
    {
        [$id, $version] = $this->completeDraft();
        $view = $this->workflow->submit($this->user(self::SALES), $id, $version) ?? throw new \LogicException('Not submitted.');

        return [$id, $view->request->version];
    }

    /**
     * Submitted and endorsed by Shane, so with TSD: [publicId, version].
     *
     * @return array{string, string}
     */
    public function withTsd(): array
    {
        [$id, $version] = $this->submitted();
        $view = $this->workflow->endorse($this->user(self::SHANE), $id, $version, true, null) ?? throw new \LogicException('Not endorsed.');

        return [$id, $view->request->version];
    }

    /**
     * Section 10, from the portal's acceptance test.
     *
     * @return array<string, mixed>
     */
    public static function assessment(): array
    {
        return [
            'existing_product_fit' => ['state' => 'provided', 'value' => 'no'],
            'gap_nature' => ['state' => 'provided', 'value' => ['new_construction'], 'selectionMode' => 'all_required'],
            'technical_feasibility' => ['state' => 'provided', 'value' => 'yes'],
            'plant_capability' => ['state' => 'provided', 'value' => 'existing_plant'],
            'new_materials_required' => ['state' => 'provided', 'value' => 'no'],
            'testing_required' => ['state' => 'provided', 'value' => 'no'],
            'development_cost' => ['state' => 'provided', 'value' => ['amount' => 3500, 'currency' => 'AUD']],
            'development_duration' => ['state' => 'provided', 'value' => ['amount' => 3, 'unit' => 'weeks']],
            'conversion_cost' => ['state' => 'provided', 'value' => ['amount' => 950, 'currency' => 'AUD', 'per' => 'km']],
            'technical_risks' => ['state' => 'provided', 'value' => 'Tooling setup verification needed'],
            'assessment_notes' => ['state' => 'provided', 'value' => 'Verified in CableFit Pro.'],
        ];
    }

    /**
     * The `value` of one answer envelope, or null.
     *
     * @param array<string, mixed> $answers
     */
    public static function value(array $answers, string $fieldId): mixed
    {
        $answer = $answers[$fieldId] ?? null;

        return is_array($answer) ? ($answer['value'] ?? null) : null;
    }
}
