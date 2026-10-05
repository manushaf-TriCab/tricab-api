<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Rfq\Contract\RfqRepository;
use TriCab\Core\Rfq\Infrastructure\InMemoryRfqRepository;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Tests\Rfq\Application\ReadRfqQueueServiceTest;

/**
 * Plan 1.5 exit: denied users cannot reach RFQ data even by calling the
 * endpoints directly. Everyone who reads RFQs reads all of them, every
 * employee reads (owner decision, 28 Sep 2026), and branch is a filter.
 *
 * Written against permissions no database holds yet: the in-memory user
 * repository is the authority here, exactly as in `OnboardingEndpointsTest`.
 */
final class RfqEndpointsTest extends TestCase
{
    private const ME = '/api/v1/rfqs/me';
    private const QUEUE = '/api/v1/rfqs';
    private const PARSE = '/api/v1/rfqs/parse';

    /** The id InMemoryApiUserRepository authenticates as. */
    private const SIGNED_IN_USER_ID = 1;

    private const OWN_RFQ = '11111111-1111-4111-8111-111111111111';
    private const COLLEAGUE_RFQ = '22222222-2222-4222-8222-222222222222';

    // ------------------------------------------------------------ deny by default

    public function test_a_signed_in_person_without_rfq_permissions_reaches_nothing(): void
    {
        $app = $this->app(['account.login']);

        foreach ([self::ME, self::QUEUE, self::QUEUE . '/' . self::OWN_RFQ] as $path) {
            $response = $app->handle(TestAppFactory::request('GET', $path));
            self::assertSame(403, $response->getStatusCode(), $path . ': ' . $response->getBody());
        }
    }

    /** RFQ.ACCESS reaches the module entry and no record. */
    public function test_module_access_alone_reads_no_rfq(): void
    {
        $app = $this->app(['account.login', Module::PERMISSION_ACCESS]);
        $this->seed($app);

        self::assertSame(200, $app->handle(TestAppFactory::request('GET', self::ME))->getStatusCode());
        self::assertSame(403, $app->handle(TestAppFactory::request('GET', self::QUEUE))->getStatusCode());
        self::assertSame(403, $app->handle(TestAppFactory::request('GET', self::QUEUE . '/' . self::OWN_RFQ))->getStatusCode());
    }

    /** The platform administrator's RFQ grant is module administration, which reads nothing. */
    public function test_the_administrator_grant_reads_no_rfq(): void
    {
        $app = $this->app(['account.login', ...Module::ADMINISTRATOR_PERMISSIONS], ['ADMINISTRATOR']);
        $this->seed($app);

        self::assertSame(403, $app->handle(TestAppFactory::request('GET', self::QUEUE))->getStatusCode());
    }

    // ----------------------------------------------------------------- access

