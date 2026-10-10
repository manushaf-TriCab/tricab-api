<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Ap\InMemoryApVendorStore;
use TriCab\Core\Finance\Ap\Domain\ApVendorInvalid;
use TriCab\Core\Finance\Ap\Domain\ApVendorInvoice;
use TriCab\Core\Finance\Ap\Domain\ApVendorMatch;
use TriCab\Core\Finance\Ap\Domain\ApVendorUnavailable;
use TriCab\Core\Finance\Ap\Service\ApVendorService;

/**
 * The six VendorEndpointTests of `test_vendor_match.py`, with the HTTP and the database taken out (the service
 * on the in-memory store), then the route rules those tests do not reach. Each source test's name is in its docblock.
 */
final class ApVendorServiceTest extends TestCase
{
    private InMemoryApVendorStore $store;

    private ApVendorService $service;

    protected function setUp(): void
    {
        $this->store = (new InMemoryApVendorStore())
            ->withVendors(ApVendorFixtures::vendors(), new DateTimeImmutable('2026-10-06T10:00:00+10:00'))
            ->withCompanies(ApVendorFixtures::companies());
        $this->service = new ApVendorService($this->store);
    }

    private function invoice(string $id, ?string $vendor, ?string $vendorMatch = null): ApVendorInvoice
    {
        $invoice = ApVendorFixtures::invoice($id, vendor: $vendor, vendorMatch: $vendorMatch);
        $this->store->withInvoice($invoice);

        return $invoice;
    }

    private function stored(string $id): ApVendorInvoice
    {
        return $this->store->invoice($id) ?? throw new \LogicException('No invoice ' . $id);
    }

    /**
     * test_capture_matches_and_generic_patch_cannot_forge_a_match: the rule the route builds on. The match is
     * worked out from the invoice's vendor fields alone, whatever the columns already hold or the caller sent.
     */
    public function testCaptureMatchesAndAForgedMatchIsIgnored(): void
    {
        $forged = new ApVendorInvoice('inv-1', vendor: 'Zend Limited', vendorMatch: 'confirmed', odooVendorId: 9);
        $match = $this->service->autoMatch($forged);
        self::assertNotNull($match);
        self::assertSame([ApVendorMatch::MATCHED, 2], [$match->vendorMatch, $match->odooVendorId]);
    }

    /**
     * test_editing_the_vendor_rematches_unless_a_person_decided: the matching half. (Which edits re-match is the
     * route's, on {@see ApVendorMatch::INPUT_FIELDS} and {@see ApVendorMatch::DECIDED}.)
     */
    public function testEditingTheVendorRematchesUnlessAPersonDecided(): void
    {
        $first = $this->service->autoMatch($this->invoice('inv-1', 'Unknown Vendor'));
        self::assertSame(ApVendorMatch::UNMATCHED, $first?->vendorMatch);
        $edited = $this->service->autoMatch(ApVendorFixtures::invoice('inv-1', vendor: 'Bunnings Group Ltd'));
        self::assertSame(11, $edited?->odooVendorId);

        $chosen = $this->service->setMatch($this->invoice('inv-1', 'Bunnings Group Ltd'), ['odoo_vendor_id' => 9], 'admin@tricab.com');
        self::assertSame(ApVendorMatch::CONFIRMED, $chosen->vendorMatch);
        self::assertSame('Chosen by admin@tricab.com', $chosen->vendorMatchNote);
        self::assertSame([9, 'V000009', 'Civic Transport Services'], [$chosen->odooVendorId, $chosen->vendorRef, $chosen->odooVendorName]);
        self::assertSame(ApVendorMatch::CONFIRMED, $this->store->invoice('inv-1')?->vendorMatch);

        // A decided invoice is left alone by a run, even when its vendor now matches another one.
        self::assertSame(['checked' => 0, 'matched' => 0, 'unmatched' => 0], $this->service->run([$this->stored('inv-1')]));
        self::assertSame(9, $this->store->invoice('inv-1')?->odooVendorId);
    }

