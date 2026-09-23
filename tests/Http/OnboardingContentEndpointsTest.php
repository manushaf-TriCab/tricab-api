<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;

/**
 * Phase 4.4 over HTTP: content authoring.
 *
 * What only this layer shows: that `ONBOARDING.CONTENT.MANAGE` alone opens these
 * routes, that the answer key is on the version route and nowhere else — not on
 * the list, not on the item's history — and that each refusal carries its own
 * code.
 */
final class OnboardingContentEndpointsTest extends TestCase
{
    private const CONTENT = '/api/v1/admin/onboarding/content';

    private const ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    public function test_only_content_manage_opens_the_routes(): void
    {
        foreach ([['ONBOARDING.REVIEW'], ['ONBOARDING.PEOPLE.MANAGE'], ['ONBOARDING.REPORTS.READ'], ['ONBOARDING.SELF.READ', 'ONBOARDING.SELF.SUBMIT'], ['ONBOARDING.ADMIN']] as $held) {
            $app = $this->app(['account.login', ...$held]);
            foreach (['training', 'policies', 'forms'] as $segment) {
                $base = self::CONTENT . '/' . $segment;
                foreach ([
                    TestAppFactory::request('GET', self::CONTENT),
                    $this->json('POST', $base, ['slug' => 'x', 'title' => 'X']),
                    TestAppFactory::request('GET', $base . '/' . self::ID),
                    $this->json('PUT', $base . '/' . self::ID, ['rowVersion' => 'AAAAAAAAAAE=', 'title' => 'X']),
                    $this->json('DELETE', $base . '/' . self::ID, ['rowVersion' => 'AAAAAAAAAAE=']),
                    $this->json('POST', $base . '/' . self::ID . '/versions', []),
                    TestAppFactory::request('GET', $base . '/' . self::ID . '/versions/' . self::ID),
                    $this->json('PUT', $base . '/' . self::ID . '/versions/' . self::ID, ['rowVersion' => 'AAAAAAAAAAE=']),
                    $this->json('POST', $base . '/' . self::ID . '/versions/' . self::ID . '/publish', ['rowVersion' => 'AAAAAAAAAAE=', 'supersedesWorkInProgress' => false]),
                ] as $request) {
                    self::assertSame(403, $app->handle($request)->getStatusCode(), implode(',', $held) . ' reached ' . $request->getMethod() . ' ' . $request->getUri()->getPath());
                }
            }
        }
    }

    public function test_a_module_is_authored_and_published_and_the_answer_key_stays_on_the_version_route(): void
    {
        $app = $this->app();

        $created = $app->handle($this->json('POST', self::CONTENT . '/training', ['slug' => 'forklift-awareness', 'title' => 'Forklift awareness', 'summary' => 'Near moving plant.']));
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $detail = $this->data($created);
        $item = self::arr($detail['item']);
        $itemPublicId = self::str($item['publicId']);
        $versions = self::arr($detail['versions']);
        $versionPublicId = self::str(self::arr($versions[0])['publicId']);
        $base = self::CONTENT . '/training/' . $itemPublicId;

        $read = $this->data($app->handle(TestAppFactory::request('GET', $base . '/versions/' . $versionPublicId)));
        $version = self::arr($read['version']);
        self::assertSame(['Add at least one slide.', 'Add at least one question: passing the assessment is how a module is completed.'], $version['publishProblems']);

        $saved = $app->handle($this->json('PUT', $base . '/versions/' . $versionPublicId, [
            'rowVersion' => $version['rowVersion'],
            'slides' => [['id' => 'intro', 'title' => 'Why forklifts are dangerous']],
            'passMarkPercent' => 100,
            'questions' => [[
                'prompt' => 'A forklift is reversing towards you. What first?',
                'options' => [['key' => 'a', 'label' => 'Wave and keep walking'], ['key' => 'b', 'label' => 'Stop and wait to be seen']],
                'correctOptionKey' => 'b',
                'correctReason' => 'A driver who has not seen you cannot avoid you.',
            ]],
        ]));
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $savedVersion = self::arr($this->data($saved)['version']);
        $question = self::arr(self::arr(self::arr($savedVersion['content'])['questions'])[0]);
        self::assertSame('b', $question['correctOptionKey'], 'the author sees the answer key');

        $published = $app->handle($this->json('POST', $base . '/versions/' . $versionPublicId . '/publish', [
            'rowVersion' => $savedVersion['rowVersion'],
            'supersedesWorkInProgress' => false,
        ]));
        self::assertSame(200, $published->getStatusCode(), (string) $published->getBody());
        self::assertSame(['retiredVersionNumber' => null, 'reissuedCount' => 0], $this->data($published)['result']);
        self::assertSame('live', self::arr($this->data($published)['version'])['state']);

        // The list and the history carry no version body at all.
        foreach ([self::CONTENT, $base] as $path) {
            $body = (string) $app->handle(TestAppFactory::request('GET', $path))->getBody();
            self::assertStringNotContainsString('correctOptionKey', $body, $path);
            self::assertStringNotContainsString('cannot avoid you', $body, $path);
        }
        $list = self::arr($this->data($app->handle(TestAppFactory::request('GET', self::CONTENT)))['items']);
        self::assertSame(1, self::arr($list[0])['liveVersionNumber']);

        // Frozen.
        $late = $app->handle($this->json('PUT', $base . '/versions/' . $versionPublicId, ['rowVersion' => $savedVersion['rowVersion'], 'slides' => [], 'passMarkPercent' => 80, 'questions' => []]));
        self::assertSame(409, $late->getStatusCode());
        self::assertSame('ONBOARDING_CONTENT_VERSION_PUBLISHED', $this->code($late));

        // One draft at a time.
        self::assertSame(201, $app->handle($this->json('POST', $base . '/versions', []))->getStatusCode());
        $second = $app->handle($this->json('POST', $base . '/versions', []));
        self::assertSame(409, $second->getStatusCode());
        self::assertSame('ONBOARDING_CONTENT_DRAFT_EXISTS', $this->code($second));
    }

