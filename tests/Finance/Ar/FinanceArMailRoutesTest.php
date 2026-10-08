<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Finance\Ar\InMemoryArStore;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\Finance\Mail\RecordingReplyMailTransport;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mail\FinanceMailboxRegistry;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransport;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;
use TriCab\Core\Identity\Contract\UserRepository;

/**
 * The reply composer's three routes (T5.9) through the real app on the in-memory stores and a transport that sends
 * nothing: the envelope, statuses and codes, the gates, and the sending switch.
 */
final class FinanceArMailRoutesTest extends TestCase
{
    private const BASE = '/api/v1/finance/ar';

    /** @var App<ContainerInterface> */
    private App $app;

    private RecordingReplyMailTransport $transport;

    private InMemoryArStore $store;

    protected function setUp(): void
    {
        $this->boot(['FINANCE_AR_SENDING_ENABLED' => 'true']);
    }

    /**
     * @param array<string, string> $env
     */
    private function boot(array $env): void
    {
        $this->app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AR_ENABLED' => 'true', 'FINANCE_AR_MAILBOX' => 'ar@example.test'] + $env);
        $container = $this->app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(FinanceMailboxRegistry::class, new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('m1', 'ar@example.test', 'Accounts Receivable', 'AR', true, ['AR' => ['read' => true, 'send' => true]]),
        ]));
        $this->transport = new RecordingReplyMailTransport();
        $container->set(FinanceMailTransport::class, $this->transport);
        $store = $container->get(InMemoryArStore::class);
        self::assertInstanceOf(InMemoryArStore::class, $store);
        $this->store = $store;
        $store->insert(ArFixtures::record('r1', sourceMessageId: 'graph-1', mailbox: 'ar@example.test', senderEmail: 'accounts@acme.example.test'));
    }

    /**
     * @param list<string> $permissions
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function call(string $method, string $path, array $permissions, ?array $body = null): array
    {
        $users = $this->app->getContainer()->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_ACCESS, ...$permissions], ['EMPLOYEE']);
        $request = TestAppFactory::request($method, self::BASE . $path);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        $response = $this->app->handle($request);

        return [$response->getStatusCode(), TestAppFactory::json($response)];
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private static function errorOf(array $json): array
    {
        self::assertFalse($json['success']);
        $error = $json['error'];
        self::assertIsArray($error);
        $typed = [];
        foreach ($error as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }

    private const READ = [Module::PERMISSION_AR_RECORDS_READ];
    private const WRITE = [Module::PERMISSION_AR_RECORDS_WRITE];

    public function test_the_mailboxes_and_the_history_need_read_and_the_send_needs_write(): void
    {
        self::assertSame(403, $this->call('GET', '/send-mailboxes', [])[0]);
        self::assertSame(403, $this->call('GET', '/records/r1/outbound-messages', [])[0]);
        self::assertSame(403, $this->call('POST', '/records/r1/send-response', self::READ, ['response_body' => 'Hi'])[0], 'a reviewer cannot send');
        self::assertSame([], $this->transport->replies);
    }

    public function test_send_mailboxes_answers_the_sources_shape_in_the_envelope(): void
    {
        [$status, $json] = $this->call('GET', '/send-mailboxes', self::READ);

        self::assertSame(200, $status);
        self::assertSame(['success' => true, 'data' => ['mailboxes' => [['email' => 'ar@example.test', 'label' => 'Accounts Receivable']], 'default' => 'ar@example.test']], $json);
    }

    public function test_a_send_answers_the_sources_shape_and_the_history_then_shows_it(): void
    {
        [$status, $json] = $this->call('POST', '/records/r1/send-response', self::WRITE, ['response_body' => 'Thanks, done.', 'cc' => 'boss@example.test']);

        self::assertSame(200, $status);
        $data = $json['data'];
        self::assertIsArray($data);
        self::assertSame([true, 'r1', true], [$data['success'], $data['record_id'], $data['threaded']]);
        self::assertCount(1, $this->transport->replies);
        self::assertSame('sent', $this->store->find('r1')?->status);

        [$status, $history] = $this->call('GET', '/records/r1/outbound-messages', self::READ);
        self::assertSame(200, $status);
        $rows = ArFixtures::rows($history['data']);
        self::assertCount(1, $rows);
        self::assertSame(['Re: Purchase order 77363', ['boss@example.test'], 'sent', 'Thanks, done.'], [$rows[0]['subject'], $rows[0]['cc_addresses'], $rows[0]['status'], $rows[0]['body']]);
    }

    public function test_the_refusals_are_typed_with_the_ar_codes_and_the_sources_words(): void
    {
        [$status, $json] = $this->call('POST', '/records/r1/send-response', self::WRITE, ['response_body' => 'Hi', 'to' => 'bad']);
        self::assertSame(422, $status);
        self::assertSame(['FINANCE_AR_INPUT_INVALID', 'Invalid email address: bad'], [self::errorOf($json)['code'], self::errorOf($json)['message']]);

        [$status, $json] = $this->call('POST', '/records/r1/send-response', self::WRITE, ['response_body' => 'Hi', 'from' => 'nobody@example.test']);
        self::assertSame([403, 'FINANCE_AR_FORBIDDEN'], [$status, self::errorOf($json)['code']]);

        [$status, $json] = $this->call('POST', '/records/ghost/send-response', self::WRITE, ['response_body' => 'Hi']);
        self::assertSame([404, 'FINANCE_AR_NOT_FOUND', 'Record not found'], [$status, self::errorOf($json)['code'], self::errorOf($json)['message']]);

        [$status] = $this->call('GET', '/records/ghost/outbound-messages', self::READ);
        self::assertSame(404, $status);
        self::assertSame([], $this->transport->replies);
    }

    public function test_a_failed_send_is_a_502_with_the_reason_and_the_record_is_not_marked_sent(): void
    {
        $this->transport->failWith = new FinanceMailTransportFailed('Microsoft Graph refused the message with status 500.');

        [$status, $json] = $this->call('POST', '/records/r1/send-response', self::WRITE, ['response_body' => 'Hi']);

        self::assertSame(502, $status);
        $error = self::errorOf($json);
        self::assertSame(['FINANCE_AR_UPSTREAM_FAILED', 'send', 'Failed to send email: Microsoft Graph refused the message with status 500.'], [$error['code'], $error['reason'], $error['message']]);
        self::assertSame('new', $this->store->find('r1')?->status);
    }

    public function test_with_the_default_mail_off_transport_a_send_is_refused_as_not_sent(): void
    {
        $this->app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AR_ENABLED' => 'true', 'FINANCE_AR_SENDING_ENABLED' => 'true', 'FINANCE_AR_MAILBOX' => 'ar@example.test']);
        $container = $this->app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(FinanceMailboxRegistry::class, new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('m1', 'ar@example.test', 'Accounts Receivable', 'AR', true, ['AR' => ['read' => true, 'send' => true]]),
        ]));
        $store = $container->get(InMemoryArStore::class);
        self::assertInstanceOf(InMemoryArStore::class, $store);
        $store->insert(ArFixtures::record('r1'));

        [$status, $json] = $this->call('POST', '/records/r1/send-response', self::WRITE, ['response_body' => 'Hi']);

        self::assertSame(502, $status);
        self::assertSame('Failed to send email: Mail is switched off', self::errorOf($json)['message']);
        self::assertSame('new', $store->find('r1')?->status);
    }

    public function test_with_the_sending_switch_off_the_send_is_a_503_and_me_says_so(): void
    {
        $this->boot([]);

        [$status, $json] = $this->call('POST', '/records/r1/send-response', self::WRITE, ['response_body' => 'Hi']);
        self::assertSame(503, $status);
        self::assertSame(['FINANCE_AR_UNAVAILABLE', 'Sending is switched off'], [self::errorOf($json)['code'], self::errorOf($json)['message']]);
        self::assertSame([], $this->transport->replies);

        [, $me] = $this->call('GET', '/me', [Module::PERMISSION_AR_ACCESS]);
        $data = $me['data'];
        self::assertIsArray($data);
        self::assertFalse($data['sending_enabled']);
        self::assertSame(200, $this->call('GET', '/send-mailboxes', self::READ)[0], 'reads still work');

        $this->boot(['FINANCE_AR_SENDING_ENABLED' => 'true']);
        [, $me] = $this->call('GET', '/me', [Module::PERMISSION_AR_ACCESS]);
        $data = $me['data'];
        self::assertIsArray($data);
        self::assertTrue($data['sending_enabled']);
    }
}