    /** test_mark_new_and_hand_back_to_auto */
    public function testMarkNewAndHandBackToAuto(): void
    {
        $invoice = $this->invoice('inv-1', 'Pearl Filtration Pty Ltd');
        $marked = $this->service->setMatch($invoice, ['status' => 'new'], 'admin@tricab.com');
        self::assertSame(ApVendorMatch::NEW, $marked->vendorMatch);
        self::assertStringContainsString('create it in Odoo', $marked->vendorMatchNote);
        self::assertSame('Marked as a new vendor by admin@tricab.com; create it in Odoo', $marked->vendorMatchNote);
        self::assertSame([null, null, null], [$marked->odooVendorId, $marked->vendorRef, $marked->odooVendorName]);

        $this->store->withVendors([...ApVendorFixtures::vendors(), ApVendorFixtures::vendor(12, 'Pearl Filtration Pty Ltd')]);
        $auto = $this->service->setMatch($invoice, ['status' => 'auto'], 'admin@tricab.com');
        self::assertSame([ApVendorMatch::MATCHED, 12], [$auto->vendorMatch, $auto->odooVendorId]);
        self::assertSame(12, $this->store->invoice('inv-1')?->odooVendorId);

        try {
            $this->service->setMatch($invoice, ['odoo_vendor_id' => 999], 'admin@tricab.com');
            self::fail('A vendor that is not in the list must be refused');
        } catch (ApVendorInvalid $refused) {
            self::assertSame('That vendor is not in the Odoo list', $refused->getMessage());
            self::assertSame(422, $refused->httpStatus());
        }
        self::assertSame(12, $this->store->invoice('inv-1')?->odooVendorId, 'a refusal writes nothing');
    }

    /** test_bulk_run_skips_decided_invoices */
    public function testBulkRunSkipsDecidedInvoices(): void
    {
        $pending = $this->invoice('pending', 'Lissamead Ltd');
        $decided = $this->invoice('decided', 'Lissamead Ltd');
        $this->service->setMatch($decided, ['status' => 'new'], 'admin@tricab.com');
        $this->store->withVendors([...ApVendorFixtures::vendors(), ApVendorFixtures::vendor(13, 'Lissamead Limited')]);

        $invoices = [$pending, $this->stored('decided')];
        $result = $this->service->run($invoices, ['pending', 'decided']);

        self::assertSame(['checked' => 1, 'matched' => 1, 'unmatched' => 0], $result);
        self::assertSame(13, $this->store->invoice('pending')?->odooVendorId);
        self::assertSame(ApVendorMatch::NEW, $this->store->invoice('decided')?->vendorMatch);
    }

    /** test_vendor_list_and_suggestions */
    public function testVendorListAndSuggestions(): void
    {
        $this->invoice('zend-1', 'Zend Limited');
        $this->service->setMatch($this->stored('zend-1'), ['odoo_vendor_id' => 2], 'admin@tricab.com');
        $list = $this->service->vendors();
        self::assertCount(count(ApVendorFixtures::vendors()), $list['items']);
        $zend = array_values(array_filter($list['items'], static fn (array $item): bool => $item['odoo_id'] === 2))[0];
        self::assertGreaterThanOrEqual(1, $zend['invoice_count']);
        self::assertSame(
            ['odoo_id' => 2, 'name' => 'Zend Limited', 'ref' => 'V000002', 'email' => 'accounts@zend.example; ar@zend.example', 'vat' => '', 'company_name' => '', 'active' => true, 'invoice_count' => 1],
            $zend,
        );
        self::assertSame('2026-10-06T00:00:00+00:00', $list['synced_at'], 'in UTC with an explicit offset, as the sync reports it');

        $answer = $this->service->suggestions($this->invoice('cap-1', 'Capital Transport Services (VIC) Pty Ltd'));
        self::assertSame('Capital Transport', substr($answer['suggestions'][0]['name'], 0, 17));
        self::assertFalse($answer['buyer_like']);
        self::assertSame(['odoo_id', 'name', 'ref', 'active', 'reason'], array_keys($answer['suggestions'][0]));
        self::assertLessThanOrEqual(5, count($answer['suggestions']));
    }

    /** test_portal_down_leaves_invoice_untried_and_answers_503: with no vendor synced, the same everywhere. */
    public function testNoVendorsLeavesTheInvoiceUntriedAndAnswers503(): void
    {
        $this->store->withVendors([]);
        $invoice = $this->invoice('inv-1', 'Zend Limited');
        self::assertNull($this->service->autoMatch($invoice), 'untried: no columns to store, so a later run picks it up');
        self::assertNull($this->store->invoice('inv-1')?->vendorMatch);

        $calls = [
            'vendors' => fn () => $this->service->vendors(),
            'suggestions' => fn () => $this->service->suggestions($invoice),
            'run' => fn () => $this->service->run([$invoice]),
            'choose' => fn () => $this->service->setMatch($invoice, ['odoo_vendor_id' => 2], 'a'),
            'auto' => fn () => $this->service->setMatch($invoice, ['status' => 'auto'], 'a'),
        ];
        foreach ($calls as $name => $call) {
            try {
                $call();
                self::fail($name . ' must answer unavailable');
            } catch (ApVendorUnavailable $unavailable) {
                self::assertSame('The Odoo vendor list is unavailable; try again shortly', $unavailable->getMessage(), $name);
                self::assertSame(503, $unavailable->httpStatus(), $name);
            }
        }
        self::assertNull($this->store->invoice('inv-1')?->vendorMatch);
        self::assertSame([], $this->store->writes);
    }

