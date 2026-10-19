<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Mail\Persistence\InMemoryMailStore;
use TriCab\Core\Mail\Application\DTO\MailBlocker;
use TriCab\Core\Mail\Application\DTO\MailParty;
use TriCab\Core\Mail\Application\DTO\NewAttachment;
use TriCab\Core\Mail\Application\DTO\NewDecision;
use TriCab\Core\Mail\Application\DTO\NewLink;
use TriCab\Core\Mail\Application\Service\MailIntakeRecorder;
use TriCab\Core\Mail\Application\Service\MailReviewGuard;
use TriCab\Core\Mail\Domain\MailActor;
use TriCab\Core\Mail\Module;

/** The guard is the authority (plan I4.2): what is allowed, and the reasons for what is not. */
final class MailReviewGuardTest extends TestCase
{
    private InMemoryMailStore $store;

    private MailIntakeRecorder $recorder;

    protected function setUp(): void
    {
        $this->store = new InMemoryMailStore();
        $this->recorder = new MailIntakeRecorder($this->store);
    }

    /** @return array{allowed: list<string>, blockers: array<string, list<MailBlocker>>} */
    private function evaluate(string $threadId, bool $review = true): array
    {
        $record = $this->store->thread($threadId);
        self::assertNotNull($record);
        $permissions = $review ? [Module::PERMISSION_ACCESS, Module::PERMISSION_REVIEW] : [Module::PERMISSION_ACCESS];

        return MailReviewGuard::evaluate($record->summary, $record->assessment, new MailActor(7, 'Sam', 'sam@tricab.com', $permissions));
    }

    /**
     * @param array{allowed: list<string>, blockers: array<string, list<MailBlocker>>} $guard
     * @return list<string>
     */
    private static function kinds(array $guard, string $action): array
    {
        return array_map(static fn (MailBlocker $b): string => $b->kind, $guard['blockers'][$action] ?? []);
    }

    private function decide(string $threadId, string $label = 'uncertain'): void
    {
        $record = $this->store->thread($threadId);
        self::assertNotNull($record);
        $this->store->recordDecision($threadId, new NewDecision($record->assessment->id, $label === $record->assessment->label ? 'confirm' : 'correct', $label, 7, new MailParty('Sam', 'sam@tricab.com'), null, MailFixtures::at('2026-10-18 12:00:00')));
    }

    public function test_an_undecided_uncertain_thread_offers_draft_and_ignore_but_blocks_them_until_the_label_is_marked(): void
    {
        $t = MailFixtures::classified($this->recorder, 'g1', 'uncertain', 0.4)->threadId;
        $guard = $this->evaluate($t);

        self::assertSame(['mark_read', 'confirm_label', 'correct_label', 'create_draft', 'ignore'], $guard['allowed']);
        self::assertSame(['undecided_label'], self::kinds($guard, 'create_draft'));
        self::assertSame(['undecided_label'], self::kinds($guard, 'ignore'));
        self::assertSame([], $guard['blockers']['confirm_label'] ?? []);

        $this->decide($t);
        $after = $this->evaluate($t);
        self::assertSame([], $after['blockers']);
    }

    public function test_correcting_the_label_does_not_make_the_thread_ineligible(): void
    {
        $t = MailFixtures::classified($this->recorder, 'g2', 'uncertain', 0.4)->threadId;
        $this->decide($t, 'rfq');
        $guard = $this->evaluate($t);

        self::assertContains('create_draft', $guard['allowed']);
        self::assertSame([], $guard['blockers']);
    }

    public function test_the_injection_flag_blocks_both_actions_even_after_the_label_is_marked(): void
    {
        $t = MailFixtures::classified($this->recorder, 'g3', 'uncertain', 0.4, injection: true)->threadId;
        self::assertSame(['undecided_label', 'injection'], self::kinds($this->evaluate($t), 'create_draft'));

        $this->decide($t);
        $guard = $this->evaluate($t);
        self::assertSame(['injection'], self::kinds($guard, 'create_draft'));
        self::assertSame(['injection'], self::kinds($guard, 'ignore'));
        self::assertSame('The message contains text addressed to an assistant. Read it before acting.', $guard['blockers']['ignore'][0]->message);
    }

    public function test_only_uncertain_threads_can_be_drafted_or_ignored(): void
    {
        $t = MailFixtures::classified($this->recorder, 'g4', 'rfq', 0.95)->threadId;
        $guard = $this->evaluate($t);

        self::assertSame(['mark_read', 'confirm_label', 'correct_label'], $guard['allowed']);
        self::assertSame(['not_uncertain'], self::kinds($guard, 'create_draft'));
        self::assertSame(['not_uncertain'], self::kinds($guard, 'ignore'));
    }

    public function test_an_actioned_thread_is_refused_a_second_action_but_may_still_be_marked(): void
    {
        $t = MailFixtures::classified($this->recorder, 'g5', 'uncertain', 0.4)->threadId;
        $this->decide($t);
        $this->store->recordDraft($t, new NewLink('p', 'RFQ-D-1', 'x', 7, MailFixtures::at('2026-10-18 13:00:00')), new MailParty('Sam', 'sam@tricab.com'));
        $guard = $this->evaluate($t);

        self::assertSame(['already_actioned'], self::kinds($guard, 'create_draft'));
        self::assertSame(['already_actioned'], self::kinds($guard, 'ignore'));
        self::assertNotContains('create_draft', $guard['allowed']);
        // Marks are the labelled set (RFQ M5): they stay open after the thread is actioned.
        self::assertContains('correct_label', $guard['allowed']);
    }

    public function test_a_prefilter_skip_has_no_label_to_confirm_only_to_correct(): void
    {
        $t = MailFixtures::skipped($this->recorder, 'g6')->threadId;
        $guard = $this->evaluate($t);

        self::assertNotContains('confirm_label', $guard['allowed']);
        self::assertContains('correct_label', $guard['allowed']);
        self::assertSame(['not_uncertain'], self::kinds($guard, 'confirm_label'));
        self::assertSame(['not_uncertain'], self::kinds($guard, 'create_draft'));
    }

    public function test_someone_without_review_may_only_mark_read_or_unread(): void
    {
        $t = MailFixtures::classified($this->recorder, 'g7', 'uncertain', 0.4)->threadId;
        self::assertSame(['mark_read'], $this->evaluate($t, false)['allowed']);
        self::assertSame([], $this->evaluate($t, false)['blockers']);

        $read = MailFixtures::classified($this->recorder, 'g8', 'uncertain', 0.4, isRead: true)->threadId;
        self::assertSame(['mark_unread'], $this->evaluate($read, false)['allowed']);
    }

    public function test_attachments_do_not_change_what_is_allowed(): void
    {
        $t = MailFixtures::classified($this->recorder, 'g9', 'uncertain', 0.4, attachments: [new NewAttachment('a', 'f.pdf', 'application/pdf', 10)])->threadId;

        self::assertSame(['mark_read', 'confirm_label', 'correct_label', 'create_draft', 'ignore'], $this->evaluate($t)['allowed']);
    }
}
