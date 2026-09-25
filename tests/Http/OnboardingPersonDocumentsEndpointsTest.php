<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Product\Storage\InMemoryProductBlobStore;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonRecord;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonSummary;
use TriCab\Core\Onboarding\Application\DTO\OnboardingDocumentRef;
use TriCab\Core\Onboarding\Contract\OnboardingDocumentRepository;
use TriCab\Core\Onboarding\Contract\OnboardingPeopleRepository;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingDocumentRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingPeopleRepository;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * Phase 5.4 over HTTP: HR's route to a person's documents.
 *
 * Held here: only PEOPLE.MANAGE opens it; a document is found only under the
 * person it belongs to; every class is streamed, never signed; and the read is
 * audited against HR, before the bytes, under the permission that admitted it.
 */
final class OnboardingPersonDocumentsEndpointsTest extends TestCase
{
    private const HR_USER_ID = 1;
    private const PERSON = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const PERSON_USER_ID = 7;
    private const OTHER = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const OTHER_USER_ID = 8;
    private const DOCUMENT = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
    private const BASE = '/api/v1/admin/onboarding/people/';

    protected function setUp(): void
    {
        InMemoryProductBlobStore::resetSharedForTests();
    }

    protected function tearDown(): void
    {
        // The double mirrors bytes to the temp directory, so delete what was written.
        (new InMemoryProductBlobStore())->deleteObject('onboarding', self::blobPath(true));
        InMemoryProductBlobStore::resetSharedForTests();
    }

    public function test_only_people_manage_opens_the_documents(): void
    {
        foreach ([['ONBOARDING.SELF.READ', 'ONBOARDING.SELF.SUBMIT'], ['ONBOARDING.REVIEW'], ['ONBOARDING.CONTENT.MANAGE'], ['ONBOARDING.REPORTS.READ']] as $held) {
            $app = $this->app(['account.login', ...$held]);
            $this->seed($app, DataClass::payroll());

            foreach ([self::listPath(self::PERSON), self::contentPath(self::PERSON, self::DOCUMENT)] as $path) {
                $response = $app->handle(TestAppFactory::request('GET', $path));
                self::assertSame(403, $response->getStatusCode(), implode(',', $held) . ' reached ' . $path);
            }
            self::assertSame([], $this->documents($app)->accessLog);
        }
    }

    public function test_the_list_is_metadata_for_that_person_only(): void
    {
        $app = $this->app();
        $this->seed($app, DataClass::payroll());

        $response = $app->handle(TestAppFactory::request('GET', self::listPath(self::PERSON)));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $documents = $this->data($response)['documents'] ?? null;
        self::assertIsArray($documents);
        self::assertCount(1, $documents);
        self::assertIsArray($documents[0]);
        self::assertSame(self::DOCUMENT, $documents[0]['publicId']);
        self::assertArrayNotHasKey('blobPath', $documents[0]);

        $other = $this->data($app->handle(TestAppFactory::request('GET', self::listPath(self::OTHER))));
        self::assertSame([], $other['documents']);
        self::assertSame([], $this->documents($app)->accessLog, 'Listing is not a read of any document.');
    }

    public function test_an_unknown_person_is_not_found(): void
    {
        $app = $this->app();

        $response = $app->handle(TestAppFactory::request('GET', self::listPath('cccccccc-cccc-4ccc-8ccc-cccccccccccc')));
        self::assertSame(404, $response->getStatusCode());
    }

    public function test_every_class_is_streamed_as_an_attachment_and_audited_against_hr(): void
    {
        foreach ([DataClass::ordinaryHr(), DataClass::payroll()] as $class) {
            $app = $this->app();
            $this->seed($app, $class);

            $response = $app->handle(TestAppFactory::request('GET', self::contentPath(self::PERSON, self::DOCUMENT)));

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame('%PDF-synthetic', (string) $response->getBody(), $class->code() . ' was not streamed');
            self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
            self::assertStringStartsWith('attachment;', $response->getHeaderLine('Content-Disposition'));
            self::assertSame('no-store, private', $response->getHeaderLine('Cache-Control'));
            self::assertSame([[
                'document' => self::DOCUMENT,
                'actorUserId' => self::HR_USER_ID,
                'subjectUserId' => self::PERSON_USER_ID,
                'accessKind' => 'download',
                'permissionCode' => 'ONBOARDING.PEOPLE.MANAGE',
            ]], $this->documents($app)->accessLog);
        }
    }