    public function testMarkingNewNeedsNoVendorList(): void
    {
        $this->store->withVendors([]);
        $match = $this->service->setMatch($this->invoice('inv-1', 'Zend Limited'), ['status' => 'new'], 'someone');
        self::assertSame(ApVendorMatch::NEW, $match->vendorMatch);
    }

    public function testASetMatchBodyMustNameAVendorOrAStatus(): void
    {
        $invoice = $this->invoice('inv-1', 'Zend Limited');
        foreach ([[], ['status' => 'confirmed'], ['status' => 'NEW'], ['odoo_vendor_id' => null], ['unrelated' => 1]] as $body) {
            try {
                $this->service->setMatch($invoice, $body, 'a');
                self::fail('Refused: ' . json_encode($body));
            } catch (ApVendorInvalid $refused) {
                self::assertSame('Send odoo_vendor_id, or status "new" or "auto"', $refused->getMessage());
            }
        }
    }

    public function testTheVendorIdFollowsPythonsIntRules(): void
    {
        $invoice = $this->invoice('inv-1', 'x');
        foreach ([2, '2', ' 2 ', '+2', '002', '0_2', 2.9] as $given) {
            self::assertSame(2, $this->service->setMatch($invoice, ['odoo_vendor_id' => $given], 'a')->odooVendorId, var_export($given, true));
        }
        self::assertSame(11, $this->service->setMatch($invoice, ['odoo_vendor_id' => '1_1'], 'a')->odooVendorId);
        self::assertSame(1, $this->service->setMatch($invoice, ['odoo_vendor_id' => true], 'a')->odooVendorId, 'int(True) is 1');
        foreach (['abc', '', '1.5', '1__1', '_1', [], [1], 'NaN', NAN, INF] as $given) {
            try {
                $this->service->setMatch($invoice, ['odoo_vendor_id' => $given], 'a');
                self::fail('Refused: ' . var_export($given, true));
            } catch (ApVendorInvalid $refused) {
                self::assertSame('odoo_vendor_id must be a number', $refused->getMessage());
            }
        }
        $this->expectException(ApVendorInvalid::class);
        $this->expectExceptionMessage('That vendor is not in the Odoo list');
        $this->service->setMatch($invoice, ['odoo_vendor_id' => '99999999999999999999999'], 'a');
    }

    public function testAChosenArchivedVendorIsAccepted(): void
    {
        $match = $this->service->setMatch($this->invoice('inv-1', 'x'), ['odoo_vendor_id' => 10], 'a');
        self::assertSame([ApVendorMatch::CONFIRMED, 10], [$match->vendorMatch, $match->odooVendorId]);
    }

    public function testAnAutoRematchCanLeaveTheInvoiceUnmatched(): void
    {
        $invoice = $this->invoice('inv-1', 'Nobody Known');
        $match = $this->service->setMatch($invoice, ['status' => 'auto'], 'a');
        self::assertSame(ApVendorMatch::UNMATCHED, $match->vendorMatch);
        self::assertSame(ApVendorMatch::UNMATCHED, $this->store->invoice('inv-1')?->vendorMatch);
    }

    public function testARunTriesNeverTriedAndUnmatchedInvoicesButNotMatchedOnes(): void
    {
        $untried = $this->invoice('untried', 'Zend Limited');
        $unmatched = $this->invoice('unmatched', 'Bunnings Group Ltd', ApVendorMatch::UNMATCHED);
        $matched = new ApVendorInvoice('matched', vendor: 'Zend Limited', vendorMatch: ApVendorMatch::MATCHED, odooVendorId: 11);
        $nobody = $this->invoice('nobody', 'Nobody Known');
        $confirmed = new ApVendorInvoice('confirmed', vendor: 'Zend Limited', vendorMatch: ApVendorMatch::CONFIRMED, odooVendorId: 9);
        $this->store->withInvoice($matched)->withInvoice($confirmed);

        $result = $this->service->run([$untried, $unmatched, $matched, $nobody, $confirmed]);

        self::assertSame(['checked' => 3, 'matched' => 2, 'unmatched' => 1], $result);
        self::assertSame(2, $this->store->invoice('untried')?->odooVendorId);
        self::assertSame(11, $this->store->invoice('unmatched')?->odooVendorId);
        self::assertSame(11, $this->store->invoice('matched')?->odooVendorId, 'a matched invoice is never re-matched, even when it would now differ');
        self::assertSame(ApVendorMatch::UNMATCHED, $this->store->invoice('nobody')?->vendorMatch);
    }

