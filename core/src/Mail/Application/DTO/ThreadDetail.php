<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

/** `MailThreadDetail`: a thread, with what this person may do and why the rest is refused. */
final readonly class ThreadDetail
{
    /**
     * @param list<string> $allowedActions
     * @param array<string, list<MailBlocker>> $blockers keyed by action
     */
    public function __construct(
        public ThreadRecord $record,
        public array $allowedActions,
        public array $blockers,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $blockers = [];
        foreach ($this->blockers as $action => $found) {
            $blockers[$action] = array_map(static fn (MailBlocker $b): array => $b->toArray(), $found);
        }

        return [
            'summary' => $this->record->summary->toArray(),
            'messages' => array_map(static fn (MessageView $m): array => $m->toArray(), $this->record->messages),
            'assessment' => $this->record->assessment->toArray(),
            'decision' => $this->record->decision?->toArray(),
            'activity' => array_map(static fn (ActivityView $a): array => $a->toArray(), $this->record->activity),
            'links' => array_map(static fn (LinkView $l): array => $l->toArray(), $this->record->links),
            'allowedActions' => $this->allowedActions,
            // An empty PHP array is a JSON list; the contract wants an object.
            'blockers' => $blockers === [] ? (object) [] : $blockers,
        ];
    }
}
