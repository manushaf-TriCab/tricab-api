<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\QuoteOrder;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Api\QuoteOrder\Legacy\MysqlLegacyQuoteOrderRepository;
use TriCab\Api\QuoteOrder\Persistence\InMemoryQuoteOrderRepository;
use TriCab\Api\Tests\Doubles\RecordingQuoteOrderLegacyConnection;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;
use TriCab\Core\QuoteOrder\Application\DTO\OrderDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderActor;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderSearchCriteria;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderVisibilityScope;
use TriCab\Core\QuoteOrder\Application\Exception\OrderNotFound;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteNotFound;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteOrderAccessDenied;
use TriCab\Core\QuoteOrder\Application\Service\GetOrderService;
use TriCab\Core\QuoteOrder\Application\Service\GetQuoteService;
use TriCab\Core\QuoteOrder\Application\Service\QuoteOrderAccessService;
use TriCab\Core\QuoteOrder\Application\Service\SearchQuoteOrdersService;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteOrderType;
use TriCab\Core\QuoteOrder\Module;

/**
 * Only eligible staff may search every customer's quotes and orders. Every
 * other actor, including one with no customer links, sees its linked
 * customers or nothing. Synthetic fixtures only.
 */
final class QuoteOrderFailClosedAccessTest extends TestCase
{
    private InMemoryQuoteOrderRepository $repo;
    private QuoteOrderAccessService $access;
    private SearchQuoteOrdersService $search;
    private GetQuoteService $getQuote;
    private GetOrderService $getOrder;

