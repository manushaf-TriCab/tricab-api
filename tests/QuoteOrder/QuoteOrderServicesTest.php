<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\QuoteOrder;

use PHPUnit\Framework\TestCase;
use TriCab\Api\QuoteOrder\Mapping\LegacyQuoteOrderMapper;
use TriCab\Api\QuoteOrder\Persistence\InMemoryQuoteOrderRepository;
use TriCab\Core\QuoteOrder\Application\DTO\OrderDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteItemDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderActor;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderSearchCriteria;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderSummary;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteNotFound;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteOrderAccessDenied;
use TriCab\Core\QuoteOrder\Application\Service\GetOrderService;
use TriCab\Core\QuoteOrder\Application\Service\GetQuoteService;
use TriCab\Core\QuoteOrder\Application\Service\QuoteOrderAccessService;
use TriCab\Core\QuoteOrder\Application\Service\SearchQuoteOrdersService;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteOrderType;
use TriCab\Core\QuoteOrder\Module;

final class QuoteOrderServicesTest extends TestCase
{
    private InMemoryQuoteOrderRepository $repo;
    private QuoteOrderAccessService $access;
    private SearchQuoteOrdersService $search;
    private GetQuoteService $getQuote;
    private GetOrderService $getOrder;

    protected function setUp(): void
    {
        $this->repo = new InMemoryQuoteOrderRepository(new LegacyQuoteOrderMapper());
        $this->access = new QuoteOrderAccessService();
        $this->search = new SearchQuoteOrdersService($this->access, $this->repo, true);
        $this->getQuote = new GetQuoteService($this->access, $this->repo, true);
        $this->getOrder = new GetOrderService($this->access, $this->repo, true);
        $this->seedFixtures();
    }

    public function test_qot_vs_ord_classification(): void
    {
        $staff = $this->staffActor();
        $result = $this->search->execute($staff, new QuoteOrderSearchCriteria(type: QuoteOrderType::ALL));

        $byId = [];
        foreach ($result->items as $item) {
            $byId[$item->id] = $item;
        }

        self::assertSame(QuoteOrderType::QUOTE, $byId['1001']->type);
        self::assertNull($byId['1001']->orderId);
        self::assertSame(QuoteOrderType::ORDER, $byId['9001']->type);
        self::assertSame('1002', $byId['9001']->quoteId);
    }

    public function test_tbc_total_in_search(): void
    {
        $result = $this->search->execute($this->staffActor(), new QuoteOrderSearchCriteria(reference: 'TBC-REF'));
        self::assertCount(1, $result->items);
        self::assertNull($result->items[0]->total);
        self::assertSame('pending', $result->items[0]->totalStatus);
    }

    public function test_filters_customer_reference_date_type_status(): void
    {
        $staff = $this->staffActor();

        $byCustomer = $this->search->execute($staff, new QuoteOrderSearchCriteria(customerCode: 'ACME'));
        self::assertSame(2, $byCustomer->total);
        foreach ($byCustomer->items as $item) {
            self::assertStringStartsWith('ACME', (string) $item->customerCode);
        }

        $byRef = $this->search->execute($staff, new QuoteOrderSearchCriteria(reference: 'PO-55'));
        self::assertSame(1, $byRef->total);
        self::assertSame('9001', $byRef->items[0]->id);

        $byDate = $this->search->execute($staff, new QuoteOrderSearchCriteria(from: '2026-01-01', to: '2026-01-31'));
        self::assertSame(2, $byDate->total);

        $quotesOnly = $this->search->execute($staff, new QuoteOrderSearchCriteria(type: QuoteOrderType::QUOTE));
        foreach ($quotesOnly->items as $item) {
            self::assertSame(QuoteOrderType::QUOTE, $item->type);
        }

        $byStatus = $this->search->execute($staff, new QuoteOrderSearchCriteria(statusCode: '1710'));
        foreach ($byStatus->items as $item) {
            self::assertSame('1710', $item->statusCode);
            self::assertSame('open', $item->status);
        }
    }

    public function test_customer_code_filter_independent_of_dates(): void
    {
        $result = $this->search->execute(
            $this->staffActor(),
            new QuoteOrderSearchCriteria(customerCode: 'BETA', from: null, to: null),
        );
        self::assertSame(1, $result->total);
        self::assertSame('BETA01', $result->items[0]->customerCode);
    }

    public function test_pagination(): void
    {
        $page1 = $this->search->execute($this->staffActor(), new QuoteOrderSearchCriteria(page: 1, pageSize: 2));
        self::assertSame(2, count($page1->items));
        self::assertSame(4, $page1->total);
        self::assertSame(1, $page1->page);
        self::assertSame(2, $page1->pageSize);

        $page2 = $this->search->execute($this->staffActor(), new QuoteOrderSearchCriteria(page: 2, pageSize: 2));
        self::assertSame(2, count($page2->items));
        self::assertNotSame($page1->items[0]->id, $page2->items[0]->id);
    }

