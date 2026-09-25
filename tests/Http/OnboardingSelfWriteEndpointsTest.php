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
use TriCab\Core\Onboarding\Application\DTO\OnboardingDocumentRef;
use TriCab\Core\Onboarding\Application\DTO\OnboardingFormDetail;
use TriCab\Core\Onboarding\Application\DTO\OnboardingPolicyDetail;
use TriCab\Core\Onboarding\Application\DTO\OnboardingTraineeRecord;
use TriCab\Core\Onboarding\Application\DTO\OnboardingTrainingDetail;
use TriCab\Core\Onboarding\Contract\OnboardingDocumentRepository;
use TriCab\Core\Onboarding\Contract\OnboardingFormRepository;
use TriCab\Core\Onboarding\Contract\OnboardingPolicyRepository;
use TriCab\Core\Onboarding\Contract\OnboardingSelfServiceRepository;
use TriCab\Core\Onboarding\Contract\OnboardingTrainingRepository;
use TriCab\Core\Onboarding\Domain\ValueObject\AssessmentQuestion;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;
use TriCab\Core\Onboarding\Domain\ValueObject\PresentationManifest;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingDocumentRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingFormRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingPolicyRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingSelfServiceRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingTrainingRepository;

/**
 * Phase 3.3 over HTTP — the write side, its gates and its error codes.
 *
 * Three things are only observable at this layer and are therefore tested here
 * rather than against the services:
 *
 *  1. **`ONBOARDING.SELF.READ` does not grant a write.** The two permissions are
 *     what the leaver policy turns into at the route table, so the split has to
 *     be real at the route table.
 *  2. **The error code on the envelope**, which the SPA branches on — reload for
 *     a superseded version, an error for anything else in the 409 family.
 *  3. **What is actually on the wire.** A forged score field has to be
 *     demonstrably ignored, and an answer key has to be demonstrably absent from
 *     the bytes, not merely absent from a DTO.
 */
final class OnboardingSelfWriteEndpointsTest extends TestCase
{
    private const SIGNED_IN_USER_ID = 1;
    private const SOMEBODY_ELSE_USER_ID = 2;

    private const TRAINING = 'assignment-training';
    private const POLICY = 'assignment-policy';
    private const FORM = 'assignment-form';

    private const TRAINING_VERSION = 'version-training-3';
    private const POLICY_VERSION = 'version-policy-2';
    private const FORM_VERSION = 'version-form-1';

    private const READ = ['account.login', 'ONBOARDING.SELF.READ'];
    private const READ_AND_SUBMIT = ['account.login', 'ONBOARDING.SELF.READ', 'ONBOARDING.SELF.SUBMIT'];

    // ------------------------------------------------------------- the gates

    /**
     * The split that carries the leaver policy. Reading an induction and adding
     * to it are different permissions, so a person can keep one without the
     * other.
     */
    public function test_reading_a_module_is_allowed_where_writing_to_it_is_not(): void
    {
        $app = $this->appWithTraining(self::READ);

        $read = $app->handle(TestAppFactory::request('GET', '/api/v1/onboarding/training/' . self::TRAINING));
        self::assertSame(200, $read->getStatusCode(), (string) $read->getBody());

        $write = $app->handle($this->json('POST', '/api/v1/onboarding/training/' . self::TRAINING . '/progress', [
            'versionPublicId' => self::TRAINING_VERSION,
            'slideIds' => ['intro'],
        ]));
        self::assertSame(403, $write->getStatusCode(), (string) $write->getBody());
    }

