<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Expense\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Expense\Persistence\InMemoryExpenseAuditRecorder;
use TriCab\Api\Expense\Persistence\InMemoryExpenseBankFeedRepository;
use TriCab\Api\Expense\Persistence\InMemoryExpensePeriodRepository;
use TriCab\Api\Expense\Persistence\InMemoryExpenseProfileRepository;
use TriCab\Api\Expense\Persistence\InMemoryExpenseReceiptRepository;
use TriCab\Api\Expense\Persistence\NullExpenseNotificationPort;
use TriCab\Api\Expense\Persistence\ProfileFinanceCapabilityResolver;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Application\Service\CloseExpensePeriodService;
use TriCab\Core\Expense\Application\Service\ExpenseAccessService;
use TriCab\Core\Expense\Application\Service\ExportEmployeeReceiptsCsvService;
use TriCab\Core\Expense\Application\Service\ExportFinanceReconciliationCsvService;
use TriCab\Core\Expense\Application\Service\GetOrCreateExpensePeriodService;
use TriCab\Core\Expense\Application\Service\ImportExpenseBankFeedService;
use TriCab\Core\Expense\Application\Service\OffsetExpenseBankFeedService;
use TriCab\Core\Expense\Application\Service\PreCloseExpensePeriodService;
use TriCab\Core\Expense\Application\Service\ResolveExpenseBankFeedService;
use TriCab\Core\Expense\Application\Service\ResolveExpenseReceiptFinanceService;
use TriCab\Core\Expense\Application\Service\RunExpenseBankFeedMatchingService;
use TriCab\Core\Expense\Application\Service\UndoOffsetExpenseBankFeedService;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;
use TriCab\Core\Expense\Domain\Service\ExpenseAuBankFeedCsvParser;
use TriCab\Core\Expense\Domain\Service\ExpenseBankFeedMatcher;
use TriCab\Core\Expense\Domain\Service\ExpenseNearMissDiagnostics;
use TriCab\Core\Expense\Domain\Service\ExpenseNzBankFeedCsvParser;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseCategory;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseCurrency;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseMoney;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;

final class ExpenseFinanceServicesTest extends TestCase
{
    private InMemoryExpenseReceiptRepository $receipts;
    private InMemoryExpenseProfileRepository $profiles;
    private InMemoryExpenseBankFeedRepository $bankFeeds;
    private InMemoryExpensePeriodRepository $periods;
    private InMemoryExpenseAuditRecorder $audit;
    private NullExpenseNotificationPort $notifications;
    private ExpenseAccessService $access;
    private FixedClock $clock;

    private UserId $ownerId;
    private UserId $financeId;
    private UserId $teAdminId;
    private ExpenseActor $finance;
    private ExpenseActor $teAdmin;

    protected function setUp(): void
    {
        $this->receipts = new InMemoryExpenseReceiptRepository();
        $this->profiles = new InMemoryExpenseProfileRepository();
        $this->bankFeeds = new InMemoryExpenseBankFeedRepository();
        $this->periods = new InMemoryExpensePeriodRepository();
        $this->audit = new InMemoryExpenseAuditRecorder();
        $this->notifications = new NullExpenseNotificationPort();
        $this->clock = FixedClock::utc('2026-09-15T10:00:00Z');
        $this->access = new ExpenseAccessService($this->profiles, new ProfileFinanceCapabilityResolver());

        $this->ownerId = UserId::fromInt(10);
        $this->financeId = UserId::fromInt(20);
        $this->teAdminId = UserId::fromInt(30);

        $this->profiles->save(new ExpenseProfile(
            $this->ownerId,
            'owner',
            'owner@tricab.com',
            true,
            false,
            false,
            null,
            null,
        ));
        $this->profiles->save(new ExpenseProfile(
            $this->financeId,
            'finance',
            'finance@tricab.com',
            true,
            true,
            false,
            null,
            null,
        ));
        $this->profiles->save(new ExpenseProfile(
            $this->teAdminId,
            'teadmin',
            'teadmin@tricab.com',
            true,
            false,
            true,
            null,
            null,
        ));

        $this->finance = $this->actor($this->financeId, 'finance@tricab.com');
        $this->teAdmin = $this->actor($this->teAdminId, 'teadmin@tricab.com');
    }

    public function test_teAdminWithoutFinanceIsDenied(): void
    {
        $this->expectException(ExpenseAccessDenied::class);
        $this->importService()->execute($this->teAdmin, 'AU', $this->auCsvRow('REF1', 'owner@tricab.com', '100.00', '2026-09-10'));
    }

