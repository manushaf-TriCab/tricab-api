<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Quotegen;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Assistant\Infrastructure\Foundry\FoundryAccessTokenProvider;
use TriCab\Api\Assistant\Infrastructure\Foundry\FoundryStreamClient;
use TriCab\Api\Quotegen\Application\QuotegenChatService;
use TriCab\Api\Quotegen\Application\QuotegenQuoteGateway;
use TriCab\Api\Quotegen\Application\QuotegenTools;
use TriCab\Api\Quotegen\Application\QuotegenTurnContext;
use TriCab\Api\Quotegen\Infrastructure\Foundry\QuotegenAgentLoop;
use TriCab\Api\Quotegen\Infrastructure\Persistence\InMemoryQuotegenBudgetLedger;
use TriCab\Api\Quotegen\Infrastructure\Persistence\InMemoryQuotegenConversationRepository;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Module;

abstract class QuotegenTestCase extends TestCase
{
    public const RFQ = '11111111-1111-4111-8111-111111111111';

    protected FakeQuoteGateway $gateway;

    protected FakeFoundryStream $stream;

    protected InMemoryQuotegenConversationRepository $conversations;

    protected InMemoryQuotegenBudgetLedger $ledger;

    /** @param list<list<array{string, array<string, mixed>}>> $rounds */
    protected function service(array $rounds, int $limitMicros = 5_000_000, int $maxRounds = 8): QuotegenChatService
    {
        $this->gateway = new FakeQuoteGateway();
        $this->conversations = new InMemoryQuotegenConversationRepository();
        $this->ledger = new InMemoryQuotegenBudgetLedger();
        $this->stream = new FakeFoundryStream($rounds);
        $agent = new QuotegenAgentLoop(
            stream: $this->stream,
            accessTokens: new FakeTokens(),
            tools: new QuotegenTools($this->gateway, $this->conversations),
            ledger: $this->ledger,
            endpoint: 'https://example.invalid',
            deployment: 'test-deployment',
            authMode: 'api-key',
            apiKey: 'k',
            systemPrompt: 'system',
            maxRounds: $maxRounds,
            turnDeadlineSeconds: 90,
            inputUsdPerMtok: 3.0,
            outputUsdPerMtok: 15.0,
            budgetLimitMicros: $limitMicros,
            maxOutputTokens: 1000,
        );

        return new QuotegenChatService($this->conversations, $this->ledger, $agent, $this->gateway, $limitMicros);
    }

    /** @param list<string> $extra */
    protected static function actor(array $extra = [], bool $pricing = true): RfqActor
    {
        $permissions = [Module::PERMISSION_ACCESS, Module::PERMISSION_DRAFT_READ, Module::PERMISSION_DRAFT_EDIT, ...$extra];
        if ($pricing) {
            $permissions[] = Module::PERMISSION_PRICING_VIEW;
        }

        return new RfqActor(7, 'user-public-7', $permissions, null);
    }

    /**
     * @return list<array{string, array<string, mixed>}>
     */
    protected function turn(QuotegenChatService $service, RfqActor $actor, mixed $message = 'hello', mixed $revision = 12): array
    {
        $events = [];
        $service->turn($actor, 'rep@tricab.com', 'Rep Person', self::RFQ, $message, $revision, static function (string $e, array $d) use (&$events): void {
            $events[] = [$e, $d];
        });

        return $events;
    }

    /**
     * @param list<array{string, array<string, mixed>}> $events
     *
     * @return array{string, array<string, mixed>}
     */
    protected static function last(array $events): array
    {
        $last = end($events);
        self::assertIsArray($last);

        return $last;
    }

    /** @return array<string, mixed> */
    protected static function arr(mixed $value): array
    {
        self::assertIsArray($value);
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> the tool definitions of the first model call */
    protected function toolDefinitions(): array
    {
        return array_values(array_map(self::arr(...), self::arr($this->stream->payloads[0]['tools'] ?? null)));
    }

    /**
     * @param list<array{string, array<string, mixed>}> $events
     *
     * @return list<string>
     */
    protected static function names(array $events): array
    {
        return array_map(static fn (array $e): string => $e[0], $events);
    }
}

/** Replies with scripted Messages API streams and keeps every request it was sent. */
final class FakeFoundryStream implements FoundryStreamClient
{
    /** @var list<array<string, mixed>> */
    public array $payloads = [];

