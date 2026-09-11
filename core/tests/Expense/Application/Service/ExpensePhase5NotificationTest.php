<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Expense\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Expense\Email\ExpenseEmailTemplates;
use TriCab\Api\Expense\Observability\ExpenseEventLogger;
use TriCab\Api\Expense\Persistence\GraphExpenseNotificationPort;
use TriCab\Api\Expense\Persistence\InMemoryExpenseAuditRecorder;
use TriCab\Api\Expense\Persistence\InMemoryExpenseProfileRepository;
use TriCab\Api\Expense\Persistence\InMemoryExpenseReceiptRepository;
use TriCab\Api\Expense\Persistence\ProfileFinanceCapabilityResolver;
use TriCab\Api\Expense\Persistence\ProfileManagerResolver;
use TriCab\Api\Expense\Persistence\RecordingExpenseNotificationPort;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\Service\ExpenseAccessService;
use TriCab\Core\Expense\Application\Service\ExpenseApprovalService;
use TriCab\Core\Expense\Application\Service\ExpenseReceiptAssembler;
use TriCab\Core\Expense\Application\Service\SubmitExpenseReceiptService;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;
use TriCab\Core\Expense\Domain\Exception\ExpenseConcurrencyConflict;
use TriCab\Core\Expense\Domain\Exception\InvalidExpenseStatusTransition;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseCategory;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseCurrency;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseMoney;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Tests\Expense\Doubles\ConflictingExpenseReceiptRepository;
use TriCab\Core\Tests\Expense\Doubles\ExpensePhase5Fixtures;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;

/**
 * Phase 5 notification behaviour, driven through the real Graph adapter with a
 * fake HTTP transport so recipient resolution, the safe-send rails, and the
 * retry policy are all exercised without touching Graph.
 */
final class ExpensePhase5NotificationTest extends TestCase
{
    private const OWNER_EMAIL = 'owner@tricab.com';

    private const MANAGER_EMAIL = 'mgr@tricab.com';

    private const FINANCE_EMAIL = 'finance@tricab.com';

    private InMemoryExpenseReceiptRepository $receipts;
    private InMemoryExpenseProfileRepository $profiles;
    private InMemoryExpenseAuditRecorder $audit;
    private ExpenseAccessService $access;
    private FixedClock $clock;
    private FakeJsonHttpClient $http;

    private UserId $ownerId;
    private UserId $managerId;
    private UserId $financeId;

    protected function setUp(): void
    {
        $this->receipts = new InMemoryExpenseReceiptRepository();
        $this->profiles = new InMemoryExpenseProfileRepository();
        $this->audit = new InMemoryExpenseAuditRecorder();
        $this->clock = FixedClock::utc('2026-09-15T10:00:00Z');
        $this->access = new ExpenseAccessService($this->profiles, new ProfileFinanceCapabilityResolver());
        $this->http = new FakeJsonHttpClient();
        $this->http->body = '{"access_token":"graph-token","expires_in":3600}';

        $this->ownerId = UserId::fromInt(10);
        $this->managerId = UserId::fromInt(11);
        $this->financeId = UserId::fromInt(12);

        $this->profiles->save(new ExpenseProfile($this->ownerId, 'owner', self::OWNER_EMAIL, true, false, false, 'mgr', $this->managerId));
        $this->profiles->save(new ExpenseProfile($this->managerId, 'mgr', self::MANAGER_EMAIL, true, false, false, null, null));
        $this->profiles->save(new ExpenseProfile($this->financeId, 'finance', self::FINANCE_EMAIL, true, true, false, null, null));
    }

    public function test_draftThenSubmitMailsOwnerAndManager(): void
    {
        $port = $this->graphPort(sendingEnabled: true);
        $receipt = $this->seedDraft();

        $this->submitService($port)->execute(
            $this->actor($this->ownerId, self::OWNER_EMAIL),
            $receipt->publicId()->toString(),
            $receipt->rowVersion()?->toBase64() ?? '',
            Module::SUBMIT_DRAFT_THEN_SUBMIT,
        );

        self::assertSame([self::OWNER_EMAIL, self::MANAGER_EMAIL], $this->sentRecipients());
    }

