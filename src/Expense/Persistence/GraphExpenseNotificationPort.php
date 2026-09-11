<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use JsonException;
use Throwable;
use TriCab\Api\Expense\Email\ExpenseEmailTemplates;
use TriCab\Api\Expense\Observability\ExpenseEventLogger;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Core\Expense\Contract\ExpenseNotificationPort;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseBankFeedEntry;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;
use TriCab\Core\Expense\Domain\Service\ExpenseSubmitNotificationRecipients;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Microsoft Graph mail adapter for TriExpense notifications (client credentials,
 * sending as the configured Accounts Payable mailbox).
 *
 * Three safety rails, in order:
 *  1. `$sendingEnabled` false → recipients are still resolved and the intent is
 *     logged, but Graph is never called. This is the default everywhere.
 *  2. `$testRecipient` set → every message is redirected to that address. The
 *     intended recipient is logged masked so a redirected run is still traceable.
 *  3. Nothing sensitive is logged: no access token, no SAS, no message body.
 *
 * Notifications are fire-and-forget by design. A receipt transition that already
 * committed must not be rolled back because mail failed, so delivery problems are
 * logged and swallowed rather than thrown back into the application service.
 */
final class GraphExpenseNotificationPort implements ExpenseNotificationPort
{
    private const GRAPH_SCOPE = 'https://graph.microsoft.com/.default';

    private const TOKEN_URL_TEMPLATE = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

    private const SEND_MAIL_URL_TEMPLATE = 'https://graph.microsoft.com/v1.0/users/%s/sendMail';

    private const MAX_ATTEMPTS = 3;

    /** Upper bound on an honoured Retry-After, so a hostile value cannot stall a worker. */
    private const MAX_RETRY_DELAY_SECONDS = 5;

    private ?string $cachedAccessToken = null;