    public function testARunWritesOnlyWhatChanged(): void
    {
        $match = $this->service->autoMatch(ApVendorFixtures::invoice('inv-1', vendor: 'Nobody Known'));
        self::assertNotNull($match);
        $already = new ApVendorInvoice(
            'inv-1',
            vendor: 'Nobody Known',
            vendorMatch: $match->vendorMatch,
            vendorRef: $match->vendorRef,
            odooVendorName: $match->odooVendorName,
            vendorMatchNote: $match->vendorMatchNote,
        );
        $this->store->withInvoice($already);
        self::assertSame(['checked' => 1, 'matched' => 0, 'unmatched' => 1], $this->service->run([$already]));
        self::assertSame([], $this->store->writes, 'same columns: no write');

        $stale = new ApVendorInvoice('inv-2', vendor: 'Nobody Known', vendorMatch: ApVendorMatch::UNMATCHED, vendorMatchNote: 'old note');
        $this->store->withInvoice($stale);
        $this->service->run([$stale]);
        self::assertSame(['inv-2'], $this->store->writes);
    }

    public function testARunWithIdsConsidersOnlyThose(): void
    {
        $one = $this->invoice('1', 'Zend Limited');
        $two = $this->invoice('2', 'Zend Limited');
        self::assertSame(['checked' => 1, 'matched' => 1, 'unmatched' => 0], $this->service->run([$one, $two], [2]), 'ids may arrive as numbers');
        self::assertNull($this->store->invoice('1')?->vendorMatch);
        self::assertSame(2, $this->store->invoice('2')?->odooVendorId);
        self::assertSame(['checked' => 1, 'matched' => 1, 'unmatched' => 0], $this->service->run([$one, $this->stored('2')], []), 'no ids: all, and the one just matched is skipped');
        self::assertSame(['checked' => 0, 'matched' => 0, 'unmatched' => 0], $this->service->run([], ['x']));
    }

    public function testTheVendorListCountsLiveInvoicesOnlyAndKeepsArchivedVendors(): void
    {
        $this->store->withInvoice(new ApVendorInvoice('a', odooVendorId: 10, vendorMatch: 'matched'));
        $this->store->withInvoice(new ApVendorInvoice('b', odooVendorId: 10, vendorMatch: 'matched'), binned: true);
        $this->store->withInvoice(new ApVendorInvoice('c', odooVendorId: null, vendorMatch: 'unmatched'));
        $items = $this->service->vendors()['items'];
        $old = array_values(array_filter($items, static fn (array $item): bool => $item['odoo_id'] === 10))[0];
        self::assertSame([false, 1], [$old['active'], $old['invoice_count']]);
        self::assertSame(0, array_values(array_filter($items, static fn (array $item): bool => $item['odoo_id'] === 1))[0]['invoice_count']);
    }

    public function testTheListIsOrderedByNameThenIdAndLeavesOutRemovedVendors(): void
    {
        $this->store->withVendors([
            ApVendorFixtures::vendor(3, 'beta'),
            new \TriCab\Core\Finance\Ap\Domain\ApVendor(9, 'Gone', deleted: true),
            ApVendorFixtures::vendor(2, 'Beta'),
            ApVendorFixtures::vendor(1, 'Alpha'),
        ]);
        $list = $this->service->vendors();
        self::assertSame([1, 2, 3], array_column($list['items'], 'odoo_id'));
        self::assertNull($list['synced_at'], 'never synced');
    }

    public function testSuggestionsSayWhenTheVendorLooksLikeTricabItself(): void
    {
        $answer = $this->service->suggestions(ApVendorFixtures::invoice('inv-1', vendor: 'tricab', company: 'Tricab (New Zealand) Ltd', senderName: 'Go Logistics NZ Accounts'));
        self::assertTrue($answer['buyer_like']);
        self::assertSame(3, $answer['suggestions'][0]['odoo_id']);
        self::assertSame('Similar sender name', $answer['suggestions'][0]['reason']);
    }
}
