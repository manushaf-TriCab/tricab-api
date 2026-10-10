<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Core\Finance\Ap\Domain\ApActor;
use TriCab\Core\Finance\Ap\Domain\ApMailboxScope;
use TriCab\Core\Finance\Ap\Service\ApMailboxService;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;

/** `GET /mailboxes` over the registry's grants for the application `AP` (T6.3). */
final class ApMailboxServiceTest extends TestCase
{
    private ApMailboxService $service;

    protected function setUp(): void
    {
        $this->service = new ApMailboxService(new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('aaaa', 'ap@example.test', 'Accounts Payable', '', true, ['AP' => ['read' => true, 'send' => true], 'AR' => ['read' => true, 'send' => false]]),
            new FinanceMailbox('bbbb', 'christine@example.test', 'Christine', '', true, ['AP' => ['read' => true, 'send' => false]]),
            new FinanceMailbox('cccc', 'ar@example.test', 'Accounts Receivable', '', true, ['AR' => ['read' => true, 'send' => true]]),
            new FinanceMailbox('dddd', 'old@example.test', 'Retired', '', false, ['AP' => ['read' => true, 'send' => true]]),
            new FinanceMailbox('eeee', 'bare@example.test', 'No grants', '', true, []),
        ]));
    }

    public function test_it_lists_the_mailboxes_tri_ap_has_a_grant_on_with_the_shape_the_pages_read(): void
    {
        $rows = $this->service->list(new ApActor(1, 'A', 'a@example.test', []));

        self::assertSame([
            ['id' => 'aaaa', 'email' => 'ap@example.test', 'label' => 'Accounts Payable', 'is_active' => true, 'can_read' => true, 'can_send' => true],
            ['id' => 'bbbb', 'email' => 'christine@example.test', 'label' => 'Christine', 'is_active' => true, 'can_read' => true, 'can_send' => false],
            ['id' => 'dddd', 'email' => 'old@example.test', 'label' => 'Retired', 'is_active' => false, 'can_read' => false, 'can_send' => false],
        ], $rows, 'AR-only and grantless mailboxes are not Tri-AP\'s; an inactive one is shown inactive and cannot be used');
    }

    public function test_a_scoped_person_sees_only_their_mailboxes(): void
    {
        $actor = new ApActor(2, 'B', 'b@example.test', [], ApMailboxScope::limitedTo(['Christine@example.test']));

        $rows = $this->service->list($actor);

        self::assertSame(['christine@example.test'], array_column($rows, 'email'));
    }

    public function test_an_administrator_with_scope_rows_is_unrestricted_because_the_scope_is_built_unrestricted(): void
    {
        $actor = new ApActor(3, 'C', 'c@example.test', [], ApMailboxScope::unrestricted(), true);

        self::assertCount(3, $this->service->list($actor));
    }
}