    public function test_auAndNzImport(): void
    {
        $au = $this->importService()->execute(
            $this->finance,
            'AU',
            $this->auCsvHeader() . "\n" . $this->auCsvData('AU-1', 'owner@tricab.com', '12.50', '2026-09-10', 'Woolworths'),
        );
        self::assertSame(1, $au['imported']);
        self::assertCount(1, $au['entries']);

        $details = str_pad('CAFE NZ', 40)
            . str_pad('Auckland', 25)
            . str_pad('NZL', 3)
            . '1010';
        $nzCsv = "Transaction Date,Amount,Currency,Cardholder Email,Details,Type\n"
            . "2026-09-11,-45.00,NZD,owner@tricab.com,\"{$details}\",purchase\n";
        $nz = $this->importService()->execute(
            $this->finance,
            'NZ',
            $nzCsv,
            'nz_statement_2026-09-30.csv',
        );
        self::assertSame(1, $nz['imported']);
        $entry = $this->bankFeeds->findByPublicId(
            \TriCab\Core\Expense\Domain\ValueObject\ExpenseBankFeedPublicId::fromString($nz['entries'][0]),
        );
        self::assertNotNull($entry);
        self::assertSame('NZ', $entry->country());
        self::assertStringStartsWith('NZ-2026-09-30-', $entry->bankReference());
        self::assertSame('45', $entry->amount()->amount()); // sign flipped for charge
        self::assertSame('CAFE NZ', $entry->merchantName());
        self::assertSame('Auckland', $entry->merchantCity());
    }

    public function test_autoMatchZeroOneAmbiguousAndPaymentNotExcluded(): void
    {
        $this->seedPendingReceipt('100.00', '2026-09-10');
        $this->seedPendingReceipt('200.00', '2026-09-10', publicSuffix: 'b');
        $this->seedPendingReceipt('200.05', '2026-09-09', publicSuffix: 'c');

        $import = $this->importService()->execute(
            $this->finance,
            'AU',
            $this->auCsvHeader() . "\n"
            . $this->auCsvData('M0', 'owner@tricab.com', '50.00', '2026-09-10', 'None') . "\n"
            . $this->auCsvData('M1', 'owner@tricab.com', '100.00', '2026-09-10', 'MatchMe', transactionType: 'payment') . "\n"
            . $this->auCsvData('M2a', 'owner@tricab.com', '200.00', '2026-09-11', 'Amb1') . "\n"
            . $this->auCsvData('M2b', 'owner@tricab.com', '200.05', '2026-09-09', 'Amb2'),
        );

        $result = $import['matching'];
        self::assertIsArray($result);
        self::assertSame(1, $result['matched']); // M1 payment type still matches single 100.00 receipt
        self::assertSame(1, $result['unmatched']); // M0
        self::assertGreaterThanOrEqual(1, $result['ambiguous']); // M2a/M2b share two near receipts

        $matched = $this->bankFeeds->findByBankReference('M1');
        self::assertNotNull($matched);
        self::assertTrue($matched->matchStatus()->isMatched());
        self::assertNotNull($matched->receiptId());
        self::assertSame(Module::TYPE_PAYMENT, $matched->transactionType());
    }

    public function test_offsetAndUndo(): void
    {
        $this->importService()->execute(
            $this->finance,
            'AU',
            $this->auCsvHeader() . "\n"
            . $this->auCsvData('OFF-A', 'owner@tricab.com', '80.00', '2026-09-01', 'A') . "\n"
            . $this->auCsvData('OFF-B', 'owner@tricab.com', '-80.00', '2026-09-05', 'B'),
        );
        $a = $this->bankFeeds->findByBankReference('OFF-A');
        $b = $this->bankFeeds->findByBankReference('OFF-B');
        self::assertNotNull($a);
        self::assertNotNull($b);

        $offset = new OffsetExpenseBankFeedService($this->access, $this->bankFeeds, $this->audit, $this->clock);
        $out = $offset->execute(
            $this->finance,
            $a->publicId()->toString(),
            $b->publicId()->toString(),
            $a->rowVersion()?->toBase64() ?? '',
            $b->rowVersion()?->toBase64() ?? '',
        );
        self::assertSame(Module::RESOLUTION_OFFSET, $out['a']['resolution']);
        self::assertSame($b->id(), $out['a']['offsetBankFeedEntryId']);

        $a2 = $this->bankFeeds->findByBankReference('OFF-A');
        self::assertNotNull($a2);
        $undo = new UndoOffsetExpenseBankFeedService($this->access, $this->bankFeeds, $this->audit);
        $undone = $undo->execute($this->finance, $a2->publicId()->toString(), $a2->rowVersion()?->toBase64() ?? '');
        self::assertNull($undone['entry']['resolution']);
        $b2 = $this->bankFeeds->findByBankReference('OFF-B');
        self::assertNotNull($b2);
        self::assertNull($b2->resolution());
    }

