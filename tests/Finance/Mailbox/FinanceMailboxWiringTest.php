<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Mailbox;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Shared\Mailbox\DisabledMailboxReader;
use TriCab\Api\Shared\Mailbox\GraphMailboxReader;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Shared\Mailbox\FinanceMailboxReader;
use TriCab\Core\Finance\Shared\Mailbox\FinanceMailboxReadService;
use TriCab\Core\Shared\Mailbox\MailboxReader;

/**
 * How the settings choose the reader: Graph only when mailbox reading is switched on AND
 * the finance Graph registration is complete, and nothing is read otherwise. Reading has
 * its own switch: sending being on never turns it on.
 */
final class FinanceMailboxWiringTest extends TestCase
{
    private const GRAPH = [
        'FINANCE_GRAPH_TENANT_ID' => 't',
        'FINANCE_GRAPH_CLIENT_ID' => 'c',
        'FINANCE_GRAPH_CLIENT_SECRET' => 's',
    ];

    /**
     * @param array<string, string> $env
     */
    private function reader(array $env): MailboxReader
    {
        $container = TestAppFactory::create($env)->getContainer();
        $reader = $container->get(MailboxReader::class);
        self::assertInstanceOf(MailboxReader::class, $reader);

        return $reader;
    }

    public function test_by_default_reading_is_off(): void
    {
        self::assertInstanceOf(DisabledMailboxReader::class, $this->reader([]));
    }

    public function test_enabled_with_any_graph_setting_empty_is_still_off(): void
    {
        self::assertInstanceOf(DisabledMailboxReader::class, $this->reader(['FINANCE_MAILBOX_READ_ENABLED' => 'true']));
        foreach (array_keys(self::GRAPH) as $missing) {
            $env = ['FINANCE_MAILBOX_READ_ENABLED' => 'true'] + array_diff_key(self::GRAPH, [$missing => true]);
            self::assertInstanceOf(DisabledMailboxReader::class, $this->reader($env), $missing);
        }
    }

    public function test_graph_settings_alone_and_mail_sending_do_not_turn_reading_on(): void
    {
        self::assertInstanceOf(DisabledMailboxReader::class, $this->reader(self::GRAPH));
        self::assertInstanceOf(DisabledMailboxReader::class, $this->reader(['FINANCE_MAIL_ENABLED' => 'true'] + self::GRAPH));
    }

    public function test_enabled_with_the_finance_registration_complete_uses_graph(): void
    {
        self::assertInstanceOf(GraphMailboxReader::class, $this->reader(['FINANCE_MAILBOX_READ_ENABLED' => 'true'] + self::GRAPH));
    }

    public function test_the_platforms_graph_settings_are_never_the_finance_ones(): void
    {
        $env = ['FINANCE_MAILBOX_READ_ENABLED' => 'true', 'GRAPH_TENANT_ID' => 't', 'GRAPH_CLIENT_ID' => 'c', 'GRAPH_CLIENT_SECRET' => 's'];

        self::assertInstanceOf(DisabledMailboxReader::class, $this->reader($env));
    }

    public function test_the_finance_reader_is_the_service_and_is_registered_with_no_route(): void
    {
        $container = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AP_ENABLED' => 'true'])->getContainer();

        self::assertInstanceOf(FinanceMailboxReadService::class, $container->get(FinanceMailboxReader::class));
    }
}