    public function test_a_document_is_not_found_under_somebody_else_and_leaves_no_audit_row(): void
    {
        $app = $this->app();
        $this->seed($app, DataClass::payroll());

        $response = $app->handle(TestAppFactory::request('GET', self::contentPath(self::OTHER, self::DOCUMENT)));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $this->documents($app)->accessLog, 'A refused read is not a read.');
    }

    public function test_a_leavers_documents_stay_readable(): void
    {
        $app = $this->app();
        $this->seed($app, DataClass::payroll(), new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')));

        $response = $app->handle(TestAppFactory::request('GET', self::contentPath(self::PERSON, self::DOCUMENT)));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    public function test_missing_bytes_are_not_found_but_the_authorized_read_is_still_audited(): void
    {
        $app = $this->app();
        $this->seed($app, DataClass::payroll(), null, false);

        $response = $app->handle(TestAppFactory::request('GET', self::contentPath(self::PERSON, self::DOCUMENT)));

        self::assertSame(404, $response->getStatusCode());
        self::assertCount(1, $this->documents($app)->accessLog);
    }

    // --------------------------------------------------------------- helpers

    /** @param App<ContainerInterface> $app */
    private function seed(App $app, DataClass $class, ?DateTimeImmutable $departedAtUtc = null, bool $withBytes = true): void
    {
        $token = RowVersionToken::fromBinary("\x00\x00\x00\x00\x00\x00\x07\xD1");
        $people = $this->container($app)->get(OnboardingPeopleRepository::class);
        self::assertInstanceOf(InMemoryOnboardingPeopleRepository::class, $people);
        $people->withPerson(
            new OnboardingPersonRecord(self::PERSON_USER_ID, self::PERSON, $token, $departedAtUtc),
            new OnboardingPersonSummary(self::PERSON, 'Sam Example', 'sam@example.test', null, null, null, null, null, null, []),
        );
        $people->withPerson(
            new OnboardingPersonRecord(self::OTHER_USER_ID, self::OTHER, $token, null),
            new OnboardingPersonSummary(self::OTHER, 'Lee Other', 'lee@example.test', null, null, null, null, null, null, []),
        );

        $this->documents($app)->withDocument(self::PERSON_USER_ID, new OnboardingDocumentRef(
            publicId: self::DOCUMENT,
            ownerUserId: self::PERSON_USER_ID,
            documentKind: 'employee_details',
            dataClass: $class,
            blobContainer: 'onboarding',
            blobPath: self::blobPath($withBytes),
            contentType: 'application/pdf',
            byteSize: 14,
            contentSha256: str_repeat('c', 64),
            generatedAtUtc: '2026-09-24T00:00:00+00:00',
        ));

        if ($withBytes) {
            $blobs = $this->container($app)->get(ProductBlobStore::class);
            self::assertInstanceOf(ProductBlobStore::class, $blobs);
            $blobs->putObject('onboarding', self::blobPath(true), 'application/pdf', '%PDF-synthetic');
        }
    }

    /** A reference whose bytes were never written points somewhere nothing is. */
    private static function blobPath(bool $withBytes): string
    {
        return 'synthetic/person-documents-test/' . self::DOCUMENT . ($withBytes ? '' : '-missing') . '.pdf';
    }

    private static function listPath(string $person): string
    {
        return self::BASE . $person . '/documents';
    }

    private static function contentPath(string $person, string $document): string
    {
        return self::BASE . $person . '/documents/' . $document . '/content';
    }

    /**
     * @param list<string> $permissions
     *
     * @return App<ContainerInterface>
     */
    private function app(array $permissions = ['account.login', 'ONBOARDING.PEOPLE.MANAGE']): App
    {
        $app = TestAppFactory::create();
        $users = $this->container($app)->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(self::HR_USER_ID, $permissions, ['EMPLOYEE']);

        return $app;
    }

    /** @param App<ContainerInterface> $app */
    private function documents(App $app): InMemoryOnboardingDocumentRepository
    {
        $documents = $this->container($app)->get(OnboardingDocumentRepository::class);
        self::assertInstanceOf(InMemoryOnboardingDocumentRepository::class, $documents);

        return $documents;
    }

    /** @param App<ContainerInterface> $app */
    private function container(App $app): ContainerInterface
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
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
}