    public function test_immediateSubmitMailsOwnerOnly(): void
    {
        $port = $this->graphPort(sendingEnabled: true);
        $receipt = $this->seedDraft();

        $this->submitService($port)->execute(
            $this->actor($this->ownerId, self::OWNER_EMAIL),
            $receipt->publicId()->toString(),
            $receipt->rowVersion()?->toBase64() ?? '',
            Module::SUBMIT_IMMEDIATE,
        );

        self::assertSame([self::OWNER_EMAIL], $this->sentRecipients());
    }

    public function test_approveRejectAndSendBackMapToTheRightAudience(): void
    {
        $port = $this->graphPort(sendingEnabled: true);
        $approvals = $this->approvalService($port);

        $approved = $this->seedPending('a');
        $approvals->approve(
            $this->actor($this->managerId, self::MANAGER_EMAIL),
            $approved->publicId()->toString(),
            $approved->rowVersion()?->toBase64() ?? '',
        );
        self::assertSame([self::OWNER_EMAIL], $this->sentRecipients(), 'approval notifies the owner');
        self::assertSame(
            ['TriExpense: receipt approved — Vendor A 110 AUD'],
            $this->sentSubjects(),
        );

        $this->resetTransport();
        $rejected = $this->seedPending('b');
        $approvals->reject(
            $this->actor($this->managerId, self::MANAGER_EMAIL),
            $rejected->publicId()->toString(),
            'Missing tax invoice',
            $rejected->rowVersion()?->toBase64() ?? '',
        );
        self::assertSame([self::OWNER_EMAIL], $this->sentRecipients(), 'rejection notifies the owner');
        self::assertStringContainsString('rejected', $this->sentSubjects()[0]);
        self::assertStringContainsString('Missing tax invoice', $this->sentBodies()[0]);

        // Send-back returns an approved receipt to pending, so both the owner
        // (who must fix it) and the manager (who must approve again) hear about it.
        $this->resetTransport();
        $reloaded = $this->receipts->findByPublicId($approved->publicId());
        self::assertNotNull($reloaded);
        $approvals->sendBack(
            $this->actor($this->financeId, self::FINANCE_EMAIL),
            $reloaded->publicId()->toString(),
            $reloaded->rowVersion()?->toBase64() ?? '',
            'Recode to 64100',
        );
        self::assertSame([self::OWNER_EMAIL, self::MANAGER_EMAIL], $this->sentRecipients());
        self::assertStringContainsString('Recode to 64100', $this->sentBodies()[0]);
    }

    public function test_chasedBankTransactionMailsTheCardholder(): void
    {
        $port = $this->graphPort(sendingEnabled: true);
        $port->notifyChaseBankTx(ExpensePhase5Fixtures::bankFeedEntry('chase@tricab.com'));

        self::assertSame(['chase@tricab.com'], $this->sentRecipients());
        self::assertStringContainsString('receipt required for card transaction', $this->sentSubjects()[0]);
        self::assertStringContainsString('Cafe Melbourne', $this->sentBodies()[0]);
    }

    public function test_testRecipientOverrideRedirectsEveryMessage(): void
    {
        $logger = new ExpenseEventLogger(retainForTests: true);
        $port = $this->graphPort(sendingEnabled: true, testRecipient: 'safe-inbox@tricab.com', logger: $logger);
        $receipt = $this->seedDraft();

        $this->submitService($port)->execute(
            $this->actor($this->ownerId, self::OWNER_EMAIL),
            $receipt->publicId()->toString(),
            $receipt->rowVersion()?->toBase64() ?? '',
            Module::SUBMIT_DRAFT_THEN_SUBMIT,
        );

        self::assertSame(
            ['safe-inbox@tricab.com', 'safe-inbox@tricab.com'],
            $this->sentRecipients(),
            'both intended recipients are redirected to the safe inbox',
        );

        $sent = array_values(array_filter(
            $logger->recorded(),
            static fn (array $entry): bool => $entry['event'] === 'expense.email.sent',
        ));
        self::assertCount(2, $sent);
        self::assertTrue($sent[0]['redirected']);
        self::assertSame('o****@tricab.com', $sent[0]['intendedRecipient'], 'intended address is masked, not dumped');
        self::assertSame('m**@tricab.com', $sent[1]['intendedRecipient']);
    }

