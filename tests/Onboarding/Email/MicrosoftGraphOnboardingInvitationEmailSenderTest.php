<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding\Email;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Onboarding\Email\MicrosoftGraphOnboardingInvitationEmailSender;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

/**
 * A refused invitation says why — the provider's error code — so the log line
 * the invite service writes can name an expired secret (AADSTS7000215) rather
 * than a bare 401. Never the description, which quotes the client id, and
 * never the recipient or a credential.
 */
final class MicrosoftGraphOnboardingInvitationEmailSenderTest extends TestCase
{
    public function test_an_expired_client_secret_is_named_by_its_code(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [[
            'status' => 401,
            'body' => '{"error":"invalid_client","error_description":"AADSTS7000215: Invalid client secret provided for app client-456.","error_codes":[7000215]}',
        ]];

        $message = $this->failureOf($http);

        self::assertSame('Microsoft identity platform answered 401 (invalid_client, AADSTS7000215).', $message);
    }

    public function test_a_refused_send_is_named_by_graph_s_code(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            ['status' => 200, 'body' => '{"access_token":"token-abc","expires_in":3600}'],
            ['status' => 403, 'body' => '{"error":{"code":"ErrorAccessDenied","message":"Access is denied for sam@example.test."}}'],
        ];

        $message = $this->failureOf($http);

        self::assertSame('Microsoft Graph sendMail answered 403 (ErrorAccessDenied).', $message);
        self::assertStringNotContainsString('sam@example.test', $message);
    }

    public function test_a_body_that_is_not_json_adds_nothing(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [['status' => 503, 'body' => '<html>busy</html>']];

        self::assertSame('Microsoft identity platform answered 503.', $this->failureOf($http));
    }

    private function failureOf(FakeJsonHttpClient $http): string
    {
        $sender = new MicrosoftGraphOnboardingInvitationEmailSender($http, 'tenant-123', 'client-456', 'secret-789', 'onboarding@tricab.com');

        try {
            $sender->sendInvitation(EmailAddress::fromString('sam@example.test'), 'Sam', 'https://tricab.ai/onboarding');
        } catch (RuntimeException $failure) {
            self::assertStringNotContainsString('secret-789', $failure->getMessage());
            self::assertStringNotContainsString('client-456', $failure->getMessage());

            return $failure->getMessage();
        }

        self::fail('the send did not fail');
    }
}