    public function test_quote_details_and_lines(): void
    {
        $details = $this->getQuote->execute($this->staffActor(), '1001');
        self::assertSame('1001', $details->quoteId);
        self::assertSame('AUD', $details->currency);
        self::assertCount(1, $details->items);
        self::assertSame('BVA-10BK', $details->items[0]->productCode);
        self::assertCount(1, $details->items[0]->packs);
        self::assertSame('Cut to length', $details->items[0]->packs[0]->comments);
    }

    public function test_order_details_include_nested_quote(): void
    {
        $order = $this->getOrder->execute($this->staffActor(), '9001');
        self::assertSame('9001', $order->orderId);
        self::assertSame('PO-55', $order->customerPoNumber);
        self::assertNotNull($order->quote);
        self::assertSame('1002', $order->quote->quoteId);
    }

    public function test_unauthorized_without_quote_read(): void
    {
        $actor = new QuoteOrderActor(
            userId: 2,
            publicId: 'u-2',
            emailAddress: 'x@tricab.com',
            permissions: ['ACCOUNT.LOGIN'],
            roles: ['EMPLOYEE'],
        );

        $this->expectException(QuoteOrderAccessDenied::class);
        $this->search->execute($actor, new QuoteOrderSearchCriteria());
    }

    public function test_customer_portal_empty_codes_returns_empty(): void
    {
        $portal = new QuoteOrderActor(
            userId: 3,
            publicId: 'u-3',
            emailAddress: 'cust@example.com',
            permissions: [Module::PERMISSION_READ],
            roles: ['CUSTOMER_USER'],
            customerPublicIds: ['cust-public-1'],
            allowedCustomerCodes: [],
        );

        $result = $this->search->execute($portal, new QuoteOrderSearchCriteria());
        self::assertSame(0, $result->total);
        self::assertSame([], $result->items);

        $this->expectException(QuoteNotFound::class);
        $this->getQuote->execute($portal, '1001');
    }

    public function test_customer_portal_with_codes_sees_own_only(): void
    {
        $portal = new QuoteOrderActor(
            userId: 4,
            publicId: 'u-4',
            emailAddress: 'acme@example.com',
            permissions: [Module::PERMISSION_READ],
            roles: ['CUSTOMER_USER'],
            customerPublicIds: ['cust-public-acme'],
            allowedCustomerCodes: ['ACME01'],
        );

        $result = $this->search->execute($portal, new QuoteOrderSearchCriteria());
        self::assertSame(2, $result->total);
        foreach ($result->items as $item) {
            self::assertSame('ACME01', $item->customerCode);
        }
    }

    public function test_portal_cannot_expand_scope_via_customer_code_filter(): void
    {
        $portal = new QuoteOrderActor(
            userId: 4,
            publicId: 'u-4',
            emailAddress: 'acme@example.com',
            permissions: [Module::PERMISSION_READ],
            roles: ['CUSTOMER_USER'],
            customerPublicIds: ['cust-public-acme'],
            allowedCustomerCodes: ['ACME01'],
        );

        $result = $this->search->execute(
            $portal,
            new QuoteOrderSearchCriteria(customerCode: 'BETA'),
        );
        self::assertSame(0, $result->total);
        self::assertSame([], $result->items);
    }

    public function test_portal_id_enumeration_returns_not_found_for_foreign_quote(): void
    {
        $portal = new QuoteOrderActor(
            userId: 4,
            publicId: 'u-4',
            emailAddress: 'acme@example.com',
            permissions: [Module::PERMISSION_READ],
            roles: ['CUSTOMER_USER'],
            customerPublicIds: ['cust-public-acme'],
            allowedCustomerCodes: ['ACME01'],
        );

        // Own quote OK.
        $own = $this->getQuote->execute($portal, '1001');
        self::assertSame('ACME01', $own->customerCode);

        // Foreign quote / order look like not found (no existence leak via 403).
        try {
            $this->getQuote->execute($portal, '1003');
            self::fail('Expected QuoteNotFound for foreign quote');
        } catch (QuoteNotFound) {
            self::assertTrue(true);
        }

        try {
            $this->getOrder->execute($portal, '9001');
            // 9001 is ACME — should succeed
            self::assertTrue(true);
        } catch (QuoteNotFound) {
            self::fail('Own order should be visible');
        }

        // Malformed IDs
        $this->expectException(QuoteNotFound::class);
        $this->getQuote->execute($portal, 'not-a-number');
    }

    public function test_portal_foreign_order_id_enumeration(): void
    {
        $portal = new QuoteOrderActor(
            userId: 5,
            publicId: 'u-5',
            emailAddress: 'beta@example.com',
            permissions: [Module::PERMISSION_READ],
            roles: ['CUSTOMER_USER'],
            customerPublicIds: ['cust-public-beta'],
            allowedCustomerCodes: ['BETA01'],
        );

        $this->expectException(\TriCab\Core\QuoteOrder\Application\Exception\OrderNotFound::class);
        $this->getOrder->execute($portal, '9001');
    }

