<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CustomerContactReport;

use PHPUnit\Framework\TestCase;
use TriCab\Api\CustomerContactReport\Identity\IdentityCustomerContactReportAssigneeDirectory;
use TriCab\Api\CustomerContactReport\Persistence\InMemoryCustomerContactReportRepository;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportAccountsLogin;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportListCriteria;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportAccessDenied;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportClientFieldRejected;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportConflict;
use TriCab\Core\CustomerContactReport\Application\Service\CreateCustomerContactReportService;
use TriCab\Core\CustomerContactReport\Application\Service\CustomerContactFourJsLookup;
use TriCab\Core\CustomerContactReport\Application\Service\CustomerContactReportCreditPlanner;
use TriCab\Core\CustomerContactReport\Application\Service\CustomerContactReportLineResolver;
use TriCab\Core\CustomerContactReport\Application\Service\DecideCustomerContactReportApprovalService;
use TriCab\Core\CustomerContactReport\Application\Service\ExportCustomerContactReportsService;
use TriCab\Core\CustomerContactReport\Application\Service\RequestCustomerContactReportApprovalService;
use TriCab\Core\CustomerContactReport\Application\Service\UpdateCustomerContactReportService;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactFourJsGateway;
use TriCab\Core\CustomerContactReport\Domain\Credit\CustomerContactReportCreditCalculator as Calc;
use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;
use TriCab\Core\CustomerContactReport\Domain\FourJs\FourJsOcrParser;
use TriCab\Core\CustomerContactReport\Domain\FourJs\FourJsUnavailable;
use TriCab\Core\CustomerContactReport\Module;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

/**
 * Orders, lines, credit types, GST, partial-credit caps, amend, autosave and CSV.
 */
final class CustomerContactReportOrdersAndAmendTest extends TestCase
{
    use AssignsCustomerContactReports;

    private InMemoryCustomerContactReportRepository $reports;

    private CreateCustomerContactReportService $create;

    private UpdateCustomerContactReportService $update;

    private RequestCustomerContactReportApprovalService $request;

    private DecideCustomerContactReportApprovalService $decide;

    private ExportCustomerContactReportsService $export;

    private FakeFourJs $fourJs;

    protected function setUp(): void
    {
        $users = new InMemoryApiUserRepository();
        foreach (['alex@tricab.com' => 'Alex Assignee', 'accounts@tricab.com' => 'Accounts'] as $email => $name) {
            $users->register(
                EmailAddress::fromString($email),
                $name,
                UserStatus::active(),
                [RoleCode::fromString('EMPLOYEE')],
                [PermissionCode::fromString('ACCOUNT.LOGIN')],
                EmailOtpExternalIdentityFactory::forEmail(EmailAddress::fromString('seed-' . $email)),
            );
        }
        $this->reports = new InMemoryCustomerContactReportRepository();
        $this->fourJs = new FakeFourJs();
        $clock = FixedClock::utc('2026-09-30 01:00:00');
        $planner = new CustomerContactReportCreditPlanner(
            new CustomerContactReportLineResolver(new CustomerContactFourJsLookup($this->fourJs)),
        );
        $this->create = new CreateCustomerContactReportService($this->reports, $clock, null, $planner);
        $this->update = new UpdateCustomerContactReportService($this->reports, $clock, $planner);
        $this->request = new RequestCustomerContactReportApprovalService($this->reports, $clock);
        $this->decide = new DecideCustomerContactReportApprovalService(
            $this->reports,
            new IdentityCustomerContactReportAssigneeDirectory($users),
            $clock,
            new CustomerContactReportAccountsLogin('accounts@tricab.com'),
        );
        $this->export = new ExportCustomerContactReportsService($this->reports, $clock);
    }

    // ---- calculator -------------------------------------------------------