    public function test_the_access_summary_is_what_the_server_resolved(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]]);

        $response = $app->handle(TestAppFactory::request('GET', self::ME));
        $data = $this->data($response);

        self::assertSame(200, $response->getStatusCode());
        $permissions = $data['permissions'];
        self::assertIsArray($permissions);
        self::assertContains(Module::PERMISSION_DRAFT_READ, $permissions);
        self::assertNotContains('ACCOUNT.LOGIN', $permissions, 'Only RFQ.* codes are reported.');
        self::assertArrayNotHasKey('grantedScope', $data, 'reading is not scoped');
        self::assertArrayNotHasKey('effectiveScope', $data);
        self::assertSame(['code' => null, 'source' => 'unavailable'], $data['branch']);
    }

    /** The scaffold invented a person and a branch; the server invents neither. */
    public function test_the_access_summary_names_no_person_and_no_default_branch(): void
    {
        $app = $this->app(['account.login', Module::PERMISSION_ACCESS]);

        $body = (string) $app->handle(TestAppFactory::request('GET', self::ME))->getBody();

        self::assertStringNotContainsString('VIC', $body);
        self::assertStringNotContainsString('@', $body);
    }

    // ------------------------------------------------------------------ queue

    public function test_an_empty_store_answers_an_empty_queue(): void
    {
        $app = $this->app(['account.login', Module::PERMISSION_DRAFT_READ]);

        $data = $this->data($app->handle(TestAppFactory::request('GET', self::QUEUE)));

        self::assertSame([], $data['items']);
        self::assertSame(0, $data['total']);
        self::assertSame([], $data['branches']);
    }

    /** As in 4js: an employee sees every RFQ, a colleague's in another branch included. */
    public function test_an_employee_sees_every_rfq(): void
    {
        $app = $this->app(['account.login', ...Module::EMPLOYEE_PERMISSIONS]);
        $this->seed($app);

        $response = $app->handle(TestAppFactory::request('GET', self::QUEUE));
        $body = (string) $response->getBody();
        $data = $this->data($response);

        self::assertSame(200, $response->getStatusCode(), $body);
        self::assertSame(2, $data['total']);
        self::assertStringContainsString(self::OWN_RFQ, $body);
        self::assertStringContainsString(self::COLLEAGUE_RFQ, $body);
        self::assertSame(['NSW', 'VIC'], $data['branches']);
    }

    public function test_branch_is_a_filter(): void
    {
        $app = $this->app(['account.login', ...Module::EMPLOYEE_PERMISSIONS]);
        $this->seed($app);

        $response = $app->handle(TestAppFactory::request('GET', self::QUEUE)->withQueryParams(['branch' => 'nsw']));
        $body = (string) $response->getBody();

        self::assertSame(1, $this->data($response)['total']);
        self::assertStringContainsString(self::COLLEAGUE_RFQ, $body);
        self::assertSame(400, $app->handle(TestAppFactory::request('GET', self::QUEUE)->withQueryParams(['branch' => "VIC'--"]))->getStatusCode());
    }

    /** The browser renders from `allowedActions`; a reader who holds nothing else gets `view`. */
    public function test_each_item_carries_the_actions_the_caller_may_take(): void
    {
        $app = $this->app(['account.login', Module::PERMISSION_DRAFT_READ]);
        $this->seed($app);

        $items = $this->data($app->handle(TestAppFactory::request('GET', self::QUEUE)))['items'];

        self::assertIsArray($items);
        self::assertIsArray($items[0]);
        self::assertSame(['view'], $items[0]['allowedActions']);
    }

    /** No email address leaves the server in a queue item (invariant 11). */
    public function test_queue_items_carry_no_email_address(): void
    {
        $app = $this->app(['account.login', Module::PERMISSION_DRAFT_READ]);
        $this->seed($app);

        self::assertStringNotContainsString('@', (string) $app->handle(TestAppFactory::request('GET', self::QUEUE))->getBody());
    }

    /** Reading is the only thing an employee holds: a colleague's RFQ offers `view`, nothing more. */
    public function test_an_employee_may_view_a_colleague_s_rfq_and_not_work_it(): void
    {
        $app = $this->app(['account.login', ...Module::EMPLOYEE_PERMISSIONS]);
        $this->seed($app);

        $data = $this->data($app->handle(TestAppFactory::request('GET', self::QUEUE . '/' . self::COLLEAGUE_RFQ)));

        self::assertSame(self::COLLEAGUE_RFQ, $data['publicId']);
        self::assertSame(['view'], $data['allowedActions']);
    }

    public function test_an_invalid_filter_is_a_400_not_an_ignored_filter(): void
    {
        $app = $this->app(['account.login', Module::PERMISSION_DRAFT_READ]);

        $response = $app->handle(TestAppFactory::request('GET', self::QUEUE)->withQueryParams(['status' => 'synced']));
        $payload = TestAppFactory::json($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertIsArray($payload['error'] ?? null);
        self::assertSame('RFQ_QUERY_INVALID', $payload['error']['code'] ?? null);
    }

    // ----------------------------------------------------------------- detail

    public function test_an_rfq_is_returned(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]]);
        $this->seed($app);

        $response = $app->handle(TestAppFactory::request('GET', self::QUEUE . '/' . self::OWN_RFQ));
        $data = $this->data($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::OWN_RFQ, $data['publicId']);
        self::assertSame([], $data['documents']);
        self::assertNull($data['latestRevision']);
    }

    public function test_an_unknown_or_malformed_id_is_a_404(): void
    {
        $app = $this->app(['account.login', ...Module::EMPLOYEE_PERMISSIONS]);
        $this->seed($app);

        $absent = $app->handle(TestAppFactory::request('GET', self::QUEUE . '/33333333-3333-4333-8333-333333333333'));
        $garbage = $app->handle(TestAppFactory::request('GET', self::QUEUE . '/drop-table'));

        foreach ([$absent, $garbage] as $response) {
            self::assertSame(404, $response->getStatusCode());
        }
    }

    // ---------------------------------------------------------------- helpers

    // ------------------------------------------------------------ plan 3.7 writes

    public function test_matching_and_saving_need_the_create_permission(): void
    {
        $app = $this->app(['account.login', Module::PERMISSION_ACCESS, Module::PERMISSION_DRAFT_READ]);
        $parse = $app->handle(TestAppFactory::request('POST', self::PARSE)->withParsedBody(['text' => '4C 16mm2 120m']));
        $create = $app->handle(TestAppFactory::request('POST', self::QUEUE)->withParsedBody(['parseToken' => 'x.y', 'header' => [], 'lines' => []]));

        self::assertSame(403, $parse->getStatusCode());
        self::assertSame(403, $create->getStatusCode());
    }

    public function test_match_text_with_no_parser_says_so(): void
    {
        // TestAppFactory has no RFQ_PARSER_ENABLED: the undeployed client answers.
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]]);
        $response = $app->handle(TestAppFactory::request('POST', self::PARSE)->withParsedBody(['text' => '4C 16mm2 120m']));
        $error = TestAppFactory::json($response)['error'] ?? null;

        self::assertSame(503, $response->getStatusCode(), (string) $response->getBody());
        self::assertIsArray($error);
        self::assertSame('RFQ_PARSER_UNAVAILABLE', $error['code']);
        self::assertTrue($error['retryable']);
        self::assertIsString($error['correlationId']);
    }

    public function test_match_text_needs_text(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]]);
        foreach ([null, [], ['text' => 42]] as $body) {
            $response = $app->handle(TestAppFactory::request('POST', self::PARSE)->withParsedBody($body));
            self::assertSame(422, $response->getStatusCode(), json_encode($body) ?: '');
        }
        $blank = $app->handle(TestAppFactory::request('POST', self::PARSE)->withParsedBody(['text' => "  \n "]));
        self::assertSame(422, $blank->getStatusCode(), 'blank text is refused before the parser is asked');
    }

    public function test_product_search_answers_from_two_characters_and_says_when_the_parser_is_down(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]]);
        $short = $app->handle(TestAppFactory::request('GET', self::QUEUE . '/lookups/products?q=X'));
        self::assertSame(200, $short->getStatusCode());
        self::assertSame(['items' => []], $this->data($short));

        $down = $app->handle(TestAppFactory::request('GET', self::QUEUE . '/lookups/products?q=XL-PAXN'));
        self::assertSame(503, $down->getStatusCode());
    }

    public function test_scan_screenshot_is_validated_before_the_parser_is_asked(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]]);
        $none = $app->handle(TestAppFactory::request('POST', self::QUEUE . '/ocr'));
        self::assertSame(422, $none->getStatusCode());

        $sheet = $app->handle(TestAppFactory::request('POST', self::QUEUE . '/ocr')->withUploadedFiles(['file' => $this->upload('rfq.xlsx', 'application/vnd.ms-excel')]));
        self::assertSame(422, $sheet->getStatusCode(), 'a spreadsheet is not a screenshot');

        // A real image reaches the parser; TestAppFactory has none deployed.
        $down = $app->handle(TestAppFactory::request('POST', self::QUEUE . '/ocr')->withUploadedFiles(['file' => $this->upload('shot.png', 'image/png')]));
        self::assertSame(503, $down->getStatusCode());
        $error = TestAppFactory::json($down)['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame('RFQ_PARSER_UNAVAILABLE', $error['code']);
    }

    public function test_scan_screenshot_needs_the_create_permission(): void
    {
        $app = $this->app(['account.login', Module::PERMISSION_ACCESS, Module::PERMISSION_DRAFT_READ]);
        $response = $app->handle(TestAppFactory::request('POST', self::QUEUE . '/ocr')->withUploadedFiles(['file' => $this->upload('shot.png', 'image/png')]));

        self::assertSame(403, $response->getStatusCode());
    }

    private function upload(string $name, string $type): \Slim\Psr7\UploadedFile
    {
        $bytes = "\x89PNG\r\n\x1a\n" . str_repeat('0', 16);

        return new \Slim\Psr7\UploadedFile((new \Slim\Psr7\Factory\StreamFactory())->createStream($bytes), $name, $type, strlen($bytes), UPLOAD_ERR_OK);
    }

    public function test_margin_and_sell_price_decisions_are_validated(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_ADMIN]]);
        foreach ([
            ['marginPercent' => '100'],
            ['marginPercent' => 33],
            ['marginPercent' => 'abc'],
            ['sellPrice' => '0'],
            ['sellPrice' => '-4'],
            ['sellPrice' => 12],
        ] as $decision) {
            $line = ['lineUid' => 'a', 'comment' => '', ...$decision];
            $response = $app->handle(TestAppFactory::request('POST', self::QUEUE . '/readiness')->withParsedBody(['parseToken' => 'x.y', 'header' => [], 'lines' => [$line]]));
            self::assertSame(422, $response->getStatusCode(), json_encode($decision) ?: '');
        }
    }

    public function test_sales_cannot_set_a_margin(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]]);
        $line = ['lineUid' => 'a', 'comment' => '', 'marginPercent' => '30'];
        $response = $app->handle(TestAppFactory::request('POST', self::QUEUE . '/readiness')->withParsedBody(['parseToken' => null, 'header' => [], 'lines' => [$line]]));

        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
    }

    public function test_readiness_is_validated_like_a_save(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]]);
        foreach ([
            [],
            ['parseToken' => 'x.y', 'header' => [], 'lines' => [['lineUid' => 'a', 'comment' => '', 'cuts' => 'four']]],
            ['parseToken' => 'x.y', 'header' => [], 'lines' => [['lineUid' => 'a', 'comment' => '', 'productCode' => '<script>']]],
        ] as $body) {
            $response = $app->handle(TestAppFactory::request('POST', self::QUEUE . '/readiness')->withParsedBody($body));
            self::assertSame(422, $response->getStatusCode(), json_encode($body) ?: '');
        }
    }

    public function test_the_summary_and_exports_are_validated_like_a_save(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]]);
        foreach (['/summary', '/exports/quote-lines', '/exports/summary-lines'] as $path) {
            foreach ([
                [],
                ['parseToken' => 'x.y', 'header' => [], 'lines' => 'all'],
                ['parseToken' => 'x.y', 'header' => [], 'lines' => [], 'includePrices' => 'yes'],
                ['parseToken' => 'x.y', 'header' => [], 'lines' => [['lineUid' => 'a', 'comment' => '', 'cuts' => 'four']]],
            ] as $body) {
                $response = $app->handle(TestAppFactory::request('POST', self::QUEUE . $path)->withParsedBody($body));
                self::assertSame(422, $response->getStatusCode(), $path . ' ' . json_encode($body));
            }
        }
    }

    public function test_sales_cannot_export_prices_and_a_missing_parser_is_a_503_not_a_file(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]]);
        $body = ['parseToken' => null, 'header' => [], 'lines' => [], 'includePrices' => true];
        foreach (['/summary', '/exports/quote-lines', '/exports/summary-lines'] as $path) {
            $response = $app->handle(TestAppFactory::request('POST', self::QUEUE . $path)->withParsedBody($body));
            self::assertSame(403, $response->getStatusCode(), $path . ' ' . (string) $response->getBody());
        }

        $free = $app->handle(TestAppFactory::request('POST', self::QUEUE . '/exports/quote-lines')->withParsedBody(['parseToken' => null, 'header' => [], 'lines' => []]));
        self::assertSame(503, $free->getStatusCode());
        self::assertStringNotContainsString('text/csv', $free->getHeaderLine('Content-Type'));
    }

    public function test_an_unknown_rfq_has_no_summary_or_file(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]]);
        foreach (['/summary', '/exports/quote-lines', '/exports/summary-lines'] as $path) {
            $response = $app->handle(TestAppFactory::request('POST', self::QUEUE . '/11111111-1111-4111-8111-111111111111' . $path)->withParsedBody(['header' => []]));
            self::assertContains($response->getStatusCode(), [404, 503], $path . ' ' . (string) $response->getBody());
        }
    }

    public function test_save_as_draft_is_validated_before_anything_is_read(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]]);
        $bad = [
            null,
            ['parseToken' => '', 'header' => [], 'lines' => []],
            ['parseToken' => 42, 'header' => [], 'lines' => []],
            ['parseToken' => 'x.y', 'header' => [], 'lines' => 'all'],
            ['parseToken' => 'x.y', 'header' => 'ACME', 'lines' => []],
            ['parseToken' => 'x.y', 'header' => ['customerId' => '12'], 'lines' => []],
            ['parseToken' => 'x.y', 'header' => ['region' => 'mars'], 'lines' => []],
            ['parseToken' => 'x.y', 'header' => ['validTill' => '31/12/26'], 'lines' => []],
        ];
        foreach ($bad as $body) {
            $response = $app->handle(TestAppFactory::request('POST', self::QUEUE)->withParsedBody($body));
            self::assertSame(422, $response->getStatusCode(), json_encode($body) ?: '');
            $error = TestAppFactory::json($response)['error'] ?? null;
            self::assertIsArray($error);
            self::assertSame('RFQ_INPUT_INVALID', $error['code'] ?? null);
        }
    }

    public function test_a_save_is_validated_before_anything_is_read(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]]);
        $bad = [
            null,
            ['lines' => []],
            ['expectedRevision' => 0, 'lines' => []],
            ['expectedRevision' => 1, 'lines' => 'all'],
            ['expectedRevision' => 1, 'lines' => [['lineUid' => 'a', 'approved' => 'yes', 'comment' => '']]],
            ['expectedRevision' => 1, 'lines' => [['lineUid' => 'a', 'approved' => true]]],
            ['expectedRevision' => 1, 'header' => ['currency' => 'EUR'], 'lines' => []],
        ];
        foreach ($bad as $body) {
            $response = $app->handle(TestAppFactory::request('PATCH', self::QUEUE . '/' . self::OWN_RFQ)->withParsedBody($body));
            self::assertSame(422, $response->getStatusCode(), json_encode($body) ?: '');
            $error = TestAppFactory::json($response)['error'] ?? null;
            self::assertIsArray($error);
            self::assertSame('RFQ_INPUT_INVALID', $error['code'] ?? null);
        }
    }

    public function test_saving_an_rfq_that_does_not_exist_is_not_found(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]]);
        $response = $app->handle(TestAppFactory::request('PATCH', self::QUEUE . '/' . self::OWN_RFQ)
            ->withParsedBody(['expectedRevision' => 1, 'lines' => []]));

        self::assertSame(404, $response->getStatusCode());
    }

    // ------------------------------------------------------- workflow panel (E6)

    public function test_every_workflow_write_needs_the_revision_and_validates_its_body(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES], ...Module::ROLE_PERMISSIONS[Module::ROLE_ADMIN]]);
        $this->seed($app);
        $rfq = self::QUEUE . '/' . self::COLLEAGUE_RFQ;
        $cases = [
            ['POST', '/claim', null],
            ['POST', '/claim', ['expectedRevision' => 0]],
            ['POST', '/claim', ['expectedRevision' => '1']],
            ['PATCH', '/assignment', ['expectedRevision' => 1]],
            ['PATCH', '/assignment', ['expectedRevision' => 1, 'assigneeId' => 5]],
            ['PATCH', '/workflow', ['expectedRevision' => 1, 'status' => 'new']],
            ['PATCH', '/workflow', ['expectedRevision' => 1, 'status' => 'synced', 'priority' => 'normal', 'reason' => '']],
            ['PATCH', '/workflow', ['expectedRevision' => 1, 'status' => 'new', 'priority' => 'normal', 'reason' => ['x']]],
            ['POST', '/archive', ['expectedRevision' => 1]],
            ['POST', '/archive', ['expectedRevision' => 1, 'reason' => '   ']],
            ['POST', '/archive', ['expectedRevision' => 1, 'reason' => str_repeat('x', 501)]],
            ['POST', '/restore', ['expectedRevision' => -1]],
            ['POST', '/comments', ['expectedRevision' => 1, 'message' => '']],
            ['POST', '/comments', ['expectedRevision' => 1, 'message' => str_repeat('x', 2001)]],
        ];
        foreach ($cases as [$method, $suffix, $body]) {
            $response = $app->handle(TestAppFactory::request($method, $rfq . $suffix)->withParsedBody($body));
            self::assertSame(422, $response->getStatusCode(), $method . ' ' . $suffix . ' ' . json_encode($body));
            $error = TestAppFactory::json($response)['error'] ?? null;
            self::assertIsArray($error);
            self::assertSame('RFQ_INPUT_INVALID', $error['code'] ?? null);
        }
    }

    public function test_workflow_writes_on_an_unknown_rfq_are_404_and_without_a_store_they_are_503(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES], ...Module::ROLE_PERMISSIONS[Module::ROLE_ADMIN]]);
        $this->seed($app);
        $body = ['expectedRevision' => 1, 'status' => 'in_progress', 'priority' => 'normal', 'reason' => '', 'message' => 'hi', 'assigneeId' => null];

        foreach ([['POST', '/claim'], ['PATCH', '/workflow'], ['POST', '/comments'], ['GET', '/activity']] as [$method, $suffix]) {
            $missing = $app->handle(TestAppFactory::request($method, self::QUEUE . '/33333333-3333-4333-8333-333333333333' . $suffix)->withParsedBody($body));
            self::assertSame(404, $missing->getStatusCode(), $method . ' ' . $suffix);
            self::assertSame('RFQ_NOT_FOUND', $this->errorCode($missing));
        }
        // The RFQ is there; the memory build has no store to write to, and says so.
        $unwritable = $app->handle(TestAppFactory::request('POST', self::QUEUE . '/' . self::COLLEAGUE_RFQ . '/comments')->withParsedBody($body));
        self::assertSame(503, $unwritable->getStatusCode());
        self::assertSame('RFQ_STORE_UNAVAILABLE', $this->errorCode($unwritable));
    }

    public function test_a_rep_may_not_assign_and_the_people_lookup_is_for_managers(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]]);
        $this->seed($app);

        $assign = $app->handle(TestAppFactory::request('PATCH', self::QUEUE . '/' . self::COLLEAGUE_RFQ . '/assignment')
            ->withParsedBody(['expectedRevision' => 1, 'assigneeId' => null]));
        self::assertSame(403, $assign->getStatusCode(), 'no RFQ.DRAFT.ASSIGN');
        self::assertSame(403, $app->handle(TestAppFactory::request('GET', self::QUEUE . '/lookups/people?q=a'))->getStatusCode());
    }

    public function test_the_people_lookup_and_the_activity_trail_answer_items(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES], ...Module::ROLE_PERMISSIONS[Module::ROLE_ADMIN]]);
        $this->seed($app);

        self::assertSame(['items' => []], $this->data($app->handle(TestAppFactory::request('GET', self::QUEUE . '/lookups/people?q=pat'))));
        self::assertSame(['items' => []], $this->data($app->handle(TestAppFactory::request('GET', self::QUEUE . '/' . self::COLLEAGUE_RFQ . '/activity'))));
    }

    public function test_a_summary_names_no_blocked_reason_unless_the_rfq_is_blocked(): void
    {
        $app = $this->app(['account.login', Module::PERMISSION_DRAFT_READ]);
        $this->seed($app);

        $detail = $this->data($app->handle(TestAppFactory::request('GET', self::QUEUE . '/' . self::OWN_RFQ)));
        self::assertArrayHasKey('blockedReason', $detail);
        self::assertNull($detail['blockedReason']);
    }

    /**
     * @param list<string> $permissions
     * @param list<string> $roles
     * @return App<ContainerInterface>
     */
    private function app(array $permissions, array $roles = ['EMPLOYEE']): App
    {
        $app = TestAppFactory::create();
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(self::SIGNED_IN_USER_ID, $permissions, $roles);

        return $app;
    }

    /**
     * Two RFQs: one the signed-in user owns, one a colleague in another branch
     * owns. Everyone who reads sees both.
     *
     * @param App<ContainerInterface> $app
     */
    private function seed(App $app): void
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $repository = $container->get(RfqRepository::class);
        self::assertInstanceOf(InMemoryRfqRepository::class, $repository);

        $repository
            ->withRecord(ReadRfqQueueServiceTest::record(self::OWN_RFQ, self::SIGNED_IN_USER_ID, 'VIC'))
            ->withRecord(ReadRfqQueueServiceTest::record(self::COLLEAGUE_RFQ, 99, 'NSW'));
    }

    private function errorCode(ResponseInterface $response): mixed
    {
        $error = TestAppFactory::json($response)['error'] ?? null;
        self::assertIsArray($error);

        return $error['code'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(ResponseInterface $response): array
    {
        $payload = TestAppFactory::json($response);
        self::assertArrayHasKey('data', $payload, (string) $response->getBody());
        $data = $payload['data'];
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }
}