    public function test_administrator_staff_search_all_scope(): void
    {
        $admin = new QuoteOrderActor(
            userId: 9,
            publicId: 'u-admin',
            emailAddress: 'admin@tricab.com',
            permissions: [Module::PERMISSION_READ],
            roles: ['ADMINISTRATOR'],
        );
        $scope = $this->access->resolveVisibility($admin);
        self::assertTrue($scope->staffSearchAll);
        self::assertFalse($scope->isCustomerPortalScoped);

        $result = $this->search->execute($admin, new QuoteOrderSearchCriteria());
        self::assertSame(4, $result->total);
    }

    public function test_employee_with_quote_read_has_staff_search_all_until_territory_assignment(): void
    {
        $scope = $this->access->resolveVisibility($this->staffActor());
        self::assertTrue($scope->staffSearchAll);
        self::assertTrue($scope->isAdministrator === false);
    }

    public function test_mapper_currency_and_status_from_legacy_row(): void
    {
        $mapper = new LegacyQuoteOrderMapper();
        $summary = $mapper->mapSummary([
            'quote_id' => '55',
            'order_id' => null,
            'quote_date' => '2026-02-01',
            'tnet' => '10.50',
            'cy' => 3200,
            'st' => 1705,
            'cref' => 'R1',
            'cus_gen' => 0,
            'code' => 'X',
            'name' => 'X Co',
            'rep_name' => 'Rep',
        ]);

        self::assertSame('draft', $summary->status);
        self::assertSame('Draft', $summary->statusLabel);
        self::assertSame('AUD', $summary->currency);
        self::assertSame('10.50', $summary->total);
    }

    private function staffActor(): QuoteOrderActor
    {
        return new QuoteOrderActor(
            userId: 1,
            publicId: 'u-1',
            emailAddress: 'staff@tricab.com',
            permissions: [Module::PERMISSION_READ, 'ACCOUNT.LOGIN'],
            roles: ['EMPLOYEE'],
        );
    }

    private function seedFixtures(): void
    {
        $this->repo->seedFromLegacyRow(
            [
                'quote_id' => '1001',
                'quote_date' => '2026-01-10',
                'valid_date' => '2026-02-10',
                'tnet' => '504.19',
                'cy' => 3200,
                'st' => 1710,
                'cref' => 'Q-1001',
                'cus_gen' => 0,
                'customer_code' => 'ACME01',
                'customer_name' => 'Acme Pty',
                'contact_name' => null,
                'representative' => 'Alex Rep',
                'order_id' => null,
            ],
            [
                [
                    'item' => [
                        'item_id' => '5001',
                        'item_no' => '1',
                        'product_code' => 'BVA-10BK',
                        'quantity' => '100',
                        'pack_count' => '1',
                        'unit_price' => '5.04',
                        'item_reference' => '',
                    ],
                    'packs' => [
                        ['id' => '8001', 'pk' => '100', 'qty' => '1', 'comments' => 'Cut to length'],
                    ],
                ],
            ],
        );

        $quote2 = new QuoteDetails(
            id: '1002',
            type: QuoteOrderType::QUOTE,
            quoteId: '1002',
            orderId: '9001',
            date: '2026-01-15',
            validDate: '2026-02-15',
            raisedBy: 'Alex Rep',
            representative: 'Alex Rep',
            customerCode: 'ACME01',
            customerName: 'Acme Pty',
            contactName: null,
            reference: 'Q-1002',
            status: 'closed',
            statusCode: '1711',
            statusLabel: 'Closed',
            total: '1200.00',
            totalStatus: 'resolved',
            currency: 'AUD',
            items: [
                new QuoteItemDetails('5002', '1', 'CBL-1', '10', '1', '120.00', null, []),
            ],
        );
        $this->repo->seedQuote($quote2);
        $this->repo->seedOrder(new OrderDetails(
            id: '9001',
            orderId: '9001',
            customerPoNumber: 'PO-55',
            reference: 'Ship ASAP',
            issueDate: '2026-01-16',
            comments: 'Dock 2',
            quoteId: '1002',
            quote: $quote2,
        ));

        $this->repo->seedSummary(new QuoteOrderSummary(
            id: '1003',
            type: QuoteOrderType::QUOTE,
            quoteId: '1003',
            orderId: null,
            date: '2025-12-01',
            raisedBy: null,
            representative: 'Sam',
            customerCode: 'BETA01',
            customerName: 'Beta Ltd',
            reference: 'TBC-REF',
            status: 'draft',
            statusCode: '1705',
            statusLabel: 'Draft',
            total: null,
            totalStatus: 'pending',
            currency: 'AUD',
        ));

        $this->repo->seedSummary(new QuoteOrderSummary(
            id: '1004',
            type: QuoteOrderType::QUOTE,
            quoteId: '1004',
            orderId: null,
            date: '2026-03-01',
            raisedBy: 'Cust Contact',
            representative: 'Sam',
            customerCode: 'GAMMA01',
            customerName: 'Gamma',
            reference: 'OpenQ',
            status: 'open',
            statusCode: '1710',
            statusLabel: 'Open',
            total: '1.00',
            totalStatus: 'resolved',
            currency: 'USD',
        ));
    }
}
