<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Ar\Service\ArMailboxScanJob;
use TriCab\Core\Finance\Ar\Service\ArOverdueRemindersJob;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobRegistry;

/**
 * Which Tri-AR jobs the registry holds for which switches (T5.7, T5.11): the scan needs the app and mailbox reading, the
 * reminders need the app, Tri-AR's own sending switch and a working mail service. Nothing here runs a job.
 */
final class FinanceArReminderWiringTest extends TestCase
{
    private const APP = ['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AR_ENABLED' => 'true'];
    private const GRAPH = ['FINANCE_GRAPH_TENANT_ID' => 't', 'FINANCE_GRAPH_CLIENT_ID' => 'c', 'FINANCE_GRAPH_CLIENT_SECRET' => 's'];

    /**
     * @param array<string, string> $env
     * @return list<string>
     */
    private function jobs(array $env): array
    {
        $registry = TestAppFactory::create($env)->getContainer()->get(FinanceJobRegistry::class);
        self::assertInstanceOf(FinanceJobRegistry::class, $registry);

        return array_map(static fn ($job): string => $job->name(), $registry->all());
    }

    public function test_the_reminders_need_the_app_the_sending_switch_and_a_working_mail_service(): void
    {
        $sending = ['FINANCE_AR_SENDING_ENABLED' => 'true'];
        $mail = ['FINANCE_MAIL_ENABLED' => 'true'] + self::GRAPH;

        self::assertNotContains(ArOverdueRemindersJob::NAME, $this->jobs(self::APP), 'nothing on');
        self::assertNotContains(ArOverdueRemindersJob::NAME, $this->jobs(self::APP + $mail), 'mail on but Tri-AR sending off');
        self::assertNotContains(ArOverdueRemindersJob::NAME, $this->jobs(self::APP + $sending), 'Tri-AR sending on but mail off: every morning would leave suppressed rows');
        self::assertNotContains(ArOverdueRemindersJob::NAME, $this->jobs(self::APP + $sending + ['FINANCE_MAIL_ENABLED' => 'true']), 'mail on but the Graph settings missing');
        self::assertContains(ArOverdueRemindersJob::NAME, $this->jobs(self::APP + $sending + $mail));
        self::assertNotContains(ArOverdueRemindersJob::NAME, $this->jobs(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AR_ENABLED' => 'false'] + $sending + $mail), 'app off');
        self::assertNotContains(ArOverdueRemindersJob::NAME, $this->jobs(['FINANCE_MODULE_ENABLED' => 'false', 'FINANCE_AR_ENABLED' => 'true'] + $sending + $mail), 'module off');
    }

    public function test_the_two_jobs_are_independent_and_the_scan_sorts_before_the_reminders(): void
    {
        $reading = ['FINANCE_MAILBOX_READ_ENABLED' => 'true'] + self::GRAPH;
        $both = $this->jobs(self::APP + $reading + ['FINANCE_AR_SENDING_ENABLED' => 'true', 'FINANCE_MAIL_ENABLED' => 'true']);

        self::assertContains(ArMailboxScanJob::NAME, $both);
        self::assertContains(ArOverdueRemindersJob::NAME, $both);
        self::assertContains(ArMailboxScanJob::NAME, $this->jobs(self::APP + $reading), 'the scan does not need sending');
        self::assertNotContains(ArOverdueRemindersJob::NAME, $this->jobs(self::APP + $reading));
        $sorted = [ArMailboxScanJob::NAME, ArOverdueRemindersJob::NAME];
        sort($sorted);
        self::assertSame([ArMailboxScanJob::NAME, ArOverdueRemindersJob::NAME], $sorted, 'the runner takes jobs in name order');
    }
}