    /** @param list<list<array{string, array<string, mixed>}>> $rounds one list of events per call */
    public function __construct(private array $rounds)
    {
    }

    public function stream(string $url, array $headers, array $payload, callable $onEvent): array
    {
        $this->payloads[] = $payload;
        $round = array_shift($this->rounds) ?? throw new \RuntimeException('Unexpected model call.');
        foreach ($round as [$event, $data]) {
            $onEvent($event, $data);
        }

        return ['status' => 200, 'body' => ''];
    }

    /** @return list<array{string, array<string, mixed>}> */
    public static function text(string $text, int $in = 1000, int $out = 100): array
    {
        return [
            ['message_start', ['message' => ['usage' => ['input_tokens' => $in, 'output_tokens' => 1]]]],
            ['content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
            ['content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $text]]],
            ['content_block_stop', ['index' => 0]],
            ['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => $out]]],
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<array{string, array<string, mixed>}>
     */
    public static function toolUse(string $id, string $name, array $input, int $in = 1000, int $out = 100): array
    {
        return [
            ['message_start', ['message' => ['usage' => ['input_tokens' => $in, 'output_tokens' => 1]]]],
            ['content_block_start', ['index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => []]]],
            ['content_block_delta', ['index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => json_encode($input === [] ? new \stdClass() : $input)]]],
            ['content_block_stop', ['index' => 0]],
            ['message_delta', ['delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => $out]]],
        ];
    }
}

final class FakeTokens implements FoundryAccessTokenProvider
{
    public function accessToken(): string
    {
        return 'token';
    }
}

/** An RFQ at revision 12 with two lines. No sync method exists on the interface; `calls` proves what ran. */
final class FakeQuoteGateway implements QuotegenQuoteGateway
{
    /** @var list<string> */
    public array $calls = [];

    public int $revision = 12;

    /** Set to make the next save fail as if someone saved first. */
    public bool $conflictOnSave = false;

    public ?string $extractionState = 'complete';

    public function snapshot(QuotegenTurnContext $context): ?array
    {
        $this->calls[] = 'snapshot';
        if ($context->rfqPublicId !== QuotegenTestCase::RFQ) {
            return null;
        }
        $line = static fn (string $uid, string $qty): array => ['lineUid' => $uid, 'quantity' => $qty, 'pricing' => null];
        $lines = [$line('l1', '100'), $line('l2', '50')];
        if ($context->mayViewPricing()) {
            foreach ($lines as &$l) {
                $l['pricing'] = ['unitCost' => '1.20', 'marginPercent' => 30, 'unitSell' => '1.71'];
            }
            unset($l);
        }

        return ['revision' => $this->revision, 'rfq' => ['publicId' => $context->rfqPublicId, 'extractionState' => $this->extractionState, 'latestRevision' => ['revision' => $this->revision, 'lines' => $lines]]];
    }

    public function readiness(QuotegenTurnContext $context): array
    {
        $this->calls[] = 'readiness';

        return ['ready' => true, 'blockingIssues' => [], 'lines' => [], 'pricing' => $context->mayViewPricing() ? ['total' => '171.00'] : null];
    }

    public function searchProducts(string $query): array
    {
        $this->calls[] = 'searchProducts';

        return [['code' => 'TPS2C1.5', 'style' => 'TPS', 'description' => 'Flat TPS']];
    }

    public function findCustomers(string $query): array
    {
        $this->calls[] = 'findCustomers';

        return [];
    }

    public function applyEdits(QuotegenTurnContext $context, array $edits): array
    {
        $this->calls[] = 'applyEdits';
        if ($this->conflictOnSave) {
            throw new RfqRevisionConflict($this->revision + 1, $context->revision);
        }
        $this->revision++;
        $uids = [];
        foreach (is_array($edits['lines'] ?? null) ? $edits['lines'] : [] as $edit) {
            if (is_array($edit) && is_string($edit['lineUid'] ?? null)) {
                $uids[] = $edit['lineUid'];
            }
        }

        return ['revision' => $this->revision, 'changedLineIds' => $uids];
    }
}
