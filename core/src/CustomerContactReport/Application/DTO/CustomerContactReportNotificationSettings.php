<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\DTO;

/**
 * Deployment values the notification builder is allowed to see.
 * Empty strings mean "not configured", which omits that recipient or link.
 */
final readonly class CustomerContactReportNotificationSettings
{
    public function __construct(
        public string $webBaseUrl = '',
        public string $coordinatorLogin = '',
        public string $accountsMailbox = '',
    ) {
    }
}