    public function test_disabledEmailResolvesRecipientsButNeverCallsGraph(): void
    {
        $logger = new ExpenseEventLogger(retainForTests: true);
        $port = $this->graphPort(sendingEnabled: false, logger: $logger);
        $receipt = $this->seedDraft();

        $this->submitService($port)->execute(
            $this->actor($this->ownerId, self::OWNER_EMAIL),
            $receipt->publicId()->toString(),
            $receipt->rowVersion()?->toBase64() ?? '',
            Module::SUBMIT_DRAFT_THEN_SUBMIT,
        );

        self::assertSame([], $this->http->calls, 'no HTTP call of any kind, not even a token request');

        $suppressed = array_values(array_filter(
            $logger->recorded(),
            static fn (array $entry): bool => $entry['event'] === 'expense.email.suppressed',
        ));
        self::assertCount(2, $suppressed, 'intent is still recorded for both recipients');
        self::assertSame('email_disabled', $suppressed[0]['reason']);
    }

    public function test_replayedApprovalProducesNoSecondMail(): void
    {
        $inner = $this->graphPort(sendingEnabled: true);
        $port = new RecordingExpenseNotificationPort($inner);
        $approvals = $this->approvalService($port);
        $receipt = $this->seedPending('c');

        $rowVersion = $receipt->rowVersion()?->toBase64() ?? '';
        $approvals->approve(
            $this->actor($this->managerId, self::MANAGER_EMAIL),
            $receipt->publicId()->toString(),
            $rowVersion,
        );
        self::assertCount(1, $port->events());
        self::assertSame([self::OWNER_EMAIL], $this->sentRecipients());

        // A double-clicked Approve replays the same request. The receipt is
        // already approved, so the transition is refused before any save — and
        // therefore before any notification.
        $this->expectException(InvalidExpenseStatusTransition::class);
        try {
            $approvals->approve(
                $this->actor($this->managerId, self::MANAGER_EMAIL),
                $receipt->publicId()->toString(),
                $rowVersion,
            );
        } finally {
            self::assertCount(1, $port->events(), 'the replay produced no second notification');
            self::assertSame([self::OWNER_EMAIL], $this->sentRecipients());
        }
    }

    public function test_concurrencyConflictOnSaveSendsNoMail(): void
    {
        // Another writer bumped the row between this request's read and its save,
        // which is what Azure SQL reports as a rowversion mismatch. The receipt
        // did not change, so nobody may be told that it did.
        $inner = $this->graphPort(sendingEnabled: true);
        $port = new RecordingExpenseNotificationPort($inner);
        $receipt = $this->seedPending('f');

        $approvals = new ExpenseApprovalService(
            $this->access,
            new ConflictingExpenseReceiptRepository($this->receipts),
            $port,
            $this->audit,
            new ExpenseReceiptAssembler(),
            $this->clock,
        );

        try {
            $approvals->approve(
                $this->actor($this->managerId, self::MANAGER_EMAIL),
                $receipt->publicId()->toString(),
                $receipt->rowVersion()?->toBase64() ?? '',
            );
            self::fail('expected ExpenseConcurrencyConflict');
        } catch (ExpenseConcurrencyConflict) {
            // expected
        }

        self::assertSame([], $port->events(), 'no notification for a write that did not land');
        self::assertSame([], $this->sentRecipients());
    }

