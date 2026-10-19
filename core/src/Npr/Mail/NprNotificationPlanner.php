<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Mail;

use TriCab\Core\Npr\Domain\NprRequest;
use TriCab\Core\Npr\Domain\NprWorkflowEvent;

/**
 * Recipients per event: the matrix of npr-portal `config/notifications.json`
 * (for the events this workflow raises) and the "Email rules" of
 * docs/ACCESS_AND_ROUTING.md.
 *
 *  - addresses are read from the request as it stands after the transition, then
 *    lower-cased and de-duplicated (a To address is never repeated in Cc);
 *  - the Deputy CEO is copied on `manager_endorsed` ONLY for the first TSD
 *    handoff, and on `screening_decided`; nowhere else. The address is the
 *    `NPR_DEPUTY_CEO_EMAIL` setting, never code;
 *  - the TSD queue address is the `NPR_TSD_QUEUE_EMAIL` setting;
 *  - a required role with no address is returned in `missing` so it is recorded
 *    as a failed delivery. The TSD owner may legitimately not be named yet
 *    (a return before assignment), so that role is optional.
 */
final class NprNotificationPlanner
{
    private const MANAGER = 'assigned_manager';
    private const REQUESTER = 'requester';
    private const TSD_QUEUE = 'tsd_queue';
    private const DEPUTY_CEO = 'deputy_ceo';
    private const NEW_OWNER = 'new_tsd_owner';
    private const OWNER = 'tsd_owner';
    private const SIGNATORY = 'assigned_signatory';

    /** @var array<string, array{to: list<string>, cc: list<string>}> */
    private const MATRIX = [
        NprWorkflowEvent::SALES_SUBMITTED => ['to' => [self::MANAGER], 'cc' => [self::REQUESTER]],
        NprWorkflowEvent::SALES_RESUBMITTED => ['to' => [self::MANAGER], 'cc' => [self::REQUESTER, self::OWNER]],
        NprWorkflowEvent::MANAGER_RETURNED => ['to' => [self::REQUESTER], 'cc' => [self::MANAGER]],
        NprWorkflowEvent::MANAGER_REJECTED => ['to' => [self::REQUESTER], 'cc' => [self::MANAGER]],
        NprWorkflowEvent::MANAGER_ENDORSED => ['to' => [self::TSD_QUEUE], 'cc' => [self::REQUESTER, self::MANAGER, self::DEPUTY_CEO]],
        NprWorkflowEvent::TSD_ACKNOWLEDGED => ['to' => [self::REQUESTER, self::MANAGER], 'cc' => []],
        NprWorkflowEvent::TSD_ASSIGNED => ['to' => [self::REQUESTER, self::MANAGER, self::NEW_OWNER], 'cc' => []],
        NprWorkflowEvent::ASSESSMENT_SAVED => ['to' => [self::REQUESTER, self::MANAGER], 'cc' => []],
        NprWorkflowEvent::TSD_RETURNED => ['to' => [self::REQUESTER], 'cc' => [self::MANAGER, self::OWNER]],
        NprWorkflowEvent::SCREENING_REQUESTED => ['to' => [self::SIGNATORY], 'cc' => [self::REQUESTER, self::MANAGER, self::OWNER]],
        NprWorkflowEvent::SCREENING_DECIDED => ['to' => [self::REQUESTER, self::MANAGER], 'cc' => [self::OWNER, self::DEPUTY_CEO]],
    ];

    public function __construct(
        private readonly string $tsdQueueEmail,
        private readonly string $deputyCeoEmail,
    ) {
    }

    public function plan(NprWorkflowEvent $event, NprRequest $request): NprNotificationPlan
    {
        $definition = self::MATRIX[$event->name] ?? null;
        if ($definition === null) {
            return new NprNotificationPlan([], [], []);
        }

        $to = [];
        $cc = [];
        $missing = [];
        foreach (['to' => $definition['to'], 'cc' => $definition['cc']] as $type => $roles) {
            foreach ($roles as $role) {
                if ($role === self::DEPUTY_CEO && !$this->copiesDeputyCeo($event)) {
                    continue;
                }
                $address = $this->address($role, $request);
                if ($address === '') {
                    if (!in_array($role, [self::OWNER], true)) {
                        $missing[$role . '|' . $type] = ['role' => $role, 'type' => $type];
                    }
                    continue;
                }
                if ($type === 'to') {
                    $to[] = $address;
                } else {
                    $cc[] = $address;
                }
            }
        }
        $to = array_values(array_unique($to));
        $cc = array_values(array_diff(array_unique($cc), $to));

        return new NprNotificationPlan($to, $cc, array_values($missing));
    }

    /** Robby: the first TSD handoff and the final outcome, nothing in between. */
    private function copiesDeputyCeo(NprWorkflowEvent $event): bool
    {
        return $event->name === NprWorkflowEvent::SCREENING_DECIDED
            || ($event->name === NprWorkflowEvent::MANAGER_ENDORSED && $event->firstTsdHandoff);
    }

    private function address(string $role, NprRequest $request): string
    {
        $address = match ($role) {
            self::REQUESTER => $request->requesterEmail,
            self::MANAGER => $request->manager?->email,
            self::OWNER, self::NEW_OWNER => $request->tsdOwner?->email,
            self::SIGNATORY => $request->signatory?->email,
            self::TSD_QUEUE => $this->tsdQueueEmail,
            self::DEPUTY_CEO => $this->deputyCeoEmail,
            default => null,
        };

        return strtolower(trim((string) $address));
    }
}
