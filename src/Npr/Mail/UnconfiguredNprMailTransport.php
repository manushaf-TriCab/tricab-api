<?php

declare(strict_types=1);

namespace TriCab\Api\Npr\Mail;

use TriCab\Core\Npr\Mail\NprMailFailed;
use TriCab\Core\Npr\Mail\NprMailMessage;
use TriCab\Core\Npr\Mail\NprMailTransport;

/**
 * Bound when NPR_MAIL_MODE is test or live but the Graph registration or
 * NPR_MAIL_SENDER is missing: every send fails visibly on the delivery row,
 * rather than the app refusing to start or the mail vanishing.
 */
final class UnconfiguredNprMailTransport implements NprMailTransport
{
    public function send(NprMailMessage $message): void
    {
        throw new NprMailFailed('NPR mail is switched on but not configured: GRAPH_TENANT_ID, GRAPH_CLIENT_ID, GRAPH_CLIENT_SECRET and NPR_MAIL_SENDER are required.');
    }
}
