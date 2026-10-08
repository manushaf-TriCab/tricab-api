<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Mail;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Mail\DisabledFinanceMailTransport;
use TriCab\Api\Finance\Mail\GraphFinanceMailTransport;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailLedger;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailboxRegistry;
use TriCab\Core\Finance\Shared\Mail\FinanceMailLedger;
use TriCab\Core\Finance\Shared\Mail\FinanceMailSender;
use TriCab\Core\Finance\Shared\Mail\FinanceMailService;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransport;
use TriCab\Core\Identity\Contract\UserRepository;

/**
 * How the settings choose the transport: mail is Graph only when switched on AND
 * the finance Graph registration is complete, and nothing is sent otherwise.
 */
final class FinanceMailWiringTest extends TestCase
{
    private const GRAPH = [
        'FINANCE_GRAPH_TENANT_ID' => 't',
        'FINANCE_GRAPH_CLIENT_ID' => 'c',
        'FINANCE_GRAPH_CLIENT_SECRET' => 's',
    ];

    /**
     * @param array<string, string> $env
     */
    private function transport(array $env): FinanceMailTransport
    {
        $container = TestAppFactory::create($env)->getContainer();
        $transport = $container->get(FinanceMailTransport::class);
        self::assertInstanceOf(FinanceMailTransport::class, $transport);

        return $transport;
    }

    public function test_by_default_mail_is_off_and_nothing_can_be_sent(): void
    {
        self::assertInstanceOf(DisabledFinanceMailTransport::class, $this->transport([]));
    }

    public function test_enabled_with_any_graph_setting_empty_is_still_off(): void
    {
        self::assertInstanceOf(DisabledFinanceMailTransport::class, $this->transport(['FINANCE_MAIL_ENABLED' => 'true']));
        foreach (array_keys(self::GRAPH) as $missing) {
            $env = ['FINANCE_MAIL_ENABLED' => 'true'] + array_diff_key(self::GRAPH, [$missing => true]);
            self::assertInstanceOf(DisabledFinanceMailTransport::class, $this->transport($env), $missing);
        }
    }

    public function test_graph_settings_alone_do_not_turn_mail_on(): void
    {
        self::assertInstanceOf(DisabledFinanceMailTransport::class, $this->transport(self::GRAPH));
    }

    public function test_enabled_with_the_finance_registration_complete_uses_graph(): void
    {
        self::assertInstanceOf(GraphFinanceMailTransport::class, $this->transport(['FINANCE_MAIL_ENABLED' => 'true'] + self::GRAPH));
    }

    public function test_the_platforms_graph_settings_are_never_the_finance_ones(): void
    {
        $env = ['FINANCE_MAIL_ENABLED' => 'true', 'GRAPH_TENANT_ID' => 't', 'GRAPH_CLIENT_ID' => 'c', 'GRAPH_CLIENT_SECRET' => 's'];

        self::assertInstanceOf(DisabledFinanceMailTransport::class, $this->transport($env));
    }

    public function test_the_default_sender_is_the_service_over_the_in_memory_twins(): void
    {
        $container = TestAppFactory::create()->getContainer();

        self::assertInstanceOf(FinanceMailService::class, $container->get(FinanceMailSender::class));
        self::assertInstanceOf(InMemoryFinanceMailboxRegistry::class, $container->get(FinanceMailboxRegistry::class));
        self::assertInstanceOf(InMemoryFinanceMailLedger::class, $container->get(FinanceMailLedger::class));
    }

    public function test_the_mailbox_route_lists_the_registry_for_finance_admin_only(): void
    {
        $app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true']);
        $container = $app->getContainer();
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);

        $users->replacePermissions(1, ['account.login', Module::PERMISSION_ACCESS], ['EMPLOYEE']);
        self::assertSame(403, $app->handle(TestAppFactory::request('GET', '/api/v1/finance/admin/mailboxes'))->getStatusCode());

        $users->replacePermissions(1, ['account.login', Module::PERMISSION_ADMIN], ['EMPLOYEE']);
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/finance/admin/mailboxes'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['mailboxes' => []], TestAppFactory::json($response)['data']);
    }
}