    protected function setUp(): void
    {
        $this->repo = new InMemoryQuoteOrderRepository();
        $this->access = new QuoteOrderAccessService();
        $this->search = new SearchQuoteOrdersService($this->access, $this->repo, true);
        $this->getQuote = new GetQuoteService($this->access, $this->repo, true);
        $this->getOrder = new GetOrderService($this->access, $this->repo, true);

        $this->repo->seedQuote($this->quote('1001', 'ACME01'));
        $this->repo->seedOrder($this->order('9001', $this->quote('2001', 'ACME01', '9001')));
        $this->repo->seedQuote($this->quote('3001', 'BETA01'));
        $this->repo->seedOrder($this->order('9002', $this->quote('3002', 'BETA01', '9002')));
        $this->repo->seedQuote($this->quote('4001', 'GAMMA01'));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function nonStaffRoleSets(): iterable
    {
        yield 'customer user' => [['CUSTOMER_USER']];
        yield 'customer admin' => [['CUSTOMER_ADMIN']];
        yield 'newbie applicant' => [['NEWBIE']];
        yield 'customer applicant' => [['CUSTOMER_APPLICANT']];
        yield 'future portal role' => [['PORTAL_VIEWER']];
        yield 'guest' => [['GUEST']];
        yield 'specialist role without EMPLOYEE' => [['RFQ_SALES']];
        yield 'no roles' => [[]];
        yield 'customer user + employee' => [['CUSTOMER_USER', 'EMPLOYEE']];
        yield 'customer admin + administrator' => [['CUSTOMER_ADMIN', 'ADMINISTRATOR']];
        yield 'newbie + employee' => [['NEWBIE', 'EMPLOYEE']];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('nonStaffRoleSets')]
    public function test_non_staff_without_links_sees_nothing(array $roles): void
    {
        $actor = $this->actor($roles);
        $scope = $this->access->resolveVisibility($actor);

        self::assertFalse($scope->staffSearchAll);
        self::assertFalse($scope->isAdministrator);
        self::assertFalse($scope->isUnrestricted());
        self::assertTrue($scope->isCustomerPortalScoped);
        self::assertTrue($scope->forcesEmptyResults());

        $result = $this->search->execute($actor, new QuoteOrderSearchCriteria(type: QuoteOrderType::ALL));
        self::assertSame(0, $result->total);
        self::assertSame([], $result->items);

        $filtered = $this->search->execute($actor, new QuoteOrderSearchCriteria(customerCode: 'BETA'));
        self::assertSame(0, $filtered->total);

        $this->assertQuoteHidden($actor, '1001');
        $this->assertQuoteHidden($actor, '3001');
        $this->assertOrderHidden($actor, '9001');
        $this->assertOrderHidden($actor, '9002');
        self::assertFalse($this->access->canViewCustomerCode($scope, 'ACME01'));
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('nonStaffRoleSets')]
    public function test_non_staff_with_one_link_sees_only_that_customer(array $roles): void
    {
        $actor = $this->actor($roles, ['cust-acme'], ['ACME01']);
        $scope = $this->access->resolveVisibility($actor);

        self::assertFalse($scope->isUnrestricted());
        self::assertSame(['ACME01'], $this->visibleCodes($actor));
        self::assertSame('1001', $this->getQuote->execute($actor, '1001')->quoteId);
        self::assertSame('9001', $this->getOrder->execute($actor, '9001')->orderId);
        $this->assertQuoteHidden($actor, '3001');
        $this->assertQuoteHidden($actor, '4001');
        $this->assertOrderHidden($actor, '9002');
    }

    public function test_customer_with_two_links_sees_both_and_nothing_else(): void
    {
        $actor = $this->actor(['CUSTOMER_USER'], ['cust-acme', 'cust-beta'], ['ACME01', 'BETA01']);

        self::assertSame(['ACME01', 'BETA01'], $this->visibleCodes($actor));
        self::assertSame('3001', $this->getQuote->execute($actor, '3001')->quoteId);
        self::assertSame('9002', $this->getOrder->execute($actor, '9002')->orderId);
        $this->assertQuoteHidden($actor, '4001');
    }

    public function test_customer_code_filter_cannot_expand_scope(): void
    {
        $actor = $this->actor(['CUSTOMER_USER'], ['cust-acme'], ['ACME01']);

        foreach (['BETA', 'GAMMA01', 'A', '__UNAUTHORIZED__'] as $filter) {
            $result = $this->search->execute($actor, new QuoteOrderSearchCriteria(customerCode: $filter));
            foreach ($result->items as $item) {
                self::assertSame('ACME01', $item->customerCode, 'filter ' . $filter);
            }
        }
    }

    public function test_foreign_records_are_indistinguishable_from_missing_ones(): void
    {
        $actor = $this->actor(['CUSTOMER_USER'], ['cust-acme'], ['ACME01']);

        self::assertSame(
            str_replace('7777', '{id}', $this->quoteFailure($actor, '7777')),
            str_replace('3001', '{id}', $this->quoteFailure($actor, '3001')),
        );
        self::assertSame(
            str_replace('7777', '{id}', $this->orderFailure($actor, '7777')),
            str_replace('9002', '{id}', $this->orderFailure($actor, '9002')),
        );
    }

    /**
     * @param list<string> $customerPublicIds
     * @param list<string> $customerCodes
     */
    #[DataProvider('missingOrMalformedContexts')]
    public function test_missing_or_malformed_customer_context_sees_nothing(array $customerPublicIds, array $customerCodes): void
    {
        $actor = $this->actor(['CUSTOMER_USER'], $customerPublicIds, $customerCodes);

        self::assertSame(0, $this->search->execute($actor, new QuoteOrderSearchCriteria())->total);
        $this->assertQuoteHidden($actor, '1001');
        $this->assertOrderHidden($actor, '9001');
    }

    /**
     * @return iterable<string, array{list<string>, list<string>}>
     */
    public static function missingOrMalformedContexts(): iterable
    {
        yield 'no context' => [[], []];
        yield 'link with no mapped customer code' => [['cust-unmapped'], []];
        yield 'blank customer codes' => [['cust-blank'], ['', '   ']];
        yield 'codes without links' => [[], ['']];
    }

    public function test_employee_keeps_staff_wide_access(): void
    {
        $actor = $this->actor(['EMPLOYEE']);
        $scope = $this->access->resolveVisibility($actor);

        self::assertTrue($scope->isUnrestricted());
        self::assertFalse($scope->isAdministrator);
        self::assertSame(['ACME01', 'BETA01', 'GAMMA01'], $this->visibleCodes($actor));
        self::assertSame('3001', $this->getQuote->execute($actor, '3001')->quoteId);
        self::assertSame('9002', $this->getOrder->execute($actor, '9002')->orderId);
    }

    public function test_administrator_keeps_staff_wide_access(): void
    {
        $actor = $this->actor(['ADMINISTRATOR', 'EMPLOYEE']);
        $scope = $this->access->resolveVisibility($actor);

        self::assertTrue($scope->isUnrestricted());
        self::assertTrue($scope->isAdministrator);
        self::assertSame(['ACME01', 'BETA01', 'GAMMA01'], $this->visibleCodes($actor));

        $adminOnly = $this->actor(['ADMINISTRATOR']);
        self::assertTrue($this->access->resolveVisibility($adminOnly)->isUnrestricted());
    }

    public function test_staff_with_guest_role_left_over_keeps_access(): void
    {
        self::assertTrue($this->access->resolveVisibility($this->actor(['EMPLOYEE', 'GUEST']))->isUnrestricted());
    }

    public function test_quote_read_is_still_required(): void
    {
        $actor = new QuoteOrderActor(1, 'u-1', 'staff@example.test', ['ACCOUNT.LOGIN'], ['EMPLOYEE']);

        $this->expectException(QuoteOrderAccessDenied::class);
        $this->access->resolveVisibility($actor);
    }

    public function test_scope_without_a_positive_staff_decision_fails_closed(): void
    {
        $malformed = new QuoteOrderVisibilityScope(
            canSearch: true,
            isCustomerPortalScoped: false,
            isAdministrator: false,
            staffSearchAll: false,
        );
        self::assertFalse($malformed->isUnrestricted());
        self::assertTrue($malformed->forcesEmptyResults());
        self::assertNull($this->repo->findQuoteById('1001', $malformed));
        self::assertNull($this->repo->findOrderById('9001', $malformed));
        self::assertSame(0, $this->repo->search(new QuoteOrderSearchCriteria(), $malformed)->total);
        self::assertFalse($this->access->canViewCustomerCode($malformed, 'ACME01'));

        $contradictory = new QuoteOrderVisibilityScope(
            canSearch: true,
            isCustomerPortalScoped: true,
            isAdministrator: true,
            staffSearchAll: true,
            allowedCustomerCodes: ['ACME01'],
        );
        self::assertFalse($contradictory->isUnrestricted());
        self::assertNull($this->repo->findQuoteById('3001', $contradictory));
        self::assertFalse($this->access->canViewCustomerCode($contradictory, 'BETA01'));
    }

    public function test_legacy_sql_is_customer_filtered_for_every_restricted_scope(): void
    {
        $restricted = [
            'portal' => new QuoteOrderVisibilityScope(true, true, false, false, ['ACME01']),
            'not staff, not portal' => new QuoteOrderVisibilityScope(true, false, false, false, ['ACME01']),
            'contradictory' => new QuoteOrderVisibilityScope(true, true, true, true, ['ACME01']),
        ];

        foreach ($restricted as $label => $scope) {
            $connection = new RecordingQuoteOrderLegacyConnection();
            $repository = new MysqlLegacyQuoteOrderRepository($connection, new LegacyReadOnlySqlGuard());

            $repository->search(new QuoteOrderSearchCriteria(), $scope);
            $repository->findQuoteById('3001', $scope);

            $headerQueries = $connection->queriesFor(['searchCount', 'search', 'findQuoteById']);
            self::assertCount(3, $headerQueries, $label);
            foreach ($headerQueries as $query) {
                self::assertStringContainsString('c.code IN (:vis_code_0)', $query['sql'], $label . ' ' . $query['method']);
                self::assertSame('ACME01', $query['params'][':vis_code_0'] ?? null, $label . ' ' . $query['method']);
            }
        }
    }

    public function test_legacy_sql_runs_no_query_for_restricted_scope_without_codes(): void
    {
        $connection = new RecordingQuoteOrderLegacyConnection();
        $repository = new MysqlLegacyQuoteOrderRepository($connection, new LegacyReadOnlySqlGuard());
        $scope = new QuoteOrderVisibilityScope(true, false, false, false);

        self::assertSame(0, $repository->search(new QuoteOrderSearchCriteria(), $scope)->total);
        self::assertNull($repository->findQuoteById('3001', $scope));
        self::assertNull($repository->findOrderById('9002', $scope));
        self::assertSame([], $connection->queries);
    }

    public function test_legacy_order_whose_quote_is_out_of_scope_is_hidden(): void
    {
        $restrictedConnection = new RecordingQuoteOrderLegacyConnection();
        $restrictedRepository = new MysqlLegacyQuoteOrderRepository($restrictedConnection, new LegacyReadOnlySqlGuard());
        $restricted = new QuoteOrderVisibilityScope(true, false, false, false, ['ACME01']);
        self::assertNull($restrictedRepository->findOrderById('9002', $restricted));
        $restrictedQuoteQueries = $restrictedConnection->queriesFor(['findQuoteById']);
        self::assertCount(1, $restrictedQuoteQueries);
        self::assertStringContainsString('c.code IN (:vis_code_0)', $restrictedQuoteQueries[0]['sql']);

        $staffConnection = new RecordingQuoteOrderLegacyConnection();
        $staffRepository = new MysqlLegacyQuoteOrderRepository($staffConnection, new LegacyReadOnlySqlGuard());
        $staff = new QuoteOrderVisibilityScope(true, false, false, true);
        self::assertNotNull($staffRepository->findOrderById('9002', $staff));
        $staffQuoteQueries = $staffConnection->queriesFor(['findQuoteById']);
        self::assertCount(1, $staffQuoteQueries);
        self::assertStringNotContainsString('c.code IN', $staffQuoteQueries[0]['sql']);
    }

    /**
     * @param list<string> $roles
     * @param list<string> $customerPublicIds
     * @param list<string> $customerCodes
     */
    private function actor(array $roles, array $customerPublicIds = [], array $customerCodes = []): QuoteOrderActor
    {
        return new QuoteOrderActor(
            userId: 42,
            publicId: 'u-42',
            emailAddress: 'actor@example.test',
            permissions: ['ACCOUNT.LOGIN', Module::PERMISSION_READ],
            roles: $roles,
            customerPublicIds: $customerPublicIds,
            allowedCustomerCodes: $customerCodes,
        );
    }

    /**
     * @return list<string>
     */
    private function visibleCodes(QuoteOrderActor $actor): array
    {
        $result = $this->search->execute($actor, new QuoteOrderSearchCriteria(type: QuoteOrderType::ALL, pageSize: 100));
        $codes = array_values(array_unique(array_map(
            static fn ($item): string => (string) $item->customerCode,
            $result->items,
        )));
        sort($codes);

        return $codes;
    }

    private function assertQuoteHidden(QuoteOrderActor $actor, string $quoteId): void
    {
        self::assertSame(QuoteNotFound::class . ': Quote not found: ' . $quoteId, $this->quoteFailure($actor, $quoteId));
    }

    private function assertOrderHidden(QuoteOrderActor $actor, string $orderId): void
    {
        self::assertSame(OrderNotFound::class . ': Order not found: ' . $orderId, $this->orderFailure($actor, $orderId));
    }

    private function quoteFailure(QuoteOrderActor $actor, string $quoteId): string
    {
        try {
            $this->getQuote->execute($actor, $quoteId);
        } catch (\Throwable $e) {
            return $e::class . ': ' . $e->getMessage();
        }

        return 'visible';
    }

    private function orderFailure(QuoteOrderActor $actor, string $orderId): string
    {
        try {
            $this->getOrder->execute($actor, $orderId);
        } catch (\Throwable $e) {
            return $e::class . ': ' . $e->getMessage();
        }

        return 'visible';
    }

    private function quote(string $quoteId, string $customerCode, ?string $orderId = null): QuoteDetails
    {
        return new QuoteDetails(
            id: $quoteId,
            type: QuoteOrderType::QUOTE,
            quoteId: $quoteId,
            orderId: $orderId,
            date: '2026-10-01',
            validDate: '2026-10-31',
            raisedBy: 'Synthetic',
            representative: 'Synthetic',
            customerCode: $customerCode,
            customerName: 'Synthetic ' . $customerCode,
            contactName: null,
            reference: 'SYN-' . $quoteId,
            status: 'open',
            statusCode: '1710',
            statusLabel: 'Open',
            total: '10.00',
            totalStatus: 'resolved',
            currency: 'AUD',
        );
    }

    private function order(string $orderId, QuoteDetails $quote): OrderDetails
    {
        return new OrderDetails(
            id: $orderId,
            orderId: $orderId,
            customerPoNumber: 'PO-' . $orderId,
            reference: null,
            issueDate: '2026-10-02',
            comments: null,
            quoteId: $quote->quoteId,
            quote: $quote,
        );
    }

}