    public function test_throttledSendIsRetriedAndHonoursRetryAfter(): void
    {
        $logger = new ExpenseEventLogger(retainForTests: true);
        $port = $this->graphPort(sendingEnabled: true, logger: $logger);

        // Token, then a throttled sendMail, then a successful retry. The token is
        // cached, so the retry does not fetch a second one.
        $this->http->queue = [
            ['status' => 200, 'body' => '{"access_token":"graph-token","expires_in":3600}'],
            ['status' => 429, 'body' => '', 'headers' => ['retry-after' => '0']],
            ['status' => 202, 'body' => ''],
        ];

        $port->notifyChaseBankTx(ExpensePhase5Fixtures::bankFeedEntry('throttled@tricab.com'));

        self::assertSame([], $this->http->queue, 'all queued responses were consumed, so the 429 was retried');
        self::assertCount(2, $this->sendMailCalls(), 'the throttled send was attempted twice');
        self::assertSame(
            ['expense.email.sent'],
            array_column($logger->recorded(), 'event'),
        );
    }

    public function test_authorizationFailureIsNotRetried(): void
    {
        $logger = new ExpenseEventLogger(retainForTests: true);
        $port = $this->graphPort(sendingEnabled: true, logger: $logger);

        $this->http->queue = [
            ['status' => 200, 'body' => '{"access_token":"graph-token","expires_in":3600}'],
            ['status' => 403, 'body' => '{"error":{"code":"ErrorAccessDenied"}}'],
        ];

        $port->notifyChaseBankTx(ExpensePhase5Fixtures::bankFeedEntry('denied@tricab.com'));

        self::assertSame([], $this->http->queue);
        self::assertCount(2, $this->http->calls, 'one token call plus one sendMail — no retry on 403');
        $failed = $logger->recorded()[0];
        self::assertSame('expense.email.failed', $failed['event']);
        self::assertSame(403, $failed['status']);
    }

    public function test_transportFailureIsLoggedAndDoesNotBreakTheTransition(): void
    {
        $logger = new ExpenseEventLogger(retainForTests: true);
        $port = $this->graphPort(sendingEnabled: true, logger: $logger);
        $this->http->transportError = new RuntimeException('HTTP request failed.');

        $receipt = $this->seedPending('d');
        $details = $this->approvalService($port)->approve(
            $this->actor($this->managerId, self::MANAGER_EMAIL),
            $receipt->publicId()->toString(),
            $receipt->rowVersion()?->toBase64() ?? '',
        );

        self::assertSame('approved', $details->status, 'the approval still committed');
        self::assertSame('expense.email.failed', $logger->recorded()[0]['event']);
        self::assertSame('transport_error', $logger->recorded()[0]['reason']);
    }

    public function test_missingProfileEmailIsSkippedRatherThanGuessed(): void
    {
        $logger = new ExpenseEventLogger(retainForTests: true);
        $port = $this->graphPort(sendingEnabled: true, logger: $logger);

        // A receipt owned by a user with no ExpenseProfile has no resolvable address.
        $orphan = ExpensePhase5Fixtures::pendingReceipt(
            'e',
            UserId::fromInt(999),
            UserId::fromInt(998),
        );
        $port->notifyApproved($orphan);

        self::assertSame([], $this->http->calls);
        self::assertSame('expense.email.skipped', $logger->recorded()[0]['event']);
        self::assertSame('no_resolvable_recipient', $logger->recorded()[0]['reason']);
    }

    public function test_loggerRedactsTokensSasUrlsAndDocumentBytes(): void
    {
        $logger = new ExpenseEventLogger(retainForTests: true);
        $logger->log('expense.email.sent', [
            'accessToken' => 'should-never-appear',
            'clientSecret' => 'should-never-appear',
            'sasUrl' => 'should-never-appear',
            'imageBytes' => 'should-never-appear',
            'authorization' => 'Bearer should-never-appear',
            'downloadUrl' => 'https://acct.blob.core.windows.net/triexpense/r.jpg?sp=r&se=2026-01-01&sig=abc',
            'notification' => 'approved',
            'status' => 202,
        ]);

        $entry = $logger->recorded()[0];
        self::assertSame(['event', 'notification', 'status'], array_keys($entry));
        self::assertStringNotContainsString('should-never-appear', json_encode($entry, JSON_THROW_ON_ERROR));
    }

    public function test_loggerTruncatesLongValues(): void
    {
        $logger = new ExpenseEventLogger(retainForTests: true);
        $logger->log('expense.ocr.failed', ['reason' => str_repeat('a', 500)]);

        $reason = $logger->recorded()[0]['reason'];
        self::assertIsString($reason);
        self::assertLessThan(500, strlen($reason));
    }