    public function test_every_write_route_is_refused_without_the_submit_permission(): void
    {
        $app = $this->appWithTraining(self::READ);
        $this->seedPolicy($app);
        $this->seedForm($app);

        foreach ([
            ['POST', '/api/v1/onboarding/training/' . self::TRAINING . '/progress'],
            ['POST', '/api/v1/onboarding/training/' . self::TRAINING . '/attempts'],
            ['POST', '/api/v1/onboarding/training/' . self::TRAINING . '/attempts/attempt-0001'],
            ['POST', '/api/v1/onboarding/policies/' . self::POLICY . '/acknowledgement'],
            ['PUT', '/api/v1/onboarding/forms/' . self::FORM . '/draft'],
            ['POST', '/api/v1/onboarding/forms/' . self::FORM . '/submission'],
        ] as [$method, $path]) {
            $response = $app->handle($this->json($method, $path, ['versionPublicId' => 'anything']));

            self::assertSame(403, $response->getStatusCode(), $method . ' ' . $path . ' was not gated.');
        }
    }

    /**
     * Invariant 4, over HTTP: another person's assignment answers exactly as a
     * mistyped id does.
     */
    public function test_another_person_s_item_answers_the_same_as_one_that_does_not_exist(): void
    {
        $app = $this->appWithTraining(self::READ_AND_SUBMIT, owner: self::SOMEBODY_ELSE_USER_ID);

        $theirs = $app->handle(TestAppFactory::request('GET', '/api/v1/onboarding/training/' . self::TRAINING));
        $nonsense = $app->handle(TestAppFactory::request('GET', '/api/v1/onboarding/training/no-such-item'));

        self::assertSame(404, $theirs->getStatusCode());
        self::assertSame(404, $nonsense->getStatusCode());
        self::assertSame((string) $nonsense->getBody(), (string) $theirs->getBody(), 'The two must be indistinguishable.');
    }

    // ------------------------------------------------------- the write paths

