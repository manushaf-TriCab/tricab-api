<?php

declare(strict_types=1);

namespace TriCab\Api\CustomerContactReport\Email;

use RuntimeException;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportNotice;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportNotifier;

/**
 * Records notices for tests. Never calls Microsoft Graph.
 */
final class InMemoryCustomerContactReportNotifier implements CustomerContactReportNotifier
{
    /** @var list<CustomerContactReportNotice> */
    public array $sent = [];

    public int $attempts = 0;

    public bool $fail = false;

    public function send(CustomerContactReportNotice $notice): void
    {
        $this->attempts++;
        if ($this->fail) {
            throw new RuntimeException('notification failed');
        }
        $this->sent[] = $notice;
    }
}
