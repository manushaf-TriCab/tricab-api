<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use TriCab\Api\Finance\Ar\InMemoryArRateLimiter;
use TriCab\Api\Finance\Ar\InMemoryArStore;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\Finance\Mail\RecordingReplyMailTransport;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Ar\Contract\ArRateLimiter;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mail\FinanceMailboxRegistry;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransport;
use TriCab\Core\Identity\Contract\UserRepository;

/**
 * The credit-note routes (T5.10) through the real app on the in-memory stores and a transport that sends nothing: the
 * envelope, statuses and codes, the gates, the link shown once, and the two public routes working with no sign-in
 * and no permission, over a full flow from create to the rep's submit.
 */
final class FinanceArCreditNoteRoutesTest extends TestCase
{
    private const BASE = '/api/v1/finance/ar';
    private const READ = [Module::PERMISSION_AR_RECORDS_READ];
    private const WRITE = [Module::PERMISSION_AR_RECORDS_WRITE];

    /** @var App<ContainerInterface> */
    private App $app;

    private RecordingReplyMailTransport $transport;

    private InMemoryArStore $store;

    protected function setUp(): void
    {
        $this->boot([]);
    }

    /**
     * @param array<string, string> $env
     */
    private function boot(array $env): void
    {
        $this->app = TestAppFactory::create($env + [
            'FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AR_ENABLED' => 'true', 'FINANCE_AR_SENDING_ENABLED' => 'true',
            'FINANCE_AR_PUBLIC_ORIGIN' => 'https://tricab.example.test', 'FINANCE_AR_MAILBOX' => 'ar@example.test',
        ]);
        $container = $this->container();
        $container->set(FinanceMailboxRegistry::class, new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('m1', 'ar@example.test', 'Accounts Receivable', 'AR', true, ['AR' => ['read' => true, 'send' => true]]),
        ]));
        $this->transport = new RecordingReplyMailTransport();
        $container->set(FinanceMailTransport::class, $this->transport);
        $container->set(ArRateLimiter::class, new InMemoryArRateLimiter());
        $store = $container->get(InMemoryArStore::class);
        self::assertInstanceOf(InMemoryArStore::class, $store);
        $this->store = $store;
        $store->insert(ArFixtures::record('r1'));
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

        return $this->raw($method, $path, $body);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function raw(string $method, string $path, ?array $body = null): array
    {
        $request = TestAppFactory::request($method, self::BASE . $path);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        $response = $this->app->handle($request);

        return [$response->getStatusCode(), $this->decode($response)];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        return TestAppFactory::json($response);
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private static function data(array $json): array
    {
        self::assertTrue($json['success'], json_encode($json, JSON_THROW_ON_ERROR));
        $data = $json['data'];
        self::assertIsArray($data);
        $typed = [];
        foreach ($data as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private static function error(array $json): array
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

    /**
     * Creates a request through the route and reads the link it shows.
     *
     * @return array{data: array<string, mixed>, id: string, token: string, url: string}
     */
    private function created(): array
    {
        [$status, $json] = $this->call('POST', '/credit-notes', self::WRITE, [
            'customer_name' => 'Acme Marine', 'sales_rep_email' => 'rep@example.test', 'sales_rep_name' => 'Riley', 'email_record_id' => 'r1', 'original_request' => 'Please credit INV-1',
        ]);
        self::assertSame(200, $status, json_encode($json, JSON_THROW_ON_ERROR));
        $data = self::data($json);
        $url = $data['form_url'];
        self::assertIsString($url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $token = $query['token'] ?? '';
        $id = $query['id'] ?? '';
        self::assertIsString($token);
        self::assertIsString($id);

        return ['data' => $data, 'id' => $id, 'token' => $token, 'url' => $url];
    }

    /**
     * The `id` and `token` of a form link in the answer to a create or resend.
     *
     * @param array<string, mixed> $data
     * @return array{id: string, token: string}
     */
    private static function linkOf(array $data): array
    {
        $url = $data['form_url'];
        self::assertIsString($url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $id = $query['id'] ?? '';
        $token = $query['token'] ?? '';
        self::assertIsString($id);
        self::assertIsString($token);

        return ['id' => $id, 'token' => $token];
    }

    private function container(): Container
    {
        $container = $this->app->getContainer();
        self::assertInstanceOf(Container::class, $container);

        return $container;
    }

    public function test_the_gates_read_for_the_lists_and_write_for_the_changes(): void
    {
        self::assertSame(403, $this->call('GET', '/credit-notes', [])[0]);
        self::assertSame(403, $this->call('GET', '/credit-notes/x', [])[0]);
        foreach ([['POST', '/credit-notes'], ['PATCH', '/credit-notes/x'], ['POST', '/credit-notes/x/resend']] as [$method, $path]) {
            self::assertSame(403, $this->call($method, $path, self::READ, ['customer_name' => 'A', 'sales_rep_email' => 'r@example.test'])[0], $method . ' ' . $path);
        }
        self::assertSame([], $this->transport->standalone);
    }

    public function test_create_answers_the_link_once_and_no_other_answer_carries_a_token(): void
    {
        $created = $this->created();
        $data = $created['data'];

        self::assertSame([true, true, null], [$data['success'], $data['mail_sent'], $data['mail_error']]);
        self::assertStringStartsWith('https://tricab.example.test/finance/ar/credit-note-form?id=', $created['url']);
        self::assertGreaterThanOrEqual(40, strlen($created['token']));

        foreach ([['GET', '/credit-notes'], ['GET', '/credit-notes/' . $created['id']], ['PATCH', '/credit-notes/' . $created['id']]] as [$method, $path]) {
            [, $json] = $this->call($method, $path, [...self::READ, ...self::WRITE], $method === 'PATCH' ? ['status' => 'under_review'] : null);
            self::assertStringNotContainsString($created['token'], json_encode($json, JSON_THROW_ON_ERROR), $method . ' ' . $path);
        }
        self::assertCount(1, $this->transport->standalone);
        self::assertStringContainsString($created['token'], $this->transport->standalone[0]->body);
    }

    public function test_the_sources_refusals_are_typed_with_the_ar_codes(): void
    {
        [$status, $json] = $this->call('POST', '/credit-notes', self::WRITE, ['customer_name' => 'A']);
        self::assertSame([422, 'FINANCE_AR_INPUT_INVALID', 'customer_name and sales_rep_email are required'], [$status, self::error($json)['code'], self::error($json)['message']]);

        [$status, $json] = $this->call('GET', '/credit-notes/ghost', self::READ);
        self::assertSame([404, 'FINANCE_AR_NOT_FOUND', 'Credit note request not found'], [$status, self::error($json)['code'], self::error($json)['message']]);

        $created = $this->created();
        [$status, $json] = $this->call('PATCH', '/credit-notes/' . $created['id'], self::WRITE, ['status' => 'overdue']);
        self::assertSame([422, 'Invalid status: overdue'], [$status, self::error($json)['message']]);

        [$status] = $this->call('GET', '/credit-notes?limit=abc', self::READ);
        self::assertSame(422, $status);
    }

    public function test_the_full_flow_with_the_two_public_routes_used_with_no_permission(): void
    {
        $created = $this->created();
        $link = ['id' => $created['id'], 'token' => $created['token']];

        // The rep has no account: no permission at all, and the form still opens and submits.
        [$status, $json] = $this->call('GET', '/public/credit-note-form?id=' . $link['id'] . '&token=' . $link['token'], []);
        self::assertSame(200, $status, json_encode($json, JSON_THROW_ON_ERROR));
        self::assertSame(['Acme Marine', 'form_sent'], [self::data($json)['customer_name'], self::data($json)['status']]);

        [$status, $json] = $this->call('POST', '/public/credit-note-form', [], $link + ['invoice_number' => 'INV-77', 'credit_amount' => 42.5, 'reason' => 'Damaged', 'sales_rep_name' => 'Riley R']);
        self::assertSame(200, $status, json_encode($json, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($link['token'], json_encode($json, JSON_THROW_ON_ERROR));
        self::assertSame('Credit note form submitted by Riley R for invoice INV-77 ($42.50)', $this->store->find('r1')?->finalOutcome);

        [$status, $json] = $this->call('POST', '/public/credit-note-form', [], $link + ['invoice_number' => 'AGAIN', 'credit_amount' => 1]);
        self::assertSame([409, 'This form has already been submitted'], [$status, self::error($json)['message']]);
        self::assertCount(2, $this->transport->standalone, 'the link to the rep, then the notice to the team');
        self::assertSame(['ar@example.test'], $this->transport->standalone[1]->to);
    }

    public function test_the_public_routes_answer_with_their_own_codes_for_each_refusal(): void
    {
        $created = $this->created();
        $link = ['id' => $created['id'], 'token' => $created['token']];

        [$status, $json] = $this->raw('GET', '/public/credit-note-form');
        self::assertSame([422, 'Missing form ID or security token'], [$status, self::error($json)['message']]);

        [$status, $json] = $this->raw('GET', '/public/credit-note-form?id=' . $link['id'] . '&token=wrong');
        self::assertSame([404, 'FINANCE_AR_NOT_FOUND', 'Invalid form link'], [$status, self::error($json)['code'], self::error($json)['message']]);

        [$status, $json] = $this->raw('POST', '/public/credit-note-form', $link + ['credit_amount' => 'lots']);
        self::assertSame([422, 'credit_amount must be a number'], [$status, self::error($json)['message']]);
    }

    public function test_the_public_form_is_rate_limited_with_a_429(): void
    {
        $this->container()->set(ArRateLimiter::class, new class() implements ArRateLimiter {
            public function allow(string $key, int $limit, int $windowSeconds): bool
            {
                return false;
            }
        });

        [$status, $json] = $this->raw('GET', '/public/credit-note-form?id=x&token=y');

        self::assertSame([429, 'FINANCE_AR_RATE_LIMITED', 'Too many attempts. Try again later.'], [$status, self::error($json)['code'], self::error($json)['message']]);
    }

    public function test_resend_through_the_route_shows_a_new_link_and_the_old_one_stops_working(): void
    {
        $created = $this->created();

        [$status, $json] = $this->call('POST', '/credit-notes/' . $created['id'] . '/resend', self::WRITE);

        self::assertSame(200, $status);
        $new = self::linkOf(self::data($json));
        self::assertNotSame($created['token'], $new['token']);
        [$status] = $this->raw('GET', '/public/credit-note-form?id=' . $created['id'] . '&token=' . $created['token']);
        self::assertSame(404, $status, 'the old link is dead');
        [$status] = $this->raw('GET', '/public/credit-note-form?id=' . $new['id'] . '&token=' . $new['token']);
        self::assertSame(200, $status);
    }

    public function test_with_sending_off_or_no_origin_create_is_a_503(): void
    {
        foreach ([['FINANCE_AR_SENDING_ENABLED' => 'false'], ['FINANCE_AR_PUBLIC_ORIGIN' => '']] as $env) {
            $this->boot($env);
            [$status, $json] = $this->call('POST', '/credit-notes', self::WRITE, ['customer_name' => 'A', 'sales_rep_email' => 'r@example.test']);
            self::assertSame([503, 'FINANCE_AR_UNAVAILABLE'], [$status, self::error($json)['code']]);
            self::assertSame(200, $this->call('GET', '/credit-notes', self::READ)[0], 'reads still work');
        }
        self::assertSame([], $this->transport->standalone);
    }

    public function test_with_no_sign_in_at_all_the_public_routes_answer_and_every_other_route_is_a_401(): void
    {
        $this->boot(['DEV_AUTH_ENABLED' => 'false']);
        $parts = ['id' => 'x', 'token' => 'y'];

        [$status] = $this->raw('GET', '/public/credit-note-form?id=x&token=y');
        self::assertSame(404, $status, 'reached the form logic: an invalid link, not a refusal to sign in');
        [$status] = $this->raw('POST', '/public/credit-note-form', $parts);
        self::assertSame(404, $status);

        foreach ([['GET', '/credit-notes'], ['POST', '/credit-notes'], ['GET', '/records'], ['POST', '/records/r1/send-response']] as [$method, $path]) {
            self::assertSame(401, $this->raw($method, $path, $method === 'POST' ? [] : null)[0], $method . ' ' . $path . ' needs sign-in');
        }
    }

    public function test_a_bad_public_origin_is_refused_at_startup(): void
    {
        foreach (['tricab.ai', 'http://tricab.ai', 'https://tricab.ai/finance', 'https://user:pw@tricab.ai', 'ftp://tricab.ai', 'https://tricab.ai?x=1'] as $origin) {
            try {
                TestAppFactory::create(['FINANCE_AR_PUBLIC_ORIGIN' => $origin]);
                self::fail('Expected ' . $origin . ' to be refused.');
            } catch (\InvalidArgumentException $refused) {
                self::assertStringContainsString('FINANCE_AR_PUBLIC_ORIGIN', $refused->getMessage());
            }
        }
        TestAppFactory::create(['FINANCE_AR_PUBLIC_ORIGIN' => 'http://localhost:5173']);
        TestAppFactory::create(['FINANCE_AR_PUBLIC_ORIGIN' => 'https://tricab.ai/']);
        $this->addToAssertionCount(1);
    }
}