    private function graphPort(
        bool $sendingEnabled,
        ?string $testRecipient = null,
        ?ExpenseEventLogger $logger = null,
    ): GraphExpenseNotificationPort {
        return new GraphExpenseNotificationPort(
            http: $this->http,
            profiles: $this->profiles,
            templates: new ExpenseEmailTemplates('https://apps.tricab.com'),
            logger: $logger ?? new ExpenseEventLogger(retainForTests: true),
            tenantId: 'tenant',
            clientId: 'client',
            clientSecret: 'secret',
            mailFrom: 'ap@tricab.com',
            sendingEnabled: $sendingEnabled,
            testRecipient: $testRecipient,
            pauseBetweenRetries: false,
        );
    }

    private function submitService(object $port): SubmitExpenseReceiptService
    {
        /** @var \TriCab\Core\Expense\Contract\ExpenseNotificationPort $port */
        return new SubmitExpenseReceiptService(
            $this->access,
            $this->receipts,
            $this->profiles,
            new ProfileManagerResolver($this->profiles),
            $port,
            $this->audit,
            new ExpenseReceiptAssembler(),
            $this->clock,
        );
    }

    private function approvalService(object $port): ExpenseApprovalService
    {
        /** @var \TriCab\Core\Expense\Contract\ExpenseNotificationPort $port */
        return new ExpenseApprovalService(
            $this->access,
            $this->receipts,
            $port,
            $this->audit,
            new ExpenseReceiptAssembler(),
            $this->clock,
        );
    }

    private function seedDraft(): ExpenseReceipt
    {
        $now = new DateTimeImmutable('2026-09-01T00:00:00Z', new DateTimeZone('UTC'));
        $receipt = ExpenseReceipt::createDraft(
            publicId: ExpenseReceiptPublicId::fromString('22222222-2222-4222-8222-222222222221'),
            ownerUserId: $this->ownerId,
            vendorName: 'Vendor Draft',
            totalAmount: ExpenseMoney::fromString('110.00'),
            gstAmount: ExpenseMoney::fromString('10.00'),
            currency: ExpenseCurrency::fromString('AUD'),
            gstType: Module::GST_INCLUSIVE,
            receiptDate: $now,
            category: ExpenseCategory::fromLegacy('64100'),
            costCentre: 'MEL',
            description: null,
            attachment: null,
            now: $now,
            managerUserId: null,
        );

        return $this->receipts->save($receipt);
    }

    private function seedPending(string $suffix): ExpenseReceipt
    {
        return $this->receipts->save(
            ExpensePhase5Fixtures::pendingReceipt($suffix, $this->ownerId, $this->managerId),
        );
    }

    private function actor(UserId $id, string $email): ExpenseActor
    {
        return new ExpenseActor($id, 'pub-' . $id->toInt(), $email, [Module::PERMISSION_ACCESS], ['employee']);
    }

    private function resetTransport(): void
    {
        $this->http->calls = [];
    }

    /**
     * @return list<string>
     */
    private function sentRecipients(): array
    {
        $recipients = [];
        foreach ($this->sendMailCalls() as $call) {
            /** @var array<string, mixed> $message */
            $message = $call['payload']['message'];
            $recipients[] = (string) $message['toRecipients'][0]['emailAddress']['address'];
        }

        return $recipients;
    }

    /**
     * @return list<string>
     */
    private function sentSubjects(): array
    {
        return array_map(
            static fn (array $call): string => (string) $call['payload']['message']['subject'],
            $this->sendMailCalls(),
        );
    }

    /**
     * @return list<string>
     */
    private function sentBodies(): array
    {
        return array_map(
            static fn (array $call): string => (string) $call['payload']['message']['body']['content'],
            $this->sendMailCalls(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sendMailCalls(): array
    {
        return array_values(array_filter(
            $this->http->calls,
            static fn (array $call): bool => str_contains($call['url'], '/sendMail'),
        ));
    }
}