    private int $cachedAccessTokenExpiresAt = 0;

    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly ExpenseProfileRepository $profiles,
        private readonly ExpenseEmailTemplates $templates,
        private readonly ExpenseEventLogger $logger,
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $mailFrom,
        private readonly bool $sendingEnabled,
        private readonly ?string $testRecipient = null,
        private readonly string $fromDisplayName = 'TriCab TriExpense',
        private readonly bool $pauseBetweenRetries = true,
    ) {
    }

    public function notifySubmitted(ExpenseReceipt $receipt, string $submitMode = 'draft_then_submit'): void
    {
        // Legacy recipient rule lives in its own domain helper — see
        // ExpenseSubmitNotificationRecipients for why the two modes differ.
        $recipients = ExpenseSubmitNotificationRecipients::resolve(
            $submitMode,
            $this->emailForUserId($receipt->ownerUserId()),
            $this->emailForUserId($receipt->managerUserId()),
        );

        $template = $this->templates->submitted($receipt, $submitMode);
        $this->deliver('submitted', $recipients, $template, $receipt->publicId()->toString());
    }

    public function notifyApproved(ExpenseReceipt $receipt): void
    {
        $this->deliver(
            'approved',
            $this->ownerOnly($receipt),
            $this->templates->approved($receipt),
            $receipt->publicId()->toString(),
        );
    }

    public function notifyRejected(ExpenseReceipt $receipt, string $reason): void
    {
        $this->deliver(
            'rejected',
            $this->ownerOnly($receipt),
            $this->templates->rejected($receipt, $reason),
            $receipt->publicId()->toString(),
        );
    }

    public function notifySentBack(ExpenseReceipt $receipt, string $note): void
    {
        // Send-back is a Finance action on an approved receipt: the owner has to
        // act, and the manager has to approve again.
        $recipients = $this->dedupe([
            $this->emailForUserId($receipt->ownerUserId()),
            $this->emailForUserId($receipt->managerUserId()),
        ]);

        $this->deliver(
            'sent_back',
            $recipients,
            $this->templates->sentBack($receipt, $note),
            $receipt->publicId()->toString(),
        );
    }

    public function notifyChaseBankTx(ExpenseBankFeedEntry $entry): void
    {
        $this->deliver(
            'chase_bank_tx',
            $this->dedupe([$entry->cardholderEmail()]),
            $this->templates->chaseBankTransaction($entry),
            $entry->publicId()->toString(),
        );
    }

    public function notifyMissingReceiptsDigest(array $recipientEmails, string $summary): void
    {
        $this->deliver(
            'missing_receipts_digest',
            $this->dedupe($recipientEmails),
            $this->templates->missingReceiptsDigest($summary),
            null,
        );
    }

    public function notifyManagerPendingDigest(array $recipientEmails, string $summary): void
    {
        $this->deliver(
            'manager_pending_digest',
            $this->dedupe($recipientEmails),
            $this->templates->managerPendingDigest($summary),
            null,
        );
    }

    /**
     * @return list<string>
     */
    private function ownerOnly(ExpenseReceipt $receipt): array
    {
        return $this->dedupe([$this->emailForUserId($receipt->ownerUserId())]);
    }

    /**
     * @param list<string> $recipients
     * @param array{subject: string, html: string} $template
     */
    private function deliver(string $event, array $recipients, array $template, ?string $reference): void
    {
        if ($recipients === []) {
            $this->logger->log('expense.email.skipped', [
                'notification' => $event,
                'reason' => 'no_resolvable_recipient',
                'reference' => $reference,
            ]);

            return;
        }

        foreach ($recipients as $intended) {
            $actual = $this->testRecipient ?? $intended;
            $redirected = $actual !== $intended;

            if (!$this->sendingEnabled) {
                $this->logger->log('expense.email.suppressed', [
                    'notification' => $event,
                    'intendedRecipient' => ExpenseEventLogger::maskEmail($intended),
                    'reference' => $reference,
                    'reason' => 'email_disabled',
                ]);
                continue;
            }

            try {
                $status = $this->sendMailWithRetry($actual, $template);
            } catch (Throwable $failure) {
                $this->logger->log('expense.email.failed', [
                    'notification' => $event,
                    'intendedRecipient' => ExpenseEventLogger::maskEmail($intended),
                    'redirected' => $redirected,
                    'reference' => $reference,
                    'reason' => 'transport_error',
                    'detail' => $failure->getMessage(),
                ]);
                continue;
            }

            if ($status < 200 || $status > 299) {
                $this->logger->log('expense.email.failed', [
                    'notification' => $event,
                    'intendedRecipient' => ExpenseEventLogger::maskEmail($intended),
                    'redirected' => $redirected,
                    'reference' => $reference,
                    'status' => $status,
                ]);
                continue;
            }

            $this->logger->log('expense.email.sent', [
                'notification' => $event,
                'intendedRecipient' => ExpenseEventLogger::maskEmail($intended),
                'redirected' => $redirected,
                'reference' => $reference,
                'status' => $status,
            ]);
        }
    }

    /**
     * @param array{subject: string, html: string} $template
     */
    private function sendMailWithRetry(string $recipient, array $template): int
    {
        $payload = [
            'message' => [
                'subject' => $template['subject'],
                'body' => [
                    'contentType' => 'HTML',
                    'content' => $template['html'],
                ],
                'toRecipients' => [
                    ['emailAddress' => ['address' => $recipient]],
                ],
                'from' => [
                    'emailAddress' => [
                        'address' => $this->mailFrom,
                        'name' => $this->fromDisplayName,
                    ],
                ],
            ],
            'saveToSentItems' => false,
        ];

        $url = sprintf(self::SEND_MAIL_URL_TEMPLATE, rawurlencode($this->mailFrom));
        $lastStatus = 0;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $token = $this->acquireAccessToken();

            try {
                $response = $this->http->postJson(
                    $url,
                    ['Authorization' => 'Bearer ' . $token],
                    $payload,
                );
            } catch (Throwable $transport) {
                if ($attempt === self::MAX_ATTEMPTS) {
                    throw $transport;
                }
                $this->pause(self::backoffSeconds($attempt));
                continue;
            }

            $lastStatus = $response['status'];
            if ($lastStatus >= 200 && $lastStatus <= 299) {
                return $lastStatus;
            }

            // 401/403 mean the app registration is wrong, and 400/404 mean the
            // request is. Retrying either just burns the throttle budget.
            if ($lastStatus >= 400 && $lastStatus < 500 && $lastStatus !== 429) {
                if ($lastStatus === 401 || $lastStatus === 403) {
                    $this->cachedAccessToken = null;
                    $this->cachedAccessTokenExpiresAt = 0;
                }

                return $lastStatus;
            }

            if ($attempt === self::MAX_ATTEMPTS) {
                return $lastStatus;
            }

            $this->pause($this->retryDelaySeconds($response, $attempt));
        }

        return $lastStatus;
    }

    /**
     * @param array{status: int, body: string, headers?: array<string, string>} $response
     */
    private function retryDelaySeconds(array $response, int $attempt): int
    {
        $headers = $response['headers'] ?? [];
        $retryAfter = $headers['retry-after'] ?? null;
        if (is_string($retryAfter) && preg_match('/^\d+$/', trim($retryAfter)) === 1) {
            return max(1, min(self::MAX_RETRY_DELAY_SECONDS, (int) trim($retryAfter)));
        }

        return self::backoffSeconds($attempt);
    }

    private static function backoffSeconds(int $attempt): int
    {
        return min(self::MAX_RETRY_DELAY_SECONDS, $attempt);
    }

    private function pause(int $seconds): void
    {
        if ($this->pauseBetweenRetries && $seconds > 0) {
            sleep($seconds);
        }
    }

    private function acquireAccessToken(): string
    {
        $now = time();
        if (
            $this->cachedAccessToken !== null
            && $this->cachedAccessToken !== ''
            && $now < ($this->cachedAccessTokenExpiresAt - 60)
        ) {
            return $this->cachedAccessToken;
        }

        $response = $this->http->postForm(
            sprintf(self::TOKEN_URL_TEMPLATE, rawurlencode($this->tenantId)),
            [],
            [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => self::GRAPH_SCOPE,
                'grant_type' => 'client_credentials',
            ],
        );

        if ($response['status'] < 200 || $response['status'] > 299) {
            throw new GraphExpenseNotificationException(
                'Graph token endpoint returned status ' . $response['status'] . '.',
            );
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new GraphExpenseNotificationException('Graph token response was not JSON.');
        }

        $token = is_array($decoded) ? ($decoded['access_token'] ?? null) : null;
        if (!is_string($token) || $token === '') {
            throw new GraphExpenseNotificationException('Graph token response had no access_token.');
        }

        $expiresIn = is_array($decoded) ? ($decoded['expires_in'] ?? 3600) : 3600;
        $ttl = is_int($expiresIn) || (is_string($expiresIn) && is_numeric($expiresIn))
            ? (int) $expiresIn
            : 3600;

        $this->cachedAccessToken = $token;
        $this->cachedAccessTokenExpiresAt = $now + max(60, $ttl);

        return $token;
    }

    /**
     * Owner and manager addresses come from the ExpenseProfile, and the manager
     * is the one frozen onto the receipt at submit — never a freshly resolved
     * one, so a reporting-line change cannot redirect mail about an old receipt.
     */
    private function emailForUserId(?UserId $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        try {
            $profile = $this->profiles->findByUserId($userId);
        } catch (Throwable) {
            return null;
        }

        $email = $profile?->legacyEmail();

        return $email !== null && $email !== '' ? $email : null;
    }

    /**
     * @param list<?string> $candidates
     * @return list<string>
     */
    private function dedupe(array $candidates): array
    {
        $out = [];
        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }
            $normalized = strtolower(trim($candidate));
            if ($normalized === '' || !str_contains($normalized, '@')) {
                continue;
            }
            if (!in_array($normalized, $out, true)) {
                $out[] = $normalized;
            }
        }

        return $out;
    }
}