    public function test_returned_goods_restocking_is_30_percent_and_gst_is_taken_before_it(): void
    {
        $figures = Calc::calculate('Returned goods', [], '200.00', true, true, 'M');
        self::assertSame('200.00', $figures->valueExGst);
        self::assertSame('60.00', $figures->restockingAmount);
        self::assertSame('140.00', $figures->subTotal);
        self::assertSame('20.00', $figures->gst);
        self::assertSame('160.00', $figures->creditTotal);
    }

    public function test_overcharge_gst_follows_location_and_can_be_switched_off(): void
    {
        self::assertSame('10.00', Calc::calculate('Overcharged', [], '100', false, true, 'M')->gst);
        self::assertSame('15.00', Calc::calculate('Overcharged', [], '100', false, true, 'C')->gst);
        $off = Calc::calculate('Overcharged', [], '100', false, false, 'M');
        self::assertSame('0.00', $off->gst);
        self::assertSame('100.00', $off->creditTotal);
        self::assertSame('0.00', $off->restockingAmount);
    }

    public function test_an_overcharge_or_a_returned_credit_with_no_amount_is_rejected(): void
    {
        foreach (['Overcharged', 'Returned goods'] as $type) {
            foreach ([null, '0', '0.00'] as $typed) {
                try {
                    Calc::calculate($type, [], $typed, false, true, 'M');
                    self::fail($type . ' accepted no amount.');
                } catch (InvalidCustomerContactReportValue) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function test_money_with_more_than_two_decimals_is_rejected(): void
    {
        $this->expectException(InvalidCustomerContactReportValue::class);
        Calc::calculate('Overcharged', [], '10.005', false, true, 'M');
    }

    // ---- 4JS parsing and partial-credit caps ------------------------------

    public function test_parser_reads_orders_and_invoices_and_skips_noise(): void
    {
        $order = FourJsOcrParser::order("PHP Warning: noise\n" . $this->orderBody());
        self::assertNotNull($order);
        self::assertSame('639952', $order->orderNo);
        self::assertSame('NZD', $order->currency);
        self::assertCount(2, $order->lines);
        self::assertSame('2560.00', $order->lines[0]->net);
        $invoice = FourJsOcrParser::invoice($this->invoiceBody());
        self::assertNotNull($invoice);
        self::assertSame('639952', $invoice->orderNo);
        self::assertSame('63995201', $invoice->invoiceNo);
        self::assertSame('0.10', $invoice->gstRate);
        self::assertNull(FourJsOcrParser::order('error not found'));
    }

    public function test_partial_credit_is_capped_by_4js_quantity_rate_and_net(): void
    {
        $this->fourJs->order = $this->orderBody();
        $resolver = new CustomerContactReportLineResolver(new CustomerContactFourJsLookup($this->fourJs));

        $ok = $resolver->resolve([['orderNo' => '639952', 'lineNo' => 1, 'creditQty' => '4']]);
        self::assertSame('4', $ok['lines'][0]->creditQty);
        self::assertSame('1024.00', $ok['lines'][0]->creditNet);
        self::assertFalse($ok['lines'][0]->capped);

        $tooMany = $resolver->resolve([['orderNo' => '639952', 'lineNo' => 1, 'creditQty' => '999']]);
        self::assertSame('10', $tooMany['lines'][0]->creditQty);
        self::assertSame('2560.00', $tooMany['lines'][0]->creditNet);
        self::assertTrue($tooMany['lines'][0]->capped);

        $dearRate = $resolver->resolve([['orderNo' => '639952', 'lineNo' => 1, 'creditRate' => '999']]);
        self::assertSame('256.0000', $dearRate['lines'][0]->creditRate);
        self::assertTrue($dearRate['lines'][0]->capped);

        $bigNet = $resolver->resolve([['orderNo' => '639952', 'lineNo' => 1, 'creditQty' => '2', 'creditNet' => '99999']]);
        self::assertSame('512.00', $bigNet['lines'][0]->creditNet);
        self::assertTrue($bigNet['lines'][0]->capped);

        $negotiated = $resolver->resolve([['orderNo' => '639952', 'lineNo' => 1, 'creditNet' => '300.50']]);
        self::assertSame('300.50', $negotiated['lines'][0]->creditNet);
    }

    public function test_a_line_4js_cannot_confirm_is_dropped_and_an_outage_is_reported(): void
    {
        $this->fourJs->order = $this->orderBody();
        $resolver = new CustomerContactReportLineResolver(new CustomerContactFourJsLookup($this->fourJs));
        $result = $resolver->resolve([['orderNo' => '639952', 'lineNo' => 99], ['orderNo' => 'abc', 'lineNo' => 1]]);
        self::assertSame([], $result['lines']);
        self::assertSame(1, $result['unconfirmed']);
        self::assertFalse($result['unavailable']);

        $this->fourJs->down = true;
        $resolver = new CustomerContactReportLineResolver(new CustomerContactFourJsLookup($this->fourJs));
        $down = $resolver->resolve([['orderNo' => '639952', 'lineNo' => 1]]);
        self::assertTrue($down['unavailable']);
        self::assertSame([], $down['lines']);
    }

    // ---- create -----------------------------------------------------------

    public function test_create_stores_server_calculated_money_from_4js_and_ignores_client_prices(): void
    {
        $this->fourJs->order = $this->orderBody();
        $this->fourJs->invoices = ['63995201' => $this->invoiceBody()];
        $saved = $this->create->executeWithWarnings($this->raiser(), $this->body([
            'creditRequired' => 'Yes',
            'creditType' => 'Returned goods',
            'restockingFeeApplies' => 'Yes',
            'lines' => [['orderNo' => '639952', 'lineNo' => 1, 'creditQty' => '2', 'unitPrice' => '0.01', 'net' => '1']],
        ]));
        $r = $saved->report;
        self::assertSame('512.00', $r->valueExGst);
        self::assertSame('153.60', $r->restockingAmount);
        self::assertSame('358.40', $r->subTotal);
        self::assertSame('51.20', $r->gst);
        self::assertSame('409.60', $r->creditTotal);
        self::assertSame('639952', $r->orderNo);
        self::assertCount(1, $r->lines);
        self::assertSame('63995201', $r->lines[0]->invoiceNo);
        self::assertSame('CAB-1', $r->productCode);
        self::assertSame([], $saved->warnings);
    }

    public function test_create_refuses_client_supplied_totals(): void
    {
        foreach (['gst', 'creditTotal', 'subTotal', 'restockingAmount'] as $field) {
            try {
                $this->create->execute($this->raiser(), $this->body([$field => '1.00']));
                self::fail($field . ' accepted.');
            } catch (CustomerContactReportClientFieldRejected) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_4js_outage_still_saves_when_the_amount_is_typed_and_warns(): void
    {
        $this->fourJs->down = true;
        $saved = $this->create->executeWithWarnings($this->raiser(), $this->body([
            'creditRequired' => 'Yes',
            'creditType' => 'Overcharged',
            'valueExGst' => '100.00',
            'lines' => [['orderNo' => '639952', 'lineNo' => 1]],
        ]));
        self::assertSame('110.00', $saved->report->creditTotal);
        self::assertSame([], $saved->report->lines);
        self::assertContains('fourjs_unavailable', $saved->warnings);
    }

    public function test_a_4js_outage_with_no_typed_amount_is_a_validation_error_not_a_lost_report(): void
    {
        $this->fourJs->down = true;
        $this->expectException(InvalidCustomerContactReportValue::class);
        $this->create->execute($this->raiser(), $this->body([
            'creditRequired' => 'Yes',
            'creditType' => 'Returned goods',
            'lines' => [['orderNo' => '639952', 'lineNo' => 1]],
        ]));
    }

    // ---- update / amend ---------------------------------------------------

    public function test_originator_edits_until_closed_and_figures_are_recomputed_by_the_server(): void
    {
        $report = $this->create->execute($this->raiser(), $this->body([
            'creditRequired' => 'Yes', 'creditType' => 'Overcharged', 'valueExGst' => '100.00',
        ]));
        self::assertSame('110.00', $report->creditTotal);
        $saved = $this->update->execute($this->raiser(), $report->publicId, [
            'rowVersion' => $report->rowVersion,
            'valueExGst' => '200.00',
            'description' => 'Updated',
        ]);
        self::assertSame('220.00', $saved->report->creditTotal);
        self::assertSame('Updated', $saved->report->description);
        self::assertFalse($saved->creditReset);
    }

    public function test_server_owned_fields_and_unknown_fields_are_refused(): void
    {
        $report = $this->create->execute($this->raiser(), $this->body());
        foreach (['status' => 'closed', 'ccNumber' => 'x', 'creditTotal' => '1', 'assignedTo' => 'a@b.c', 'bogus' => 1] as $field => $value) {
            try {
                $this->update->execute($this->raiser(), $report->publicId, ['rowVersion' => $report->rowVersion, $field => $value]);
                self::fail($field . ' accepted.');
            } catch (CustomerContactReportClientFieldRejected) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_stale_row_version_conflicts_and_writes_nothing(): void
    {
        $report = $this->create->execute($this->raiser(), $this->body());
        $this->update->execute($this->raiser(), $report->publicId, ['rowVersion' => $report->rowVersion, 'description' => 'First']);
        try {
            $this->update->execute($this->raiser(), $report->publicId, ['rowVersion' => $report->rowVersion, 'description' => 'Second']);
            self::fail('Stale write accepted.');
        } catch (CustomerContactReportConflict) {
            $this->addToAssertionCount(1);
        }
        $after = $this->reports->findVisible(
            \TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId::fromString($report->publicId),
            new \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility(true, 1, 'thilanh@tricab.com'),
        );
        self::assertSame('First', $after?->description);
    }

    public function test_permissions_by_role(): void
    {
        $report = $this->assignTo($this->reports, $this->create->execute($this->raiser(), $this->body()));
        $alex = $this->person(2, 'alex@tricab.com', []);
        $stranger = $this->person(9, 'zed@tricab.com', []);

        $saved = $this->update->execute($alex, $report->publicId, ['rowVersion' => $report->rowVersion, 'assigneeFindings' => 'Found it']);
        self::assertSame('Found it', $saved->report->assigneeFindings);
        $this->assertDenied(fn () => $this->update->execute($alex, $report->publicId, ['rowVersion' => $saved->report->rowVersion, 'description' => 'nope']));
        $this->assertDenied(fn () => $this->update->execute($stranger, $report->publicId, ['rowVersion' => $saved->report->rowVersion, 'description' => 'nope']), true);
        $this->assertDenied(fn () => $this->update->execute($this->raiser(), $report->publicId, ['rowVersion' => $saved->report->rowVersion, 'errorCode' => 'CUS']));

        $coordinator = $this->person(7, 'coord@tricab.com', [Module::PERMISSION_ASSIGN, Module::PERMISSION_READ_ALL]);
        $office = $this->update->execute($coordinator, $report->publicId, ['rowVersion' => $saved->report->rowVersion, 'errorCode' => 'CUS', 'rootCause' => 'Pick error']);
        self::assertSame('CUS', $office->report->errorCode);
    }

    public function test_nobody_but_the_coordinator_touches_a_closed_report_and_never_its_credit(): void
    {
        $report = $this->assignTo($this->reports, $this->create->execute($this->raiser(), $this->body()));
        $closed = $this->reports->close(new \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportClosure(
            $report->publicId,
            $this->reports->complete(new \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportCompletion(
                $report->publicId, $report->rowVersion, 2, 'alex@tricab.com', false, null, false, null,
                new \DateTimeImmutable('2026-09-30 02:00:00', new \DateTimeZone('UTC')),
            ))->rowVersion,
            'Coord', 7, new \DateTimeImmutable('2026-09-30'), new \DateTimeImmutable('2026-09-30 03:00:00', new \DateTimeZone('UTC')),
        ));
        $this->assertDenied(fn () => $this->update->execute($this->raiser(), $closed->publicId, ['rowVersion' => $closed->rowVersion, 'description' => 'late']));
        $coordinator = $this->person(7, 'coord@tricab.com', [Module::PERMISSION_ASSIGN, Module::PERMISSION_READ_ALL]);
        $this->assertDenied(fn () => $this->update->execute($coordinator, $closed->publicId, ['rowVersion' => $closed->rowVersion, 'valueExGst' => '5']));
        $notes = $this->update->execute($coordinator, $closed->publicId, ['rowVersion' => $closed->rowVersion, 'notes' => 'Filed']);
        self::assertSame('Filed', $notes->report->notes);
    }

    public function test_amending_after_a_credit_decision_resets_it_removes_finance_and_clears_the_review(): void
    {
        $report = $this->create->execute($this->raiser(), $this->body([
            'creditRequired' => 'Yes', 'creditType' => 'Overcharged', 'valueExGst' => '100.00',
        ]));
        $report = $this->assignTo($this->reports, $report);
        $pending = $this->request->execute($this->raiser(), $report->publicId, 'credit', ['rowVersion' => $report->rowVersion]);
        $decided = $this->decide->execute(
            $this->person(2, 'alex@tricab.com', [Module::PERMISSION_APPROVE]),
            $report->publicId,
            'credit',
            ['rowVersion' => $pending->approval->rowVersion, 'decision' => 'approved'],
        );
        self::assertNotNull($decided->settlement);
        $current = $decided->report;
        self::assertNotNull($current->creditReviewedBy);

        $saved = $this->update->execute($this->raiser(), $report->publicId, ['rowVersion' => $current->rowVersion, 'valueExGst' => '150.00']);
        self::assertTrue($saved->creditReset);
        self::assertSame('165.00', $saved->report->creditTotal);
        self::assertNull($saved->report->creditReviewedBy);
        $kinds = array_map(static fn ($a): string => $a->kind . ':' . $a->status, $this->reports->approvalsFor($report->publicId));
        self::assertSame(['credit:pending'], $kinds);

        // A second amend while the credit is still pending does not reset (or re-mail) again.
        $again = $this->update->execute($this->raiser(), $report->publicId, ['rowVersion' => $saved->report->rowVersion, 'valueExGst' => '175.00']);
        self::assertFalse($again->creditReset);
        self::assertSame('192.50', $again->report->creditTotal);
    }

    public function test_an_edit_that_leaves_the_credit_alone_does_not_reset_it(): void
    {
        $report = $this->create->execute($this->raiser(), $this->body([
            'creditRequired' => 'Yes', 'creditType' => 'Overcharged', 'valueExGst' => '100.00',
        ]));
        $report = $this->assignTo($this->reports, $report);
        $pending = $this->request->execute($this->raiser(), $report->publicId, 'credit', ['rowVersion' => $report->rowVersion]);
        $decided = $this->decide->execute(
            $this->person(2, 'alex@tricab.com', [Module::PERMISSION_APPROVE]),
            $report->publicId,
            'credit',
            ['rowVersion' => $pending->approval->rowVersion, 'decision' => 'approved'],
        );
        $saved = $this->update->execute($this->raiser(), $report->publicId, ['rowVersion' => $decided->report->rowVersion, 'description' => 'Better wording']);
        self::assertFalse($saved->creditReset);
        self::assertCount(2, $this->reports->approvalsFor($report->publicId));
    }

    public function test_order_and_invoice_cannot_both_be_removed(): void
    {
        $report = $this->create->execute($this->raiser(), $this->body());
        $this->expectException(InvalidCustomerContactReportValue::class);
        $this->update->execute($this->raiser(), $report->publicId, ['rowVersion' => $report->rowVersion, 'orderNo' => '']);
    }

    // ---- CSV ---------------------------------------------------------------

    public function test_csv_follows_visibility_and_neutralises_formulas(): void
    {
        $mine = $this->create->execute($this->raiser(), $this->body(['description' => '=HYPERLINK("x")']));
        $other = $this->create->execute($this->person(5, 'other@tricab.com', []), $this->body(['description' => 'Someone else']));

        $criteria = new CustomerContactReportListCriteria(1, 25, null, null, null, null, null, null, null, null);
        $own = $this->export->execute($this->raiser(), $criteria);
        self::assertSame(1, $own['rows']);
        self::assertStringContainsString("'=HYPERLINK", $own['body']);
        self::assertStringNotContainsString('Someone else', $own['body']);
        self::assertStringNotContainsString('Root Cause', $own['body']);

        $all = $this->export->execute($this->person(7, 'coord@tricab.com', [Module::PERMISSION_READ_ALL]), $criteria);
        self::assertSame(2, $all['rows']);
        self::assertStringContainsString('Root Cause', $all['body']);
        self::assertStringContainsString('Someone else', $all['body']);
        self::assertNotSame($mine->publicId, $other->publicId);
    }

    // ---- helpers ------------------------------------------------------------

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function body(array $extra = []): array
    {
        return $extra + [
            'reportDate' => '2026-09-30',
            'location' => 'M',
            'customerCode' => 'CUST1',
            'description' => 'Cable failed',
            'orderNo' => '639952',
            'creditRequired' => 'No',
        ];
    }

    private function raiser(): AuthenticatedUser
    {
        return $this->person(1, 'thilanh@tricab.com', []);
    }

    /**
     * @param list<string> $permissions
     */
    private function person(int $userId, string $email, array $permissions): AuthenticatedUser
    {
        return new AuthenticatedUser($userId, '550e8400-e29b-41d4-a716-446655440000', $email, $email, 'active', ['EMPLOYEE'], $permissions);
    }

    private function assertDenied(callable $action, bool $notFoundToo = false): void
    {
        try {
            $action();
            self::fail('Expected access to be denied.');
        } catch (CustomerContactReportAccessDenied) {
            $this->addToAssertionCount(1);
        } catch (\TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportNotFound $e) {
            self::assertTrue($notFoundToo);
        }
    }

    /** @param list<string> $fields */
    private static function rec(string $tag, array $fields): string
    {
        return ".RER.$tag\n" . count($fields) . "\n" . implode("\n", $fields) . "\n";
    }

    private function orderBody(): string
    {
        $h = array_fill(0, 13, '');
        $h[0] = '639952';
        $h[9] = 'CUST1';
        $h[11] = 'NZD';
        $h[12] = '2,560.00';

        return self::rec('S006', $h) . self::rec('S009', $this->line(1, 'CAB-1', '10', '256.00', '2,560.00')) . self::rec('S009', $this->line(2, 'CAB-2', '1', '40.00', '40.00'));
    }

    private function invoiceBody(): string
    {
        $h = array_fill(0, 13, '');
        $h[0] = '63995201';
        $h[9] = 'CUST1';
        $h[11] = 'NZD';
        $tax = array_fill(0, 7, '');
        $tax[5] = '0.10';
        $tax[6] = '256.00';

        return self::rec('S006', $h) . self::rec('S019', $tax) . self::rec('S009', $this->line(1, 'CAB-1', '10', '256.00', '2,560.00'));
    }

    /**
     * @return list<string>
     */
    private function line(int $no, string $product, string $qty, string $price, string $net): array
    {
        $f = array_fill(0, 19, '');
        $f[1] = (string) $no;
        $f[2] = $product;
        $f[4] = $qty;
        $f[5] = 'ea';
        $f[6] = $price;
        $f[7] = $net;
        $f[8] = 'Line ' . $no;
        $f[10] = '0';
        $f[11] = '0';

        return $f;
    }
}

final class FakeFourJs implements CustomerContactFourJsGateway
{
    public string $order = 'error not found';

    /** @var array<array-key, string> */
    public array $invoices = [];

    public bool $down = false;

    public function fetch(string $type, string $ref): string
    {
        if ($this->down) {
            throw new FourJsUnavailable('down');
        }
        if ($type === 'ORDOCR') {
            return $this->order;
        }

        return $this->invoices[$ref] ?? 'error not found';
    }
}