    public function test_refusals_are_named(): void
    {
        $app = $this->app();

        $policy = $this->data($app->handle($this->json('POST', self::CONTENT . '/policies', ['slug' => 'whs', 'title' => 'WHS'])));
        $itemPublicId = self::str(self::arr($policy['item'])['publicId']);
        $versionPublicId = self::str(self::arr(self::arr($policy['versions'])[0])['publicId']);
        $version = self::arr($this->data($app->handle(TestAppFactory::request('GET', self::CONTENT . '/policies/' . $itemPublicId . '/versions/' . $versionPublicId)))['version']);
        $base = self::CONTENT . '/policies/' . $itemPublicId . '/versions/' . $versionPublicId;

        $html = $app->handle($this->json('PUT', $base, ['rowVersion' => $version['rowVersion'], 'blocks' => [['type' => 'html', 'html' => '<script>alert(1)</script>']]]));
        self::assertSame(422, $html->getStatusCode(), 'raw HTML is refused, as the source’s own test demands');
        self::assertSame('VALIDATION_FAILED', $this->code($html));

        $empty = $app->handle($this->json('POST', $base . '/publish', ['rowVersion' => $version['rowVersion'], 'supersedesWorkInProgress' => false]));
        self::assertSame(409, $empty->getStatusCode());
        self::assertSame('ONBOARDING_CONTENT_NOT_PUBLISHABLE', $this->code($empty));

        $noFlag = $app->handle($this->json('POST', $base . '/publish', ['rowVersion' => $version['rowVersion']]));
        self::assertSame(422, $noFlag->getStatusCode(), 'the supersede decision is never defaulted');

        $taken = $app->handle($this->json('POST', self::CONTENT . '/policies', ['slug' => 'whs', 'title' => 'Again']));
        self::assertSame(409, $taken->getStatusCode());
        self::assertSame('ONBOARDING_CONTENT_SLUG_TAKEN', $this->code($taken));

        $formWithoutGroup = $app->handle($this->json('POST', self::CONTENT . '/forms', ['slug' => 'details', 'title' => 'Details']));
        self::assertSame(422, $formWithoutGroup->getStatusCode());

        // A policy's id is not a module's: the type comes from the path.
        $wrongType = $app->handle(TestAppFactory::request('GET', self::CONTENT . '/training/' . $itemPublicId));
        self::assertSame(404, $wrongType->getStatusCode());
        self::assertSame('ONBOARDING_NOT_FOUND', $this->code($wrongType));
    }

