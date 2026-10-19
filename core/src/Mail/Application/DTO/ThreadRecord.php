<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

/** What the store knows about one thread: everything `MailThreadDetail` shows except the guard's answer. */
final readonly class ThreadRecord
{
    /**
     * @param list<MessageView> $messages oldest first
     * @param list<ActivityView> $activity oldest first
     * @param list<LinkView> $links
     */
    public function __construct(
        public ThreadSummary $summary,
        public array $messages,
        public AssessmentView $assessment,
        public ?DecisionView $decision,
        public array $activity,
        public array $links,
    ) {
    }
}
