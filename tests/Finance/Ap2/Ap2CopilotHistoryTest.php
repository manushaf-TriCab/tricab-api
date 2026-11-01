<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2;

use DateTimeImmutable;
use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Finance\Ap2\InMemoryAp2CopilotHistoryStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2MailStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2SupplierStore;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Shared\Mailbox\InMemoryMailboxReader;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Ap2\Ai\Ap2AiUnavailable;
use TriCab\Core\Finance\Ap2\Ai\Ap2CopilotProposal;
use TriCab\Core\Finance\Ap2\Ai\Ap2CopilotReply;
use TriCab\Core\Finance\Ap2\Contract\Ap2Copilot;
use TriCab\Core\Finance\Ap2\Contract\Ap2CopilotHistoryStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2MailStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Actor;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invalid;
use TriCab\Core\Finance\Ap2\Service\Ap2CopilotService;
use TriCab\Core\Finance\Ap2\Service\Ap2MailSyncService;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mail\FinanceMailboxRegistry;
use TriCab\Core\Finance\Shared\Mailbox\FinanceMailboxReader;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxMessageSummary;
use TriCab\Core\Shared\Mailbox\MailboxReader;
use TriCab\Core\Shared\Mailbox\MailboxRecipient;

/** Tri-AP Copilot conversations: stored per person and email (or supplier), read back, capped at 30 messages. */
final class Ap2CopilotHistoryTest extends TestCase
{
    private const BASE = '/api/v1/finance/ap2';

    private const MAILBOX = 'ap@example.test';

    /** @var App<ContainerInterface> */
    private App $app;

    private Container $c;

    /** @var list<array{context: array<string, string>, message: string, history: list<array{role: string, content: string}>}> */
    private array $seen = [];

    private bool $fails = false;

    private string $mailId = '';

    private string $supplierId = '';