    public function test_an_item_nobody_was_given_is_deleted(): void
    {
        $app = $this->app();

        $policy = $this->data($app->handle($this->json('POST', self::CONTENT . '/policies', ['slug' => 'mistake', 'title' => 'Made by mistake'])));
        $item = self::arr($policy['item']);
        self::assertTrue($policy['canDelete']);
        self::assertSame(0, $policy['allocationCount']);
        $itemPublicId = self::str($item['publicId']);

        $noToken = $app->handle($this->json('DELETE', self::CONTENT . '/policies/' . $itemPublicId, []));
        self::assertSame(422, $noToken->getStatusCode());

        $deleted = $app->handle($this->json('DELETE', self::CONTENT . '/policies/' . $itemPublicId, ['rowVersion' => $item['rowVersion']]));
        self::assertSame(200, $deleted->getStatusCode(), (string) $deleted->getBody());
        self::assertSame(404, $app->handle(TestAppFactory::request('GET', self::CONTENT . '/policies/' . $itemPublicId))->getStatusCode());

        // The slug is free again.
        self::assertSame(201, $app->handle($this->json('POST', self::CONTENT . '/policies', ['slug' => 'mistake', 'title' => 'Again']))->getStatusCode());
    }

    public function test_a_policy_carries_its_placement_metadata_and_emphasis_through_the_api(): void
    {
        $app = $this->app();

        $policy = $this->data($app->handle($this->json('POST', self::CONTENT . '/policies', [
            'slug' => 'leave-policy', 'title' => 'Leave Policy', 'summary' => 'Leave of every kind.', 'chapterTitle' => 'Working at TriCab', 'sortOrder' => 7,
        ])));
        $item = self::arr($policy['item']);
        self::assertSame('Working at TriCab', $item['chapterTitle']);
        self::assertSame(7, $item['sortOrder']);
        self::assertSame('Leave of every kind.', $item['summary']);

        $itemPublicId = self::str($item['publicId']);
        $versionPublicId = self::str(self::arr(self::arr($policy['versions'])[0])['publicId']);
        $base = self::CONTENT . '/policies/' . $itemPublicId . '/versions/' . $versionPublicId;
        $version = self::arr($this->data($app->handle(TestAppFactory::request('GET', $base)))['version']);

        $saved = $app->handle($this->json('PUT', $base, [
            'rowVersion' => $version['rowVersion'],
            'blocks' => [['type' => 'paragraph', 'text' => 'Annual leave: four weeks.', 'marks' => ['text' => [['start' => 0, 'end' => 13, 'mark' => 'bold']]]]],
            'documentReference' => 'HR-POL-034-V000',
            'issuedLabel' => 'September-25',
            'reviewDueLabel' => 'September-28',
            'requiresAcknowledgement' => false,
        ]));
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $content = self::arr(self::arr($this->data($saved)['version'])['content']);
        self::assertSame('HR-POL-034-V000', $content['documentReference']);
        self::assertFalse($content['requiresAcknowledgement']);
        self::assertSame(['text' => [['start' => 0, 'end' => 13, 'mark' => 'bold']]], self::arr(self::arr($content['blocks'])[0])['marks']);

        $underline = $app->handle($this->json('PUT', $base, [
            'rowVersion' => self::arr($this->data($saved)['version'])['rowVersion'],
            'blocks' => [['type' => 'paragraph', 'text' => 'x', 'marks' => ['text' => [['start' => 0, 'end' => 1, 'mark' => 'underline']]]]],
        ]));
        self::assertSame(422, $underline->getStatusCode(), 'only bold and italic are marks');
    }

    // --------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $body
     */
    private function json(string $method, string $path, array $body): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode((object) $body, JSON_THROW_ON_ERROR));

        return $request;
    }

    /**
     * @param list<string> $permissions
     *
     * @return App<ContainerInterface>
     */
    private function app(array $permissions = ['account.login', 'ONBOARDING.CONTENT.MANAGE']): App
    {
        $app = TestAppFactory::create();
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, $permissions, ['EMPLOYEE']);

        return $app;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(ResponseInterface $response): array
    {
        $payload = TestAppFactory::json($response);
        self::assertIsArray($payload['data'] ?? null, (string) $response->getBody());

        /** @var array<string, mixed> */
        return $payload['data'];
    }

    private function code(ResponseInterface $response): string
    {
        $payload = TestAppFactory::json($response);
        $error = $payload['error'] ?? null;
        self::assertIsArray($error, (string) $response->getBody());

        return self::str($error['code'] ?? '');
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    private static function str(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