    public function test_bankAndReceiptResolutions(): void
    {
        $this->importService()->execute(
            $this->finance,
            'AU',
            $this->auCsvHeader() . "\n" . $this->auCsvData('CHASE1', 'owner@tricab.com', '10.00', '2026-09-10', 'X'),
        );
        $entry = $this->bankFeeds->findByBankReference('CHASE1');
        self::assertNotNull($entry);

        $resolveBank = new ResolveExpenseBankFeedService(
            $this->access,
            $this->bankFeeds,
            $this->notifications,
            $this->audit,
            $this->clock,
        );
        $resolveBank->execute(
            $this->finance,
            $entry->publicId()->toString(),
            Module::RESOLUTION_CHASED,
            'please chase',
            $entry->rowVersion()?->toBase64() ?? '',
        );
        $events = $this->notifications->events();
        self::assertSame('chase_bank_tx', $events[array_key_last($events)]['event']);

        $receipt = $this->seedPendingReceipt('22.00', '2026-09-12');
        $resolveReceipt = new ResolveExpenseReceiptFinanceService(
            $this->access,
            $this->receipts,
            $this->audit,
            $this->clock,
        );
        $before = count($this->notifications->events());
        $forced = $resolveReceipt->execute(
            $this->finance,
            $receipt->publicId()->toString(),
            Module::RESOLUTION_FORCE_APPROVED,
            'ok',
            $receipt->rowVersion()?->toBase64() ?? '',
        );
        self::assertSame('approved', $forced['status']);
        self::assertSame(Module::RESOLUTION_FORCE_APPROVED, $forced['resolution']);
        self::assertCount($before, $this->notifications->events());

        $chasedReceipt = $this->seedPendingReceipt('33.00', '2026-09-13', publicSuffix: 'c');
        $resolveReceipt->execute(
            $this->finance,
            $chasedReceipt->publicId()->toString(),
            Module::RESOLUTION_CHASED,
            'chase receipt',
            $chasedReceipt->rowVersion()?->toBase64() ?? '',
        );
        self::assertCount($before, $this->notifications->events()); // receipt chased: NO email
    }

    public function test_periodPreCloseAndClose(): void
    {
        $this->importService()->execute(
            $this->finance,
            'AU',
            $this->auCsvHeader() . "\n"
            . $this->auCsvData('P1', 'owner@tricab.com', '15.00', '2026-09-08', 'Shop', statementDate: '2026-09-30'),
        );
        $periodView = $this->periodService()->execute($this->finance, 'AU');
        $periodId = $periodView['publicId'];

        $pre = new PreCloseExpensePeriodService($this->access, $this->periods, $this->bankFeeds, $this->receipts);
        $blocked = $pre->execute($this->finance, $periodId);
        self::assertFalse($blocked['canClose']);
        self::assertContains('unmatched_bank', array_column($blocked['blockers'], 'type'));

        // Resolve unmatched bank so close can proceed (no receipts in range)
        $entry = $this->bankFeeds->findByBankReference('P1');
        self::assertNotNull($entry);
        (new ResolveExpenseBankFeedService(
            $this->access,
            $this->bankFeeds,
            $this->notifications,
            $this->audit,
            $this->clock,
        ))->execute(
            $this->finance,
            $entry->publicId()->toString(),
            Module::RESOLUTION_WRITTEN_OFF,
            'wo',
            $entry->rowVersion()?->toBase64() ?? '',
        );

        $period = $this->periods->findByPublicId(
            \TriCab\Core\Expense\Domain\ValueObject\ExpensePeriodPublicId::fromString($periodId),
        );
        self::assertNotNull($period);
        $close = new CloseExpensePeriodService(
            $this->access,
            $this->periods,
            $this->receipts,
            $pre,
            $this->audit,
            $this->clock,
        );
        $closed = $close->execute($this->finance, $periodId, $period->rowVersion()?->toBase64() ?? '');
        self::assertSame('closed', $closed['status']);
    }

    public function test_csvHeadersExact(): void
    {
        $this->seedPendingReceipt('9.99', '2026-09-01');
        $employeeCsv = (new ExportEmployeeReceiptsCsvService($this->access, $this->receipts, $this->profiles))
            ->execute($this->finance, $this->ownerId->toInt());
        $employeeHeader = strtok($employeeCsv, "\n");
        self::assertSame(
            'ID,Date,Vendor,Category Code,Category Description,Amount,GST,Currency,Cost Centre,Submitted By,Manager,Manager Status,Status,Description',
            $employeeHeader,
        );

        $this->importService()->execute(
            $this->finance,
            'AU',
            $this->auCsvHeader() . "\n" . $this->auCsvData('CSV1', 'owner@tricab.com', '9.99', '2026-09-01', 'Vendor'),
        );
        $financeCsv = (new ExportFinanceReconciliationCsvService(
            $this->access,
            $this->bankFeeds,
            $this->receipts,
            $this->periods,
        ))->execute($this->finance, 'AU');
        $financeHeader = strtok($financeCsv, "\n");
        self::assertSame(
            'Transaction Date,Posting Date,Cardholder,Cardholder Email,Department,Branch,Cost Centre,Merchant,Merchant City,Merchant Country,Bank Reference,Country,Line Type,Amount,Currency,Foreign Amount,Foreign Currency,Receipt Status,Match Status,Resolution,Receipt ID,Receipt Vendor,Receipt Date,Receipt Amount,Receipt GST,Category Code,Category Name,Approval Status',
            $financeHeader,
        );
        self::assertStringContainsString('Spend', $financeCsv);
    }