    protected function setUp(): void
    {
        $this->app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AP2_ENABLED' => 'true', 'FINANCE_AP_MAILBOX' => self::MAILBOX]);
        $c = $this->app->getContainer();
        self::assertInstanceOf(Container::class, $c);
        $this->c = $c;
        $reader = new InMemoryMailboxReader();
        $this->c->set(MailboxReader::class, $reader);
        $this->c->set(FinanceMailboxRegistry::class, new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('m1', self::MAILBOX, 'Accounts Payable', 'AP', true, ['AP2' => ['read' => true, 'send' => true], 'AP' => ['read' => true, 'send' => true]]),
        ]));
        $users = $this->c->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_ACCESS, Module::PERMISSION_AP2_ACCESS, ...Module::AP2_PERMISSIONS], ['EMPLOYEE']);

        $supplier = Ap2Fixtures::supplier('S001', 'Acme Cables')->with(email: 'billing@acme.test');
        $suppliers = $this->c->get(Ap2SupplierStore::class);
        self::assertInstanceOf(InMemoryAp2SupplierStore::class, $suppliers);
        $suppliers->insert($supplier);
        $this->supplierId = $supplier->id;

        $summary = new MailboxMessageSummary('g-1', 'Where is my money', 'billing@acme.test', 'Sender', new DateTimeImmutable('-2 hours', new \DateTimeZone('UTC')), false, 'Hello');
        $reader->add(self::MAILBOX, new MailboxMessage($summary, MailboxMessage::BODY_TEXT, 'Hello', [new MailboxRecipient(self::MAILBOX)], []), []);
        $sync = $this->c->get(Ap2MailSyncService::class);
        self::assertInstanceOf(Ap2MailSyncService::class, $sync);
        $settings = $this->c->get(\TriCab\Core\Finance\Ap2\Contract\Ap2SettingsStore::class);
        self::assertInstanceOf(\TriCab\Core\Finance\Ap2\Contract\Ap2SettingsStore::class, $settings);
        $settings->save($settings->get()->with(mailCutoffDate: (new DateTimeImmutable('-5 days', new \DateTimeZone('UTC')))->format('Y-m-d')), 1);
        $sync->sync(Ap2Fixtures::actor());
        $mails = $this->c->get(Ap2MailStore::class);
        self::assertInstanceOf(InMemoryAp2MailStore::class, $mails);
        $all = $mails->all();
        self::assertCount(1, $all);
        $this->mailId = $all[0]->id;

        $test = $this;
        $this->c->set(Ap2Copilot::class, new class ($test) implements Ap2Copilot {
            public function __construct(private readonly Ap2CopilotHistoryTest $test)
            {
            }

            public function respond(array $context, string $message, array $history): Ap2CopilotReply
            {
                return $this->test->answer($context, $message, $history);
            }
        });
    }

    /**
     * @param array<string, string> $context
     * @param list<array{role: string, content: string}> $history
     */
    public function answer(array $context, string $message, array $history): Ap2CopilotReply
    {
        $this->seen[] = ['context' => $context, 'message' => $message, 'history' => $history];
        if ($this->fails) {
            throw new Ap2AiUnavailable('down');
        }

        return new Ap2CopilotReply('Re: ' . $message, [
            new Ap2CopilotProposal('apply_draft', 'Use this draft', ['draft' => 'Hi']),
            new Ap2CopilotProposal('delete_everything', 'Nope', []),
        ]);
    }

    private function service(): Ap2CopilotService
    {
        $service = $this->c->get(Ap2CopilotService::class);
        self::assertInstanceOf(Ap2CopilotService::class, $service);

        return $service;
    }

    private function store(): InMemoryAp2CopilotHistoryStore
    {
        $store = $this->c->get(Ap2CopilotHistoryStore::class);
        self::assertInstanceOf(InMemoryAp2CopilotHistoryStore::class, $store);

        return $store;
    }

    private function actor(int $id = 7): Ap2Actor
    {
        return new Ap2Actor($id, 'Person ' . $id, 'p' . $id . '@tricab.com', [Module::PERMISSION_AP2_ACCESS, ...Module::AP2_PERMISSIONS]);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function get(string $query): array
    {
        $response = $this->app->handle(TestAppFactory::request('GET', self::BASE . '/copilot/history' . $query));

        return [$response->getStatusCode(), TestAppFactory::json($response)];
    }

    public function test_stored_history_is_used_when_none_is_sent_and_a_sent_one_wins(): void
    {
        $s = $this->service();
        $a = $this->actor();
        $s->respond($a, ['mailId' => $this->mailId, 'message' => 'first']);
        self::assertSame([], $this->seen[0]['history']);

        $s->respond($a, ['mailId' => $this->mailId, 'message' => 'second', 'history' => []]);
        self::assertSame(
            [['role' => 'user', 'content' => 'first'], ['role' => 'assistant', 'content' => 'Re: first']],
            $this->seen[1]['history'],
        );

        $s->respond($a, ['mailId' => $this->mailId, 'message' => 'third', 'history' => [['role' => 'user', 'content' => 'sent']]]);
        self::assertSame([['role' => 'user', 'content' => 'sent']], $this->seen[2]['history']);

        $stored = $this->store()->recent(7, $this->mailId, null, 30);
        self::assertCount(6, $stored);
        self::assertSame('first', $stored[0]->content);
        self::assertSame('assistant', $stored[1]->role);
        self::assertSame([['type' => 'apply_draft', 'label' => 'Use this draft', 'payload' => ['draft' => 'Hi']]], $stored[1]->proposals);
        self::assertSame([], $stored[0]->proposals);
    }

    public function test_a_conversation_keeps_only_its_newest_30_messages(): void
    {
        $s = $this->service();
        $a = $this->actor();
        for ($i = 1; $i <= 17; $i++) {
            $s->respond($a, ['supplierId' => $this->supplierId, 'message' => 'q' . $i]);
        }
        $stored = $this->store()->recent(7, null, $this->supplierId, 100);
        self::assertCount(30, $stored);
        self::assertSame('q3', $stored[0]->content, '34 messages were written; the oldest 4 are gone');
        self::assertSame('Re: q17', $stored[29]->content);
        self::assertCount(Ap2CopilotService::MAX_HISTORY, $this->seen[16]['history']);
    }

    public function test_people_do_not_see_each_others_conversations(): void
    {
        $s = $this->service();
        $s->respond($this->actor(7), ['mailId' => $this->mailId, 'message' => 'mine']);
        $s->respond($this->actor(8), ['mailId' => $this->mailId, 'message' => 'theirs']);

        self::assertSame([], $this->seen[1]['history'], 'the second person starts fresh');
        self::assertCount(2, $this->store()->recent(7, $this->mailId, null, 30));
        self::assertSame('mine', $this->store()->recent(7, $this->mailId, null, 30)[0]->content);
        self::assertSame('theirs', $this->store()->recent(8, $this->mailId, null, 30)[0]->content);
        self::assertCount(2, $s->history($this->actor(7), ['mailId' => $this->mailId])['messages']);
    }

    public function test_the_mail_wins_over_the_supplier_and_a_supplier_alone_has_its_own_conversation(): void
    {
        $s = $this->service();
        $a = $this->actor();
        $s->respond($a, ['mailId' => $this->mailId, 'supplierId' => $this->supplierId, 'message' => 'on the mail']);
        $s->respond($a, ['supplierId' => $this->supplierId, 'message' => 'on the supplier']);

        self::assertSame([], $this->seen[1]['history'], 'the supplier conversation does not see the mail conversation');
        self::assertCount(2, $this->store()->recent(7, $this->mailId, $this->supplierId, 30));
        self::assertCount(2, $this->store()->recent(7, null, $this->supplierId, 30));
        self::assertSame('on the supplier', $this->store()->recent(7, null, $this->supplierId, 30)[0]->content);

        $s->respond($a, ['mailId' => $this->mailId, 'message' => 'again']);
        self::assertCount(2, $this->seen[2]['history']);
    }

    public function test_nothing_is_stored_without_a_mail_or_supplier_or_when_the_copilot_fails(): void
    {
        $s = $this->service();
        $a = $this->actor();
        $s->respond($a, ['message' => 'general']);
        self::assertSame([], $this->store()->recent(7, null, null, 30));

        $this->fails = true;
        try {
            $s->respond($a, ['mailId' => $this->mailId, 'message' => 'boom']);
            self::fail('The Copilot failure should pass through');
        } catch (Ap2AiUnavailable) {
            // expected
        }
        self::assertSame([], $this->store()->recent(7, $this->mailId, null, 30));
    }

    public function test_a_store_that_throws_does_not_cost_the_reply(): void
    {
        $this->c->set(Ap2CopilotHistoryStore::class, new class implements Ap2CopilotHistoryStore {
            public function append(int $userId, ?string $mailId, ?string $supplierId, string $role, string $message, ?array $proposals = null): void
            {
                throw new \RuntimeException('db down');
            }

            public function recent(int $userId, ?string $mailId, ?string $supplierId, int $limit): array
            {
                throw new \RuntimeException('db down');
            }

            public function prune(int $userId, ?string $mailId, ?string $supplierId, int $keep): void
            {
            }
        });
        $log = tempnam(sys_get_temp_dir(), 'cpl');
        self::assertNotFalse($log);
        $before = ini_set('error_log', $log);
        try {
            $answer = $this->service()->respond($this->actor(), ['mailId' => $this->mailId, 'message' => 'hi']);
        } finally {
            ini_set('error_log', (string) $before);
        }
        self::assertSame('Re: hi', $answer['reply']);
        self::assertStringContainsString('could not be stored', (string) file_get_contents($log));
        unlink($log);
    }

    public function test_the_history_route_returns_only_the_callers_messages_oldest_first(): void
    {
        $s = $this->service();
        $s->respond($this->actor(1), ['mailId' => $this->mailId, 'message' => 'hello']);
        $s->respond($this->actor(2), ['mailId' => $this->mailId, 'message' => 'someone else']);

        [$status, $json] = $this->get('?mailId=' . $this->mailId);
        self::assertSame(200, $status, json_encode($json, JSON_THROW_ON_ERROR));
        $messages = Ap2Json::arr($json, 'data.messages');
        self::assertCount(2, $messages);
        self::assertSame('user', Ap2Json::get($json, 'data.messages.0.role'));
        self::assertSame('hello', Ap2Json::get($json, 'data.messages.0.content'));
        self::assertSame([], Ap2Json::get($json, 'data.messages.0.proposals'));
        self::assertSame('assistant', Ap2Json::get($json, 'data.messages.1.role'));
        self::assertSame('apply_draft', Ap2Json::get($json, 'data.messages.1.proposals.0.type'));
        $id = Ap2Json::get($json, 'data.messages.0.id');
        $createdAt = Ap2Json::get($json, 'data.messages.0.createdAt');
        self::assertIsString($id);
        self::assertIsString($createdAt);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        self::assertNotFalse(DateTimeImmutable::createFromFormat(DATE_ATOM, $createdAt));

        [, $bySupplier] = $this->get('?supplierId=' . $this->supplierId);
        self::assertSame([], Ap2Json::arr($bySupplier, 'data.messages'));
    }

    public function test_the_history_route_wants_exactly_one_existing_id(): void
    {
        self::assertSame(422, $this->get('')[0]);
        self::assertSame(422, $this->get('?mailId=' . $this->mailId . '&supplierId=' . $this->supplierId)[0]);
        self::assertSame(422, $this->get('?mailId=nope')[0]);
        self::assertSame(422, $this->get('?mailId=' . md5('missing'))[0]);
        self::assertSame(422, $this->get('?supplierId=' . md5('missing'))[0]);
        $this->expectException(Ap2Invalid::class);
        $this->service()->history($this->actor(), []);
    }
}