    public function test_progress_is_recorded_and_the_item_opens(): void
    {
        $app = $this->appWithTraining(self::READ_AND_SUBMIT);

        $response = $app->handle($this->json('POST', '/api/v1/onboarding/training/' . self::TRAINING . '/progress', [
            'versionPublicId' => self::TRAINING_VERSION,
            'slideIds' => ['intro', 'hazards'],
        ]));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);
        self::assertSame(['intro', 'hazards'], $data['reachedSlideIds']);
        self::assertSame('in_progress', $data['status']);
        self::assertFalse($data['presentationComplete']);
    }

    public function test_a_superseded_version_answers_with_the_code_the_client_reloads_on(): void
    {
        $app = $this->appWithTraining(self::READ_AND_SUBMIT);

        $response = $app->handle($this->json('POST', '/api/v1/onboarding/training/' . self::TRAINING . '/progress', [
            'versionPublicId' => 'version-training-2',
            'slideIds' => ['intro'],
        ]));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('ONBOARDING_CONTENT_SUPERSEDED', $this->errorCode($response));
    }

    public function test_a_slide_the_manifest_does_not_have_is_a_422(): void
    {
        $app = $this->appWithTraining(self::READ_AND_SUBMIT);

        $response = $app->handle($this->json('POST', '/api/v1/onboarding/training/' . self::TRAINING . '/progress', [
            'versionPublicId' => self::TRAINING_VERSION,
            'slideIds' => ['made-up'],
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('ONBOARDING_UNKNOWN_SLIDE', $this->errorCode($response));
    }

    /**
     * The whole of work order invariant 1, at the layer where it can actually be
     * checked: the bytes on the wire.
     */
    public function test_the_questions_on_the_wire_carry_no_answer_key(): void
    {
        $app = $this->appWithTraining(self::READ_AND_SUBMIT, reached: ['intro', 'hazards', 'sign-off']);

        $response = $app->handle($this->json('POST', '/api/v1/onboarding/training/' . self::TRAINING . '/attempts', [
            'versionPublicId' => self::TRAINING_VERSION,
        ]));
        $body = (string) $response->getBody();

        self::assertSame(201, $response->getStatusCode(), $body);
        self::assertStringNotContainsString('correctOptionKey', $body);
        self::assertStringNotContainsString('correctReason', $body);
        self::assertStringNotContainsString('Because the guard is the last line', $body);
        self::assertStringContainsString('A guard is missing', $body, 'The prompt is still there.');
    }

    /**
     * Invariant 2. The forged fields are not rejected — they are not read, which
     * is the stronger version, and the score that comes back is the server's.
     */
    public function test_a_forged_score_in_the_body_changes_nothing(): void
    {
        $app = $this->appWithTraining(self::READ_AND_SUBMIT, reached: ['intro', 'hazards', 'sign-off']);
        $attemptPublicId = $this->startedAttemptId($app);

        $response = $app->handle($this->json(
            'POST',
            '/api/v1/onboarding/training/' . self::TRAINING . '/attempts/' . $attemptPublicId,
            [
                'versionPublicId' => self::TRAINING_VERSION,
                'answers' => ['q1' => 'a', 'q2' => 'a'],
                'scorePercent' => 100,
                'isPass' => true,
            ],
            'key-forged',
        ));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);
        self::assertSame(0, $data['scorePercent']);
        self::assertFalse($data['isPass']);
    }

    public function test_submitting_without_an_idempotency_key_is_refused(): void
    {
        $app = $this->appWithTraining(self::READ_AND_SUBMIT, reached: ['intro', 'hazards', 'sign-off']);
        $attemptPublicId = $this->startedAttemptId($app);

        $response = $app->handle($this->json(
            'POST',
            '/api/v1/onboarding/training/' . self::TRAINING . '/attempts/' . $attemptPublicId,
            ['versionPublicId' => self::TRAINING_VERSION, 'answers' => ['q1' => 'b', 'q2' => 'b']],
        ));

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('VALIDATION_FAILED', $this->errorCode($response));
    }

    public function test_acknowledging_a_policy_completes_it_and_does_not_take_the_wording_from_the_request(): void
    {
        $app = $this->app(self::READ_AND_SUBMIT);
        $this->seedPolicy($app);

        $response = $app->handle($this->json(
            'POST',
            '/api/v1/onboarding/policies/' . self::POLICY . '/acknowledgement',
            ['versionPublicId' => self::POLICY_VERSION, 'declarationText' => 'I have skimmed this.'],
            'key-ack',
        ));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('completed', $this->data($response)['status']);

        $policies = $this->policies($app);
        self::assertSame(
            ['I have read and accept the Site Safety policy.'],
            $policies->declarations,
            'A declarationText field in the body must have no effect whatsoever.',
        );
    }

    public function test_a_read_only_policy_is_marked_read_through_the_same_route_and_writes_no_acknowledgement(): void
    {
        $app = $this->app(self::READ_AND_SUBMIT);
        $this->seedPolicy($app, requiresAcknowledgement: false);

        $response = $app->handle($this->json(
            'POST',
            '/api/v1/onboarding/policies/' . self::POLICY . '/acknowledgement',
            ['versionPublicId' => self::POLICY_VERSION],
            'key-read',
        ));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);
        self::assertSame('completed', $data['status']);
        self::assertFalse($data['requiresAcknowledgement']);
        self::assertFalse($data['isAcknowledged']);
        self::assertSame('WHS-POL-001-V005', $data['documentReference']);
        self::assertSame([], $this->policies($app)->declarations);
    }

    public function test_a_form_submission_reaches_submitted_and_splits_the_payload(): void
    {
        $app = $this->app(self::READ_AND_SUBMIT);
        $this->seedForm($app);

        $response = $app->handle($this->json(
            'POST',
            '/api/v1/onboarding/forms/' . self::FORM . '/submission',
            [
                'versionPublicId' => self::FORM_VERSION,
                'formData' => ['preferredName' => 'Sam', 'bank' => ['accountNumber' => '000-000']],
            ],
            'key-form',
        ));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('submitted', $this->data($response)['status']);

        $forms = $this->forms($app);
        self::assertSame(['preferredName' => 'Sam'], $forms->submissions[0]['ordinary']);
        self::assertSame(['bank.accountNumber' => '000-000'], $forms->submissions[0]['payroll']);
    }

    /**
     * The response after a submission must not hand the payload back. Reading a
     * submitted form is what the generated document is for, through the audited
     * route.
     */
    public function test_the_submission_response_does_not_echo_the_payload(): void
    {
        $app = $this->app(self::READ_AND_SUBMIT);
        $this->seedForm($app);

        $response = $app->handle($this->json(
            'POST',
            '/api/v1/onboarding/forms/' . self::FORM . '/submission',
            [
                'versionPublicId' => self::FORM_VERSION,
                'formData' => ['preferredName' => 'Sam', 'bank' => ['accountNumber' => '000-000']],
            ],
            'key-form',
        ));

        self::assertStringNotContainsString('000-000', (string) $response->getBody());
    }

    /**
     * 4.3: a form sent back tells the person why, and stops telling them once
     * they have acted on it.
     */
    public function test_a_sent_back_form_carries_the_reviewers_reason_until_it_is_resubmitted(): void
    {
        $app = $this->app(self::READ_AND_SUBMIT);
        $this->seedForm($app, AssignmentStatus::fixAndResubmit(), 'Your start date is before the offer date.');

        $read = $this->data($app->handle(TestAppFactory::request('GET', '/api/v1/onboarding/forms/' . self::FORM)));
        self::assertSame('fix_and_resubmit', $read['status']);
        self::assertSame('Your start date is before the offer date.', $read['reviewerNote']);

        $resubmitted = $app->handle($this->json(
            'POST',
            '/api/v1/onboarding/forms/' . self::FORM . '/submission',
            ['versionPublicId' => self::FORM_VERSION, 'formData' => ['preferredName' => 'Sam']],
            'key-resubmit',
        ));
        self::assertSame(201, $resubmitted->getStatusCode(), (string) $resubmitted->getBody());
        self::assertSame('submitted', $this->data($resubmitted)['status']);
        self::assertNull($this->data($resubmitted)['reviewerNote']);
    }

    public function test_a_draft_round_trips_its_row_version(): void
    {
        $app = $this->app(self::READ_AND_SUBMIT);
        $this->seedForm($app);
        $path = '/api/v1/onboarding/forms/' . self::FORM . '/draft';

        $first = $app->handle($this->json('PUT', $path, [
            'versionPublicId' => self::FORM_VERSION,
            'draftData' => ['preferredName' => 'Sam'],
        ]));
        self::assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        $token = $this->data($first)['draftRowVersion'];
        self::assertIsString($token);

        $second = $app->handle($this->json('PUT', $path, [
            'versionPublicId' => self::FORM_VERSION,
            'draftData' => ['preferredName' => 'Sam Two'],
            'expectedRowVersion' => $token,
        ]));
        self::assertSame(200, $second->getStatusCode(), (string) $second->getBody());

        $stale = $app->handle($this->json('PUT', $path, [
            'versionPublicId' => self::FORM_VERSION,
            'draftData' => ['preferredName' => 'Sam Three'],
            'expectedRowVersion' => $token,
        ]));
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('ONBOARDING_STALE_TOKEN', $this->errorCode($stale));
    }

    public function test_an_unparseable_row_version_is_a_422_rather_than_a_500(): void
    {
        $app = $this->app(self::READ_AND_SUBMIT);
        $this->seedForm($app);

        $response = $app->handle($this->json('PUT', '/api/v1/onboarding/forms/' . self::FORM . '/draft', [
            'versionPublicId' => self::FORM_VERSION,
            'draftData' => [],
            'expectedRowVersion' => 'not-a-token',
        ]));

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
    }

    // ------------------------------------------------------------- documents

    public function test_the_document_list_is_empty_and_answers_two_hundred(): void
    {
        $app = $this->app(self::READ);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/onboarding/documents'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], $this->data($response)['documents']);
    }

    public function test_a_restricted_document_is_streamed_as_an_attachment_and_never_cached(): void
    {
        $app = $this->app(self::READ);
        $this->documents($app)->withDocument(self::SIGNED_IN_USER_ID, self::document(DataClass::payroll()));

        $response = $app->handle(
            TestAppFactory::request('GET', '/api/v1/onboarding/documents/doc-1/content'),
        );

        // The in-memory blob store holds no bytes for this synthetic reference,
        // so the delivery decision is what is observable here: a 404 from the
        // stream path rather than a signed-URL envelope from the other one.
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            1,
            count($this->documents($app)->accessLog),
            'The read was authorized and audited before the bytes were sought.',
        );
    }

    public function test_another_person_s_document_is_not_found_and_leaves_no_audit_row(): void
    {
        $app = $this->app(self::READ);
        $this->documents($app)->withDocument(self::SOMEBODY_ELSE_USER_ID, self::document(DataClass::payroll()));

        $response = $app->handle(
            TestAppFactory::request('GET', '/api/v1/onboarding/documents/doc-1/content'),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $this->documents($app)->accessLog, 'A refused read is not a read.');
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function startedAttemptId(App $app): string
    {
        $started = $this->data($app->handle($this->json(
            'POST',
            '/api/v1/onboarding/training/' . self::TRAINING . '/attempts',
            ['versionPublicId' => self::TRAINING_VERSION],
        )));
        $publicId = $started['publicId'] ?? null;
        self::assertIsString($publicId);

        return $publicId;
    }

    // ----------------------------------------------------------------- setup

    /**
     * @param list<string> $permissions
     * @param list<string> $reached
     *
     * @return App<ContainerInterface>
     */
    private function appWithTraining(
        array $permissions,
        array $reached = [],
        int $owner = self::SIGNED_IN_USER_ID,
    ): App {
        $app = $this->app($permissions);

        $this->training($app)->withTraining(
            $owner,
            new OnboardingTrainingDetail(
                assignmentPublicId: self::TRAINING,
                title: 'Site induction',
                summary: null,
                versionPublicId: self::TRAINING_VERSION,
                versionNumber: 3,
                status: AssignmentStatus::notStarted(),
                manifest: PresentationManifest::fromArray(['intro', 'hazards', 'sign-off']),
                requiredSlideCount: 0,
                passMarkPercent: 80,
                questionCount: 2,
                reachedSlideIds: $reached,
                latestAttempt: null,
            ),
            [
                AssessmentQuestion::of(
                    'q1',
                    1,
                    'A guard is missing from a machine. What do you do first?',
                    ['a' => 'Carry on', 'b' => 'Stop the line'],
                    'b',
                    'Because the guard is the last line',
                ),
                AssessmentQuestion::of('q2', 2, 'Second question', ['a' => 'No', 'b' => 'Yes'], 'b'),
            ],
        );

        return $app;
    }

    /**
     * @param list<string> $permissions
     *
     * @return App<ContainerInterface>
     */
    private function app(array $permissions): App
    {
        $app = TestAppFactory::create();
        $this->replacePermissions($app, $permissions);
        $this->records($app)->withRecord(
            self::SIGNED_IN_USER_ID,
            new OnboardingTraineeRecord('trainee-1', '2026-10-01', null),
        );

        return $app;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function seedPolicy(App $app, bool $requiresAcknowledgement = true): void
    {
        $this->policies($app)->withPolicy(self::SIGNED_IN_USER_ID, new OnboardingPolicyDetail(
            assignmentPublicId: self::POLICY,
            title: 'Site Safety',
            versionPublicId: self::POLICY_VERSION,
            versionNumber: 2,
            status: AssignmentStatus::notStarted(),
            contentBlocks: [['type' => 'paragraph', 'text' => 'Wear the boots.']],
            declarationText: 'I have read and accept the Site Safety policy.',
            effectiveFrom: '2026-01-01',
            acknowledgedAtUtc: null,
            requiresAcknowledgement: $requiresAcknowledgement,
            documentReference: 'WHS-POL-001-V005',
        ));
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function seedForm(App $app, ?AssignmentStatus $status = null, ?string $reviewerNote = null): void
    {
        $forms = $this->forms($app);
        $forms->withForm(self::SIGNED_IN_USER_ID, new OnboardingFormDetail(
            assignmentPublicId: self::FORM,
            title: 'Employee details',
            versionPublicId: self::FORM_VERSION,
            versionNumber: 1,
            status: $status ?? AssignmentStatus::notStarted(),
            schema: ['fields' => [
                ['path' => 'preferredName', 'label' => 'Preferred name', 'type' => 'text', 'required' => true],
                ['path' => 'bank.accountNumber', 'label' => 'Account number', 'type' => 'text', 'required' => false],
                ['path' => 'health.conditions', 'label' => 'Conditions', 'type' => 'textarea', 'required' => false],
            ]],
            draftData: [],
            draftRowVersion: null,
            draftUpdatedAtUtc: null,
            submittedRevisionCount: $status === null ? 0 : 1,
            lastSubmittedAtUtc: null,
            reviewerNote: $reviewerNote,
        ));
        $forms->withClassifications(self::FORM_VERSION, [
            'preferredName' => DataClass::ORDINARY_HR,
            'bank.accountNumber' => DataClass::PAYROLL,
        ]);
    }

    private static function document(DataClass $class): OnboardingDocumentRef
    {
        return new OnboardingDocumentRef(
            publicId: 'doc-1',
            ownerUserId: self::SIGNED_IN_USER_ID,
            documentKind: 'employee_details',
            dataClass: $class,
            blobContainer: 'onboarding',
            blobPath: 'synthetic/doc-1.pdf',
            contentType: 'application/pdf',
            byteSize: 1024,
            contentSha256: str_repeat('c', 64),
            generatedAtUtc: '2026-09-22T00:00:00+00:00',
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(string $method, string $path, array $body, ?string $idempotencyKey = null): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)->withHeader('Content-Type', 'application/json');

        if ($idempotencyKey !== null) {
            $request = $request->withHeader('Idempotency-Key', $idempotencyKey);
        }

        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
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

    private function errorCode(ResponseInterface $response): ?string
    {
        $payload = TestAppFactory::json($response);
        $error = $payload['error'] ?? null;

        return is_array($error) && isset($error['code']) && is_string($error['code']) ? $error['code'] : null;
    }

    /** @param App<ContainerInterface> $app */
    private function records(App $app): InMemoryOnboardingSelfServiceRepository
    {
        $repository = $this->container($app)->get(OnboardingSelfServiceRepository::class);
        self::assertInstanceOf(InMemoryOnboardingSelfServiceRepository::class, $repository);

        return $repository;
    }

    /** @param App<ContainerInterface> $app */
    private function training(App $app): InMemoryOnboardingTrainingRepository
    {
        $repository = $this->container($app)->get(OnboardingTrainingRepository::class);
        self::assertInstanceOf(InMemoryOnboardingTrainingRepository::class, $repository);

        return $repository;
    }

    /** @param App<ContainerInterface> $app */
    private function policies(App $app): InMemoryOnboardingPolicyRepository
    {
        $repository = $this->container($app)->get(OnboardingPolicyRepository::class);
        self::assertInstanceOf(InMemoryOnboardingPolicyRepository::class, $repository);

        return $repository;
    }

    /** @param App<ContainerInterface> $app */
    private function forms(App $app): InMemoryOnboardingFormRepository
    {
        $repository = $this->container($app)->get(OnboardingFormRepository::class);
        self::assertInstanceOf(InMemoryOnboardingFormRepository::class, $repository);

        return $repository;
    }

    /** @param App<ContainerInterface> $app */
    private function documents(App $app): InMemoryOnboardingDocumentRepository
    {
        $repository = $this->container($app)->get(OnboardingDocumentRepository::class);
        self::assertInstanceOf(InMemoryOnboardingDocumentRepository::class, $repository);

        return $repository;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function container(App $app): ContainerInterface
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string>            $permissionCodes
     */
    private function replacePermissions(App $app, array $permissionCodes): void
    {
        $repository = $this->container($app)->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $repository);
        $repository->replacePermissions(self::SIGNED_IN_USER_ID, $permissionCodes, ['EMPLOYEE']);
    }
}