    public function test_fxMatchUsesForeignAmount(): void
    {
        $this->seedPendingReceipt('50.00', '2026-09-10', currency: 'USD');
        $csv = $this->auCsvHeader() . "\n"
            . 'FX1,owner@tricab.com,Owner,User,2026-09-10,2026-09-11,2026-09-30,Hotel,Sydney,AU,2000,75.00,AUD,50.00,USD,,,,,';
        $import = $this->importService()->execute($this->finance, 'AU', $csv);
        $result = $import['matching'];
        self::assertIsArray($result);
        self::assertSame(1, $result['matched']);
    }

    private function importService(): ImportExpenseBankFeedService
    {
        return new ImportExpenseBankFeedService(
            $this->access,
            $this->bankFeeds,
            new ExpenseAuBankFeedCsvParser(),
            new ExpenseNzBankFeedCsvParser(),
            $this->periodService(),
            $this->matchService(),
            $this->audit,
            $this->clock,
        );
    }

    private function matchService(): RunExpenseBankFeedMatchingService
    {
        return new RunExpenseBankFeedMatchingService(
            $this->access,
            $this->bankFeeds,
            $this->receipts,
            $this->profiles,
            new ExpenseBankFeedMatcher(),
            new ExpenseNearMissDiagnostics(),
            $this->audit,
            $this->clock,
        );
    }

    private function periodService(): GetOrCreateExpensePeriodService
    {
        return new GetOrCreateExpensePeriodService(
            $this->access,
            $this->periods,
            $this->bankFeeds,
            $this->audit,
            $this->clock,
        );
    }

    private function seedPendingReceipt(
        string $amount,
        string $date,
        string $currency = 'AUD',
        string $publicSuffix = 'a',
    ): ExpenseReceipt {
        $now = new DateTimeImmutable('2026-09-01T00:00:00Z', new DateTimeZone('UTC'));
        $receipt = ExpenseReceipt::createDraft(
            publicId: ExpenseReceiptPublicId::fromString(sprintf('11111111-1111-4111-8111-11111111111%s', $publicSuffix)),
            ownerUserId: $this->ownerId,
            vendorName: 'Vendor ' . $amount,
            totalAmount: ExpenseMoney::fromString($amount),
            gstAmount: ExpenseMoney::fromString('0'),
            currency: ExpenseCurrency::fromString($currency),
            gstType: 'unknown',
            receiptDate: new DateTimeImmutable($date, new DateTimeZone('UTC')),
            category: ExpenseCategory::fromLegacy('64100'),
            costCentre: 'MEL',
            description: null,
            attachment: null,
            now: $now,
            managerUserId: null,
        );
        $receipt->submit($this->ownerId, UserId::fromInt(99), $now);
        return $this->receipts->save($receipt);
    }

    private function actor(UserId $id, string $email): ExpenseActor
    {
        return new ExpenseActor($id, 'pub-' . $id->toInt(), $email, [Module::PERMISSION_ACCESS], ['employee']);
    }

    private function auCsvHeader(): string
    {
        return 'Bank Reference,Cardholder Email,First Name,Last Name,Transaction Date,Posting Date,Statement Date,Merchant Name,Merchant City,Merchant Country,Merchant Postcode,Amount,Currency,Foreign Amount,Foreign Currency,Net Amount,Tax Amount,Transaction Type,Merchant Category Group';
    }

    private function auCsvData(
        string $ref,
        string $email,
        string $amount,
        string $txDate,
        string $merchant,
        string $statementDate = '2026-09-30',
        string $transactionType = '',
    ): string {
        return implode(',', [
            $ref,
            $email,
            'Owner',
            'User',
            $txDate,
            $txDate,
            $statementDate,
            $merchant,
            'Melbourne',
            'AU',
            '3000',
            $amount,
            'AUD',
            '',
            '',
            '',
            '',
            $transactionType,
            '',
        ]);
    }

    private function auCsvRow(string $ref, string $email, string $amount, string $txDate): string
    {
        return $this->auCsvHeader() . "\n" . $this->auCsvData($ref, $email, $amount, $txDate, 'M');
    }
}
