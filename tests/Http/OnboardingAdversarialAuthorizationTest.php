<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Identity\Email\DevelopmentGuestOtpEmailSender;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Identity\Persistence\InMemoryEmailOtpChallengeRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\EmailOtpChallengeRepository;
use TriCab\Core\Identity\Contract\GuestOtpEmailSender;
use TriCab\Core\Identity\Contract\UserProvisioningRepository;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\EmailOtpChallenge;
use TriCab\Core\Identity\Domain\Service\GoogleExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\EmailOtpChallengeId;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Onboarding\Application\DTO\Contribution\FormContributionContext;
use TriCab\Core\Onboarding\Application\DTO\OnboardingDocumentRef;
use TriCab\Core\Onboarding\Application\DTO\OnboardingFormDetail;
use TriCab\Core\Onboarding\Application\DTO\OnboardingPolicyDetail;
use TriCab\Core\Onboarding\Application\DTO\OnboardingTraineeRecord;
use TriCab\Core\Onboarding\Application\DTO\OnboardingTrainingDetail;
use TriCab\Core\Onboarding\Contract\OnboardingDocumentRepository;
use TriCab\Core\Onboarding\Contract\OnboardingFormContributionRepository;
use TriCab\Core\Onboarding\Contract\OnboardingFormRepository;
use TriCab\Core\Onboarding\Contract\OnboardingPolicyRepository;
use TriCab\Core\Onboarding\Contract\OnboardingSelfServiceRepository;
use TriCab\Core\Onboarding\Contract\OnboardingTrainingRepository;
use TriCab\Core\Onboarding\Domain\ValueObject\AssessmentQuestion;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;
use TriCab\Core\Onboarding\Domain\ValueObject\PresentationManifest;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingDocumentRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingFormContributionRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingFormRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingPolicyRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingSelfServiceRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingTrainingRepository;

/**
 * Phase 8 item 3 — adversarial authorization over HTTP.
 *
 * The per-feature endpoint tests each hold their own gate. This file attacks the
 * module as a whole, the way somebody with a session and a proxy would:
 *
 *  - **the route table itself** is enumerated, so a route added later is attacked
 *    without anybody remembering to add it here;
 *  - **an emailed-code session is minted for real** (request-otp, verify-otp,
 *    Bearer) rather than simulated, and it holds every Onboarding permission, so
 *    the only thing that can refuse it is the assurance floor (invariant 14);
 *  - **cross-person, guessed and malformed identifiers** are tried on every
 *    route that takes one;
 *  - **the answer key** is looked for in the bytes of every trainee projection
 *    before and after answering (invariant 1);
 *  - **forged score, pass, status and completion fields** are sent on every
 *    write, and the stored state is checked rather than the response alone
 *    (invariants 2 and 7).
 *
 * Development authentication is on by default in TestAppFactory, and signs the
 * request in as user 1 over Microsoft Entra. Tests that need "nobody" or an
 * emailed code turn it off or send a Bearer token, which takes precedence.
 */
final class OnboardingAdversarialAuthorizationTest extends TestCase
{
    private const ME = 1;
    private const SOMEBODY_ELSE = 2;

    private const TRAINING = 'assignment-training';
    private const POLICY = 'assignment-policy';
    private const FORM = 'assignment-form';
    private const DOCUMENT = 'doc-1';

    private const TRAINING_VERSION = 'version-training-3';
    private const POLICY_VERSION = 'version-policy-2';
    private const FORM_VERSION = 'version-form-1';

    private const GUESSED_UUID = '6f1c2d3e-4b5a-4c6d-8e7f-9a0b1c2d3e4f';

    private const SELF_ALL = ['account.login', 'ONBOARDING.ACCESS', 'ONBOARDING.SELF.READ', 'ONBOARDING.SELF.SUBMIT'];

    private const EVERY_ONBOARDING_PERMISSION = [
        'account.login',
        'ONBOARDING.ACCESS',
        'ONBOARDING.SELF.READ',
        'ONBOARDING.SELF.SUBMIT',
        'ONBOARDING.REVIEW',
        'ONBOARDING.PEOPLE.MANAGE',
        'ONBOARDING.CONTENT.MANAGE',
        'ONBOARDING.REPORTS.READ',
        'ONBOARDING.ADMIN',
        'ONBOARDING.COMPLETIONS.READ',
    ];

    /** Placeholders a self-service path may carry: items, never people. */
    private const SELF_SERVICE_PLACEHOLDERS = ['assignmentPublicId', 'attemptPublicId', 'documentPublicId'];

    /** Anything that would betray an answer key on the wire. */
    private const ANSWER_KEY_MARKERS = ['correctOptionKey', 'correctReason', 'isCorrect', 'Because the guard is the last line'];

    // ================================================== the route inventory

    /**
     * Invariant 3 at the route table: no self-service path can name a person,
     * so there is no parameter with which to ask for somebody else's record.
     * Also a tripwire: a new self-service route with a new placeholder fails
     * here until somebody has thought about it.
     */
    public function test_every_self_service_route_identifies_an_item_and_never_a_person(): void
    {
        $routes = $this->selfServiceRoutes(TestAppFactory::create());
        self::assertGreaterThanOrEqual(15, count($routes), 'the self-service table was not found');

        foreach ($routes as [$methods, $pattern]) {
            preg_match_all('/\{([^}:]+)(?::[^}]*)?\}/', $pattern, $matches);

            foreach ($matches[1] as $placeholder) {
                self::assertContains(
                    $placeholder,
                    self::SELF_SERVICE_PLACEHOLDERS,
                    implode('|', $methods) . ' ' . $pattern . ' takes {' . $placeholder . '}',
                );
            }
        }
    }

    /**
     * Invariant 7 at the route table: nothing a trainee can call is named for
     * completing, scoring or deciding.
     */
    public function test_no_self_service_route_is_a_completion_score_or_decision_endpoint(): void
    {
        foreach ($this->selfServiceRoutes(TestAppFactory::create()) as [$methods, $pattern]) {
            self::assertDoesNotMatchRegularExpression(
                '/complet|score|grade|pass|decision|approve|review|status/i',
                $pattern,
                implode('|', $methods) . ' ' . $pattern,
            );
        }
    }

    /**
     * And over the wire: the obvious guesses at a completion endpoint do not
     * exist, and trying them changes nothing.
     */
    public function test_guessed_completion_endpoints_do_not_exist_and_change_nothing(): void
    {
        $app = $this->appWithEverything(self::SELF_ALL);

        foreach ([
            ['POST', '/api/v1/onboarding/training/' . self::TRAINING . '/complete'],
            ['PUT', '/api/v1/onboarding/training/' . self::TRAINING . '/status'],
            ['POST', '/api/v1/onboarding/training/' . self::TRAINING . '/attempts/attempt-0001/score'],
            ['POST', '/api/v1/onboarding/policies/' . self::POLICY . '/complete'],
            ['POST', '/api/v1/onboarding/forms/' . self::FORM . '/complete'],
            ['PUT', '/api/v1/onboarding/forms/' . self::FORM . '/status'],
            ['POST', '/api/v1/onboarding/me/complete'],
            ['PATCH', '/api/v1/onboarding/training/' . self::TRAINING],
            ['PUT', '/api/v1/onboarding/training/' . self::TRAINING],
            ['DELETE', '/api/v1/onboarding/forms/' . self::FORM],
        ] as [$method, $path]) {
            $response = $app->handle($this->json($method, $path, ['status' => 'completed']));

            self::assertContains($response->getStatusCode(), [404, 405], $method . ' ' . $path . ' answered ' . $response->getStatusCode());
        }

        self::assertSame([], $this->training($app)->transitions);
        self::assertSame([], $this->policies($app)->transitions);
        self::assertSame([], $this->forms($app)->transitions);
        self::assertSame('not_started', $this->statusOf($app, 'training', self::TRAINING));
    }

    /**
     * Every administrative route in the table, refused to somebody who holds
     * every self-service permission and nothing else.
     */
    public function test_every_admin_onboarding_route_is_refused_without_its_permission(): void
    {
        $app = $this->app(self::SELF_ALL);
        $routes = $this->administrativeRoutes($app);
        self::assertGreaterThanOrEqual(40, count($routes), 'the administrative table was not found');

        foreach ($routes as [$method, $path]) {
            $response = $app->handle($this->json($method, $path, []));

            self::assertSame(403, $response->getStatusCode(), $method . ' ' . $path);
            self::assertSame('INSUFFICIENT_PERMISSION', $this->code($response), $method . ' ' . $path);
        }
    }

    /**
     * Nobody at all: development authentication off and no Bearer. Every
     * Onboarding route, self-service included, answers 401.
     */
    public function test_every_onboarding_route_is_unauthenticated_without_a_credential(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        foreach ([...$this->administrativeRoutes($app), ...$this->selfServiceRequests($app)] as [$method, $path]) {
            $response = $app->handle($this->json($method, $path, []));

            self::assertSame(401, $response->getStatusCode(), $method . ' ' . $path);
        }
    }

    public function test_a_forged_or_garbage_bearer_token_reaches_nothing(): void
    {
        $app = TestAppFactory::create();
        $forged = implode('.', [
            rtrim(strtr(base64_encode('{"alg":"HS256","typ":"JWT"}'), '+/', '-_'), '='),
            rtrim(strtr(base64_encode('{"iss":"https://api.tricab.local","aud":"tricab-api","sub":"1","auth_provider":"microsoft_entra","exp":4102444800}'), '+/', '-_'), '='),
            'c2lnbmF0dXJl',
        ]);

        foreach (['Bearer ' . $forged, 'Bearer not-a-jwt', 'Bearer ' . str_repeat('A', 4096)] as $header) {
            foreach ([['GET', '/api/v1/onboarding/me'], ['GET', '/api/v1/admin/onboarding/overview']] as [$method, $path]) {
                $response = $app->handle(TestAppFactory::request($method, $path)->withHeader('Authorization', $header));

                // A Bearer header suppresses development authentication, so a
                // bad token must not fall back to the development identity. A
                // token the guest validator does not claim is handed to Entra,
                // which the test environment leaves unconfigured: that answers
                // 501 ENTRA_NOT_CONFIGURED, still a refusal. In production the
                // same token fails Entra validation with 401.
                $status = $response->getStatusCode();
                self::assertContains($status, [401, 501], $path . ' with ' . substr($header, 0, 30) . ' ' . $response->getBody());
                if ($status === 501) {
                    self::assertSame('ENTRA_NOT_CONFIGURED', $this->code($response));
                }
            }
        }
    }

    // ============================================ emailed-code (OTP) sessions

    /**
     * Invariant 14, end to end and across the whole table. The person holds
     * every Onboarding permission and the ADMINISTRATOR role, and signed in with
     * a six-digit code. Every administrative route refuses them on assurance —
     * with the assurance code, so the refusal is the floor and not a missing
     * grant.
     */
    public function test_an_emailed_code_session_holding_every_permission_reaches_no_admin_route(): void
    {
        $app = TestAppFactory::create();
        $token = $this->otpSession($app, 'otp-admin@example.com', self::EVERY_ONBOARDING_PERMISSION, ['GUEST', 'EMPLOYEE', 'ADMINISTRATOR']);

        $routes = $this->administrativeRoutes($app);
        self::assertGreaterThanOrEqual(40, count($routes));

        foreach ($routes as [$method, $path]) {
            $response = $app->handle(
                $this->json($method, $path, [])->withHeader('Authorization', 'Bearer ' . $token),
            );

            self::assertSame(403, $response->getStatusCode(), $method . ' ' . $path . ' ' . $response->getBody());
            self::assertSame('INSUFFICIENT_AUTHENTICATION_ASSURANCE', $this->code($response), $method . ' ' . $path);
        }
    }

    /**
     * The control for the test above, so it cannot pass vacuously: the same
     * permissions over Entra are refused by neither gate on any route.
     */
    public function test_the_same_permissions_over_entra_pass_both_gates_on_every_admin_route(): void
    {
        $app = $this->app(self::EVERY_ONBOARDING_PERMISSION);

        foreach ($this->administrativeRoutes($app) as [$method, $path]) {
            $response = $app->handle($this->json($method, $path, []));
            $code = $response->getStatusCode() >= 400 ? $this->code($response) : null;

            self::assertNotContains($response->getStatusCode(), [401, 500], $method . ' ' . $path . ' ' . $response->getBody());
            self::assertNotSame('INSUFFICIENT_PERMISSION', $code, $method . ' ' . $path);
            self::assertNotSame('INSUFFICIENT_AUTHENTICATION_ASSURANCE', $code, $method . ' ' . $path);
        }
    }

    /**
     * Invariants 14 and 15 from the other side: the emailed code still reaches
     * the person's own onboarding, and only theirs. User 1's item is not found
     * to user 2's OTP session, byte for byte the same as an id nobody holds.
     */
    public function test_an_emailed_code_reaches_its_own_induction_and_not_somebody_elses(): void
    {
        $app = TestAppFactory::create();
        $token = $this->otpSession($app, 'otp-trainee@example.com', self::SELF_ALL, ['GUEST']);
        $otpUserId = $this->userIdFor($app, 'otp-trainee@example.com');
        self::assertNotSame(self::ME, $otpUserId);

        $this->records($app)->withRecord($otpUserId, new OnboardingTraineeRecord('trainee-otp', '2026-10-01', null));
        $this->records($app)->withRecord(self::ME, new OnboardingTraineeRecord('trainee-1', '2026-10-01', null));
        $this->training($app)->withTraining($otpUserId, $this->trainingDetail('otp-own-training'), $this->questions());
        $this->training($app)->withTraining(self::ME, $this->trainingDetail('user-one-training'), $this->questions());

        $bearer = static fn (ServerRequestInterface $r): ServerRequestInterface => $r->withHeader('Authorization', 'Bearer ' . $token);

        $me = $app->handle($bearer(TestAppFactory::request('GET', '/api/v1/onboarding/me')));
        self::assertSame(200, $me->getStatusCode(), (string) $me->getBody());

        $own = $app->handle($bearer(TestAppFactory::request('GET', '/api/v1/onboarding/training/otp-own-training')));
        self::assertSame(200, $own->getStatusCode(), (string) $own->getBody());

        $theirs = $app->handle($bearer(TestAppFactory::request('GET', '/api/v1/onboarding/training/user-one-training')));
        $nobody = $app->handle($bearer(TestAppFactory::request('GET', '/api/v1/onboarding/training/' . self::GUESSED_UUID)));
        self::assertSame(404, $theirs->getStatusCode());
        self::assertSame((string) $nobody->getBody(), (string) $theirs->getBody());

        // And the write side with the OTP session: starting user 1's attempt.
        $write = $app->handle($bearer($this->json('POST', '/api/v1/onboarding/training/user-one-training/attempts', [
            'versionPublicId' => self::TRAINING_VERSION,
        ])));
        self::assertSame(404, $write->getStatusCode());
        self::assertSame([], $this->training($app)->transitions);

        // The reverse: user 1 over Entra does not see the OTP user's item.
        $reverse = $app->handle(TestAppFactory::request('GET', '/api/v1/onboarding/training/otp-own-training'));
        self::assertSame(404, $reverse->getStatusCode());
    }

    public function test_an_expired_code_is_refused_even_though_it_is_the_right_code(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);
        [$challengeId, $otp] = $this->requestOtp($app, 'expired@example.com', ['account.login'], ['GUEST']);

        $this->expireChallenge($app, $challengeId);

        $response = $app->handle($this->json('POST', '/api/v1/auth/guest/verify-otp', [
            'challengeId' => $challengeId,
            'email' => 'expired@example.com',
            'otp' => $otp,
        ]));

        self::assertSame(401, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringNotContainsString('accessToken', (string) $response->getBody());
    }

    /**
     * The existing guest test makes five wrong guesses and checks each one is
     * refused. This checks what that is for: after the limit, the right code
     * no longer works either, so a sixth guess that happens to be right mints
     * nothing.
     */
    public function test_the_right_code_is_refused_once_the_attempt_limit_is_spent(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);
        [$challengeId, $otp] = $this->requestOtp($app, 'limit@example.com', ['account.login'], ['GUEST']);
        $wrong = $otp === '000000' ? '111111' : '000000';

        for ($i = 0; $i < EmailOtpChallenge::DEFAULT_MAXIMUM_ATTEMPTS; ++$i) {
            $refused = $app->handle($this->json('POST', '/api/v1/auth/guest/verify-otp', [
                'challengeId' => $challengeId,
                'email' => 'limit@example.com',
                'otp' => $wrong,
            ]));
            self::assertContains($refused->getStatusCode(), [401, 429], (string) $refused->getBody());
        }

        $right = $app->handle($this->json('POST', '/api/v1/auth/guest/verify-otp', [
            'challengeId' => $challengeId,
            'email' => 'limit@example.com',
            'otp' => $otp,
        ]));

        self::assertContains($right->getStatusCode(), [401, 429], (string) $right->getBody());
        self::assertStringNotContainsString('accessToken', (string) $right->getBody());
    }

    /**
     * Replays the existing test does not try: a used code against the same
     * challenge; a live code presented for a different address; and a code
     * from a challenge that a newer request has superseded.
     */
    public function test_a_code_cannot_be_replayed_transplanted_or_used_after_it_is_superseded(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        [$usedChallenge, $usedOtp] = $this->requestOtp($app, 'replay@example.com', ['account.login'], ['GUEST']);
        $first = $app->handle($this->json('POST', '/api/v1/auth/guest/verify-otp', ['challengeId' => $usedChallenge, 'email' => 'replay@example.com', 'otp' => $usedOtp]));
        self::assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        $replay = $app->handle($this->json('POST', '/api/v1/auth/guest/verify-otp', ['challengeId' => $usedChallenge, 'email' => 'replay@example.com', 'otp' => $usedOtp]));
        self::assertSame(401, $replay->getStatusCode());
        self::assertStringNotContainsString('accessToken', (string) $replay->getBody());

        // Somebody else's live code, presented as mine.
        [$victimChallenge, $victimOtp] = $this->requestOtp($app, 'victim@example.com', ['account.login'], ['GUEST']);
        $this->seedUser($app, 'attacker@example.com', ['account.login'], ['GUEST']);
        foreach ([
            ['challengeId' => $victimChallenge, 'email' => 'attacker@example.com', 'otp' => $victimOtp],
            ['email' => 'attacker@example.com', 'otp' => $victimOtp],
        ] as $body) {
            $transplanted = $app->handle($this->json('POST', '/api/v1/auth/guest/verify-otp', $body));
            // 401 with a challenge id; 422 without one (the id is required).
            self::assertContains($transplanted->getStatusCode(), [401, 422], json_encode($body, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('accessToken', (string) $transplanted->getBody());
        }

        // A superseded code: a second request invalidates the first.
        [$oldChallenge, $oldOtp] = $this->requestOtp($app, 'superseded@example.com', ['account.login'], ['GUEST']);
        [$newChallenge] = $this->requestOtp($app, 'superseded@example.com', ['account.login'], ['GUEST']);
        self::assertNotSame($oldChallenge, $newChallenge);
        $stale = $app->handle($this->json('POST', '/api/v1/auth/guest/verify-otp', ['challengeId' => $oldChallenge, 'email' => 'superseded@example.com', 'otp' => $oldOtp]));
        self::assertSame(401, $stale->getStatusCode(), (string) $stale->getBody());
        self::assertStringNotContainsString('accessToken', (string) $stale->getBody());
    }

    public function test_a_revoked_guest_session_reaches_no_onboarding_route(): void
    {
        $app = TestAppFactory::create();
        $token = $this->otpSession($app, 'revoked@example.com', self::SELF_ALL, ['GUEST']);

        $before = $app->handle(TestAppFactory::request('GET', '/api/v1/onboarding/me')->withHeader('Authorization', 'Bearer ' . $token));
        self::assertSame(200, $before->getStatusCode(), (string) $before->getBody());

        $logout = $app->handle(TestAppFactory::request('POST', '/api/v1/auth/guest/logout')->withHeader('Authorization', 'Bearer ' . $token));
        self::assertSame(200, $logout->getStatusCode());

        foreach ($this->selfServiceRequests($app) as [$method, $path]) {
            $after = $app->handle($this->json($method, $path, [])->withHeader('Authorization', 'Bearer ' . $token));
            self::assertSame(401, $after->getStatusCode(), $method . ' ' . $path);
        }
    }

    // =============================================== cross-person (self)

    /**
     * Invariant 4 on every self-service route, reads and writes: somebody
     * else's training, policy, form and document all answer exactly as an id
     * nobody holds, and nothing is written against them.
     */
    public function test_every_self_service_route_answers_another_persons_item_as_not_found(): void
    {
        $app = $this->appWithEverything(self::SELF_ALL, owner: self::SOMEBODY_ELSE);

        foreach ($this->itemRequests() as [$method, $path, $body]) {
            $theirs = $app->handle($this->json($method, $path, $body, 'key-cross-' . md5($method . $path)));
            $nobody = $app->handle($this->json(
                $method,
                str_replace([self::TRAINING, self::POLICY, self::FORM, self::DOCUMENT], self::GUESSED_UUID, $path),
                $body,
                'key-nobody-' . md5($method . $path),
            ));

            self::assertSame(404, $theirs->getStatusCode(), $method . ' ' . $path . ' ' . $theirs->getBody());
            self::assertSame('ONBOARDING_NOT_FOUND', $this->code($theirs), $method . ' ' . $path);
            self::assertSame((string) $nobody->getBody(), (string) $theirs->getBody(), $method . ' ' . $path . ' is distinguishable');
        }

        self::assertSame([], $this->training($app)->transitions);
        self::assertSame([], $this->policies($app)->declarations);
        self::assertSame([], $this->policies($app)->transitions);
        self::assertSame([], $this->forms($app)->submissions);
        self::assertSame([], $this->forms($app)->transitions);
        self::assertSame([], $this->documents($app)->accessLog);
    }

    /**
     * My assignment, somebody else's attempt id. The attempt is checked against
     * the session's user, not merely against the assignment in the path.
     */
    public function test_another_persons_attempt_cannot_be_submitted_through_my_assignment(): void
    {
        $app = $this->appWithEverything(self::SELF_ALL, reached: ['intro', 'hazards', 'sign-off']);
        $this->training($app)->withTraining(self::SOMEBODY_ELSE, $this->trainingDetail('their-training', ['intro', 'hazards', 'sign-off']), $this->questions());
        $theirAttempt = $this->training($app)->startAttempt(self::SOMEBODY_ELSE, 'their-training', self::TRAINING_VERSION, null);

        foreach ([self::TRAINING, 'their-training'] as $assignment) {
            $response = $app->handle($this->json(
                'POST',
                '/api/v1/onboarding/training/' . $assignment . '/attempts/' . $theirAttempt->publicId,
                ['versionPublicId' => self::TRAINING_VERSION, 'answers' => ['q1' => 'b', 'q2' => 'b']],
                'key-their-attempt-' . $assignment,
            ));

            self::assertSame(404, $response->getStatusCode(), $assignment . ' ' . $response->getBody());
            self::assertSame('ONBOARDING_NOT_FOUND', $this->code($response));
        }

        $stillOpen = $this->training($app)->findAttempt(self::SOMEBODY_ELSE, 'their-training', $theirAttempt->publicId);
        self::assertNotNull($stillOpen);
        self::assertFalse($stillOpen->isSubmitted(), 'their attempt was submitted by somebody else');
    }

    /**
     * Invariant 3 against the body: a subject named in the request is not read.
     * The write lands on the session's own record whatever the body says.
     */
    public function test_a_subject_named_in_the_body_is_ignored(): void
    {
        $app = $this->appWithEverything(self::SELF_ALL);
        $this->policies($app)->withPolicy(self::SOMEBODY_ELSE, $this->policyDetail('their-policy'));

        $response = $app->handle($this->json(
            'POST',
            '/api/v1/onboarding/policies/' . self::POLICY . '/acknowledgement',
            [
                'versionPublicId' => self::POLICY_VERSION,
                'userId' => self::SOMEBODY_ELSE,
                'subjectUserId' => self::SOMEBODY_ELSE,
                'forUser' => self::SOMEBODY_ELSE,
                'assignmentPublicId' => 'their-policy',
            ],
            'key-subject',
        ));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(self::POLICY, $this->data($response)['assignmentPublicId'] ?? null);

        $theirs = $this->policies($app)->findPolicy(self::SOMEBODY_ELSE, 'their-policy');
        self::assertNotNull($theirs);
        self::assertSame('not_started', $theirs->status->toString(), 'their policy moved');
        self::assertNull($theirs->acknowledgedAtUtc);
    }

    /**
     * A named manager is limited to their own starters (covered elsewhere); this
     * is the other edge — a starter recorded as their own manager, and a
     * reviewer who is the starter, cannot fill the other parts of their form.
     */
    public function test_nobody_fills_the_hr_or_manager_part_of_their_own_form(): void
    {
        $app = $this->app(['account.login', 'ONBOARDING.SELF.READ', 'ONBOARDING.REVIEW']);
        $contributions = $this->contributions($app);
        $contributions->withForm(new FormContributionContext(9, self::GUESSED_UUID, self::ME, self::ME, 'Induction checklist', 'hr', AssignmentStatus::inProgress(), ['fields' => [
            ['path' => 'siteTour', 'label' => 'Tour of site done', 'type' => 'checkbox', 'required' => true, 'filledBy' => 'hr'],
            ['path' => 'managerSignOff', 'label' => 'Manager sign-off', 'type' => 'checkbox', 'required' => true, 'filledBy' => 'manager'],
        ]]));

        $manager = $app->handle($this->json('PUT', '/api/v1/onboarding/team/forms/' . self::GUESSED_UUID . '/contribution', ['answers' => ['managerSignOff' => true]]));
        self::assertSame(403, $manager->getStatusCode(), (string) $manager->getBody());
        self::assertSame('ONBOARDING_SELF_REVIEW_NOT_PERMITTED', $this->code($manager));

        $hr = $app->handle($this->json('PUT', '/api/v1/admin/onboarding/queue/' . self::GUESSED_UUID . '/contribution', ['answers' => ['siteTour' => true]]));
        self::assertSame(403, $hr->getStatusCode(), (string) $hr->getBody());
        self::assertSame('ONBOARDING_SELF_REVIEW_NOT_PERMITTED', $this->code($hr));

        self::assertSame([], $contributions->rows);
    }

    // ============================================ missing and malformed ids

    /**
     * Guessed and hostile identifiers on every self-service route that takes
     * one. Each is not found — never a 500, never a 200, never a different
     * answer that would tell a prober the shape of a real id.
     */
    public function test_guessed_and_malformed_ids_are_not_found_on_every_self_service_route(): void
    {
        $app = $this->appWithEverything(self::SELF_ALL);

        foreach (self::hostileIds() as $id) {
            $encoded = rawurlencode($id);
            foreach ($this->itemRequests() as [$method, $path, $body]) {
                $probe = str_replace([self::TRAINING, self::POLICY, self::FORM, self::DOCUMENT], $encoded, $path);
                $response = $app->handle($this->json($method, $probe, $body, 'key-' . md5($method . $probe)));

                self::assertSame(404, $response->getStatusCode(), $method . ' ' . $probe . ' ' . $response->getBody());
            }

            $attempt = $app->handle($this->json(
                'POST',
                '/api/v1/onboarding/training/' . self::TRAINING . '/attempts/' . $encoded,
                ['versionPublicId' => self::TRAINING_VERSION, 'answers' => ['q1' => 'b']],
                'key-attempt-' . md5($id),
            ));
            self::assertSame(404, $attempt->getStatusCode(), 'attempt ' . $id . ' ' . $attempt->getBody());

            foreach (['GET' => '/api/v1/onboarding/team/forms/' . $encoded, 'PUT' => '/api/v1/onboarding/team/forms/' . $encoded . '/contribution'] as $method => $path) {
                $team = $app->handle($this->json($method, $path, ['answers' => ['x' => true]]));
                self::assertSame(404, $team->getStatusCode(), $method . ' ' . $path . ' ' . $team->getBody());
            }
        }

        self::assertSame([], $this->training($app)->transitions);
        self::assertSame([], $this->documents($app)->accessLog);
    }

    /**
     * The same on every administrative route with an identifier, as somebody
     * holding every permission over Entra. Reads are 404; a write may refuse
     * its body first (422), but nothing answers 2xx or 500.
     */
    public function test_guessed_and_malformed_ids_are_not_found_on_every_admin_route(): void
    {
        $app = $this->app(self::EVERY_ONBOARDING_PERMISSION);

        foreach (self::hostileIds() as $id) {
            foreach ($this->administrativeRoutes($app, rawurlencode($id)) as [$method, $path, $hasId]) {
                if (!$hasId) {
                    continue;
                }

                $response = $app->handle($this->json($method, $path, []));
                $status = $response->getStatusCode();

                if ($method === 'GET') {
                    self::assertSame(404, $status, $method . ' ' . $path . ' ' . $response->getBody());
                } else {
                    self::assertContains($status, [404, 422], $method . ' ' . $path . ' ' . $response->getBody());
                }
            }
        }
    }

    // ============================================================= answer key

    /**
     * Invariant 1 across every trainee projection before an answer is
     * recorded: the summary, the item, the started attempt, the same start
     * replayed, and the item again with an attempt open.
     */
    public function test_no_trainee_projection_carries_the_answer_key_before_answering(): void
    {
        $app = $this->appWithEverything(self::SELF_ALL, reached: ['intro', 'hazards', 'sign-off']);
        $this->records($app)->withAssignments(self::ME, []);

        $bodies = [];
        $bodies['summary'] = (string) $app->handle(TestAppFactory::request('GET', '/api/v1/onboarding/me'))->getBody();
        $bodies['item before'] = (string) $app->handle(TestAppFactory::request('GET', '/api/v1/onboarding/training/' . self::TRAINING))->getBody();

        $start = $app->handle($this->json('POST', '/api/v1/onboarding/training/' . self::TRAINING . '/attempts', ['versionPublicId' => self::TRAINING_VERSION]));
        self::assertSame(201, $start->getStatusCode(), (string) $start->getBody());
        $bodies['start'] = (string) $start->getBody();
        self::assertStringContainsString('A guard is missing', $bodies['start'], 'the questions are there, without their answers');

        $bodies['start again'] = (string) $app->handle($this->json('POST', '/api/v1/onboarding/training/' . self::TRAINING . '/attempts', ['versionPublicId' => self::TRAINING_VERSION]))->getBody();
        $bodies['item with attempt open'] = (string) $app->handle(TestAppFactory::request('GET', '/api/v1/onboarding/training/' . self::TRAINING))->getBody();

        foreach ($bodies as $label => $body) {
            foreach (self::ANSWER_KEY_MARKERS as $marker) {
                self::assertStringNotContainsString($marker, $body, $label . ' carried ' . $marker);
            }
        }
    }

    /**
     * After an answer is recorded the submission response may reveal it (by
     * design), but nothing else does: the item page, the summary, and the next
     * attempt's questions stay redacted, and a replay of the submission with
     * the same key reveals nothing the first response did not.
     */
    public function test_after_answering_only_the_recorded_attempt_reveals_the_key(): void
    {
        $app = $this->appWithEverything(self::SELF_ALL, reached: ['intro', 'hazards', 'sign-off']);
        $attempt = $this->startAttempt($app);

        $submitted = $app->handle($this->json('POST', '/api/v1/onboarding/training/' . self::TRAINING . '/attempts/' . $attempt, [
            'versionPublicId' => self::TRAINING_VERSION,
            'answers' => ['q1' => 'a', 'q2' => 'a'],
        ], 'key-after'));
        self::assertSame(200, $submitted->getStatusCode(), (string) $submitted->getBody());
        self::assertFalse($this->data($submitted)['isPass'] ?? null);
        self::assertStringContainsString('isCorrect', (string) $submitted->getBody(), 'a failed attempt says which answers were wrong');
        self::assertStringNotContainsString('correctOptionKey', (string) $submitted->getBody(), 'but never which option is right');
        self::assertStringNotContainsString('correctReason', (string) $submitted->getBody());

        $replay = $app->handle($this->json('POST', '/api/v1/onboarding/training/' . self::TRAINING . '/attempts/' . $attempt, [
            'versionPublicId' => self::TRAINING_VERSION,
            'answers' => ['q1' => 'a', 'q2' => 'a'],
        ], 'key-after'));
        self::assertSame(200, $replay->getStatusCode(), (string) $replay->getBody());
        self::assertSame($this->data($submitted)['questions'] ?? null, $this->data($replay)['questions'] ?? null);

        $bodies = [
            'item after' => (string) $app->handle(TestAppFactory::request('GET', '/api/v1/onboarding/training/' . self::TRAINING))->getBody(),
            'summary after' => (string) $app->handle(TestAppFactory::request('GET', '/api/v1/onboarding/me'))->getBody(),
        ];
        $next = $app->handle($this->json('POST', '/api/v1/onboarding/training/' . self::TRAINING . '/attempts', ['versionPublicId' => self::TRAINING_VERSION]));
        self::assertSame(201, $next->getStatusCode(), (string) $next->getBody());
        $bodies['next attempt'] = (string) $next->getBody();

        foreach ($bodies as $label => $body) {
            foreach (self::ANSWER_KEY_MARKERS as $marker) {
                self::assertStringNotContainsString($marker, $body, $label . ' carried ' . $marker);
            }
        }
    }

    /**
     * Answer-key harvesting through a blank attempt — found in Phase 8 and
     * closed on the owner's decision of 25 Sep 2026.
     *
     * A re-sit is offered straight away and draws the same questions, so a key
     * shown after a failed attempt was a key for the next one: submit blank,
     * copy, re-sit, pass. A failed attempt now carries only what was chosen and
     * whether it was right; the key and its reasons come with a pass.
     */
    public function test_a_failed_attempt_withholds_the_key_and_only_a_pass_reveals_it(): void
    {
        $app = $this->appWithEverything(self::SELF_ALL, reached: ['intro', 'hazards', 'sign-off']);

        $blank = $app->handle($this->json('POST', '/api/v1/onboarding/training/' . self::TRAINING . '/attempts/' . $this->startAttempt($app), [
            'versionPublicId' => self::TRAINING_VERSION,
            'answers' => [],
        ], 'key-blank'));
        self::assertSame(200, $blank->getStatusCode(), (string) $blank->getBody());
        self::assertFalse($this->data($blank)['isPass'] ?? null);
        foreach (self::rows($this->data($blank)['questions'] ?? null) as $question) {
            self::assertNull($question['selectedOptionKey'] ?? null, 'nothing was answered');
            self::assertFalse($question['isCorrect'] ?? null);
            self::assertArrayNotHasKey('correctOptionKey', $question, 'a failed attempt names no right answer');
            self::assertArrayNotHasKey('correctReason', $question);
        }
        self::assertSame('in_progress', $this->statusOf($app, 'training', self::TRAINING));

        $passed = $app->handle($this->json('POST', '/api/v1/onboarding/training/' . self::TRAINING . '/attempts/' . $this->startAttempt($app), [
            'versionPublicId' => self::TRAINING_VERSION,
            'answers' => ['q1' => 'b', 'q2' => 'b'],
        ], 'key-pass'));
        self::assertSame(200, $passed->getStatusCode(), (string) $passed->getBody());
        self::assertTrue($this->data($passed)['isPass'] ?? null);
        foreach (self::rows($this->data($passed)['questions'] ?? null) as $question) {
            self::assertSame('b', $question['correctOptionKey'] ?? null, 'a pass shows the key, as the source did');
        }
    }

    // ================================================= forged score / status

    /**
     * Invariants 2 and 7 on every self-service write. Each body carries every
     * field a client might hope the server reads — score, pass, status,
     * completion, a looser pass mark, a rewritten answer key — and the stored
     * state is what the server decides.
     */
    public function test_forged_score_pass_status_and_completion_fields_are_ignored_on_every_write(): void
    {
        $app = $this->appWithEverything(self::SELF_ALL);
        $forged = [
            'status' => 'completed',
            'statusLabel' => 'Completed',
            'completed' => true,
            'isComplete' => true,
            'completedAtUtc' => '2026-01-01T00:00:00Z',
            'presentationComplete' => true,
            'reachedFinalSlide' => true,
            'scorePercent' => 100,
            'isPass' => true,
            'correctCount' => 99,
            'passMarkPercent' => 0,
            'reviewerNote' => null,
            'reviewedByUserId' => self::SOMEBODY_ELSE,
        ];

        // Progress: one slide of three, claiming the whole deck and completion.
        $progress = $app->handle($this->json('POST', '/api/v1/onboarding/training/' . self::TRAINING . '/progress', [
            ...$forged,
            'versionPublicId' => self::TRAINING_VERSION,
            'slideIds' => ['intro'],
        ]));
        self::assertSame(200, $progress->getStatusCode(), (string) $progress->getBody());
        self::assertSame('in_progress', $this->data($progress)['status']);
        self::assertFalse($this->data($progress)['presentationComplete']);
        self::assertSame(['intro'], $this->data($progress)['reachedSlideIds']);

        // Starting an attempt before the deck is finished is refused whatever
        // the body claims about the presentation.
        $early = $app->handle($this->json('POST', '/api/v1/onboarding/training/' . self::TRAINING . '/attempts', [
            ...$forged,
            'versionPublicId' => self::TRAINING_VERSION,
        ]));
        self::assertSame(409, $early->getStatusCode(), (string) $early->getBody());
        self::assertSame('ONBOARDING_ASSESSMENT_NOT_AVAILABLE', $this->code($early));

        // Finish the deck honestly, then submit wrong answers with a forged
        // grade and a forged answer key that would make them right.
        $app->handle($this->json('POST', '/api/v1/onboarding/training/' . self::TRAINING . '/progress', [
            'versionPublicId' => self::TRAINING_VERSION,
            'slideIds' => ['intro', 'hazards', 'sign-off'],
        ]));
        self::assertNotSame('completed', $this->statusOf($app, 'training', self::TRAINING), 'finishing the slides completed a module that has an assessment');

        $attempt = $this->startAttempt($app, $forged);
        $graded = $app->handle($this->json('POST', '/api/v1/onboarding/training/' . self::TRAINING . '/attempts/' . $attempt, [
            ...$forged,
            'versionPublicId' => self::TRAINING_VERSION,
            'answers' => ['q1' => 'a', 'q2' => 'a'],
            'questions' => [
                ['publicId' => 'q1', 'correctOptionKey' => 'a'],
                ['publicId' => 'q2', 'correctOptionKey' => 'a'],
            ],
            'answerKey' => ['q1' => 'a', 'q2' => 'a'],
        ], 'key-forged-all'));
        self::assertSame(200, $graded->getStatusCode(), (string) $graded->getBody());
        self::assertSame(0, $this->data($graded)['scorePercent']);
        self::assertFalse($this->data($graded)['isPass']);
        self::assertSame('in_progress', $this->statusOf($app, 'training', self::TRAINING));
        foreach ($this->training($app)->transitions as $transition) {
            self::assertNotSame('completed', $transition->to->toString(), 'a forged field completed the module');
        }

        // Form draft and submission: the furthest a trainee takes a form is
        // `submitted`; a reviewer decides the rest.
        $draft = $app->handle($this->json('PUT', '/api/v1/onboarding/forms/' . self::FORM . '/draft', [
            ...$forged,
            'versionPublicId' => self::FORM_VERSION,
            'draftData' => ['preferredName' => 'Sam'],
        ]));
        self::assertSame(200, $draft->getStatusCode(), (string) $draft->getBody());
        self::assertNotSame('completed', $this->data($draft)['status'] ?? null);

        $submission = $app->handle($this->json('POST', '/api/v1/onboarding/forms/' . self::FORM . '/submission', [
            ...$forged,
            'versionPublicId' => self::FORM_VERSION,
            'formData' => ['preferredName' => 'Sam'],
        ], 'key-forged-form'));
        self::assertSame(201, $submission->getStatusCode(), (string) $submission->getBody());
        self::assertSame('submitted', $this->data($submission)['status']);
        self::assertSame('submitted', $this->statusOf($app, 'form', self::FORM));
        foreach ($this->forms($app)->transitions as $transition) {
            self::assertNotSame('completed', $transition->to->toString(), 'a trainee completed their own form');
        }
    }

    /**
     * A form a reviewer has completed cannot be reopened or re-marked by the
     * trainee: a new submission and a draft save are both refused (409), and the
     * decided evidence stands.
     */
    public function test_a_trainee_cannot_move_a_decided_form(): void
    {
        $app = $this->appWithEverything(self::SELF_ALL, formStatus: AssignmentStatus::completed());

        $submission = $app->handle($this->json('POST', '/api/v1/onboarding/forms/' . self::FORM . '/submission', [
            'versionPublicId' => self::FORM_VERSION,
            'formData' => ['preferredName' => 'Changed'],
            'status' => 'in_progress',
        ], 'key-decided'));
        self::assertSame(409, $submission->getStatusCode(), (string) $submission->getBody());
        self::assertContains($this->code($submission), ['ONBOARDING_ITEM_IS_FINAL', 'ONBOARDING_ALREADY_RECORDED']);

        $draft = $app->handle($this->json('PUT', '/api/v1/onboarding/forms/' . self::FORM . '/draft', [
            'versionPublicId' => self::FORM_VERSION,
            'draftData' => ['preferredName' => 'Changed'],
            'status' => 'in_progress',
        ]));
        self::assertSame(409, $draft->getStatusCode(), (string) $draft->getBody());
        self::assertSame('ONBOARDING_ALREADY_RECORDED', $this->code($draft));

        self::assertSame('completed', $this->statusOf($app, 'form', self::FORM));
        self::assertSame([], $this->forms($app)->submissions);
        self::assertSame([], $this->forms($app)->transitions);
    }

    // =================================================================== setup

    /**
     * @return list<string>
     */
    private static function hostileIds(): array
    {
        return [
            self::GUESSED_UUID,
            '00000000-0000-0000-0000-000000000000',
            'not-a-uuid',
            "' OR '1'='1",
            '1; DROP TABLE onboarding.Assignments;--',
            '<script>alert(1)</script>',
            '../../admin/onboarding/overview',
            '%00',
            'ＡＢＣ-ünïcödé',
            str_repeat('a', 4000),
            '1',
            '-1',
        ];
    }

    /**
     * Every self-service request that names an item, with a body good enough to
     * get past parsing so the ownership check is what answers.
     *
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function itemRequests(): array
    {
        return [
            ['GET', '/api/v1/onboarding/training/' . self::TRAINING, []],
            ['GET', '/api/v1/onboarding/policies/' . self::POLICY, []],
            ['GET', '/api/v1/onboarding/forms/' . self::FORM, []],
            ['GET', '/api/v1/onboarding/documents/' . self::DOCUMENT . '/content', []],
            ['POST', '/api/v1/onboarding/training/' . self::TRAINING . '/progress', ['versionPublicId' => self::TRAINING_VERSION, 'slideIds' => ['intro']]],
            ['POST', '/api/v1/onboarding/training/' . self::TRAINING . '/attempts', ['versionPublicId' => self::TRAINING_VERSION]],
            ['POST', '/api/v1/onboarding/training/' . self::TRAINING . '/attempts/attempt-0001', ['versionPublicId' => self::TRAINING_VERSION, 'answers' => ['q1' => 'b', 'q2' => 'b']]],
            ['POST', '/api/v1/onboarding/policies/' . self::POLICY . '/acknowledgement', ['versionPublicId' => self::POLICY_VERSION]],
            ['PUT', '/api/v1/onboarding/forms/' . self::FORM . '/draft', ['versionPublicId' => self::FORM_VERSION, 'draftData' => ['preferredName' => 'Sam']]],
            ['POST', '/api/v1/onboarding/forms/' . self::FORM . '/submission', ['versionPublicId' => self::FORM_VERSION, 'formData' => ['preferredName' => 'Sam']]],
        ];
    }

    /**
     * @param App<ContainerInterface> $app
     *
     * @return list<array{0: list<string>, 1: string}>
     */
    private function selfServiceRoutes(App $app): array
    {
        $routes = [];
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            if (str_starts_with($route->getPattern(), '/api/v1/onboarding')) {
                $routes[] = [array_values($route->getMethods()), $route->getPattern()];
            }
        }

        return $routes;
    }

    /**
     * @param App<ContainerInterface> $app
     *
     * @return list<array{0: string, 1: string}>
     */
    private function selfServiceRequests(App $app): array
    {
        $requests = [];
        foreach ($this->selfServiceRoutes($app) as [$methods, $pattern]) {
            foreach ($methods as $method) {
                $requests[] = [$method, (string) preg_replace('/\{[^}]+\}/', self::GUESSED_UUID, $pattern)];
            }
        }

        return $requests;
    }

    /**
     * Every route under the HR and integration prefixes, with each placeholder
     * filled in.
     *
     * @param App<ContainerInterface> $app
     *
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    private function administrativeRoutes(App $app, string $id = self::GUESSED_UUID): array
    {
        $routes = [];
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            $pattern = $route->getPattern();
            if (!str_starts_with($pattern, '/api/v1/admin/onboarding') && !str_starts_with($pattern, '/api/v1/integrations/onboarding')) {
                continue;
            }

            foreach ($route->getMethods() as $method) {
                $routes[] = [$method, (string) preg_replace('/\{[^}]+\}/', $id, $pattern), str_contains($pattern, '{')];
            }
        }

        return $routes;
    }

    /**
     * Mints a real emailed-code session: seeds a person with the given grants,
     * requests a code, reads it from the development mailbox, verifies it, and
     * returns the Bearer token.
     *
     * @param list<string> $permissions
     * @param list<string> $roles
     *
     * @param App<ContainerInterface> $app
     */
    private function otpSession(App $app, string $email, array $permissions, array $roles): string
    {
        [$challengeId, $otp] = $this->requestOtp($app, $email, $permissions, $roles);

        $verify = $app->handle($this->json('POST', '/api/v1/auth/guest/verify-otp', [
            'challengeId' => $challengeId,
            'email' => $email,
            'otp' => $otp,
        ]));
        self::assertSame(200, $verify->getStatusCode(), (string) $verify->getBody());
        $token = $this->data($verify)['accessToken'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string>            $permissions
     * @param list<string>            $roles
     *
     * @return array{0: string, 1: string}
     */
    private function requestOtp(App $app, string $email, array $permissions, array $roles): array
    {
        $this->seedUser($app, $email, $permissions, $roles);

        $response = $app->handle($this->json('POST', '/api/v1/auth/guest/request-otp', ['email' => $email]));
        self::assertSame(202, $response->getStatusCode(), (string) $response->getBody());
        $challengeId = $this->data($response)['challengeId'] ?? null;
        self::assertIsString($challengeId);

        $sender = $this->container($app)->get(GuestOtpEmailSender::class);
        self::assertInstanceOf(DevelopmentGuestOtpEmailSender::class, $sender);
        $last = $sender->lastMessage();
        self::assertNotNull($last);
        self::assertSame(strtolower($email), $last['to']);

        return [$challengeId, $last['otpCode']];
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string>            $permissions
     * @param list<string>            $roles
     */
    private function seedUser(App $app, string $email, array $permissions, array $roles): void
    {
        $users = $this->container($app)->get(UserProvisioningRepository::class);
        self::assertInstanceOf(UserProvisioningRepository::class, $users);
        $address = EmailAddress::fromString($email);

        if ($users->findByEmail($address) !== null) {
            return;
        }

        $users->register(
            emailAddress: $address,
            displayName: 'Adversary ' . $email,
            status: UserStatus::active(),
            roles: array_map(static fn (string $r): RoleCode => RoleCode::fromString($r), $roles),
            permissions: array_map(static fn (string $p): PermissionCode => PermissionCode::fromString($p), $permissions),
            identity: GoogleExternalIdentityFactory::forSubject('seed-' . $email),
        );
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function userIdFor(App $app, string $email): int
    {
        $users = $this->container($app)->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $user = $users->findByEmail(EmailAddress::fromString($email));
        self::assertNotNull($user);

        return $user->id()->toInt();
    }

    /**
     * Rewrites a stored challenge so that it expired a minute ago, leaving its
     * hash, attempt count and identity alone — the code is still the right
     * code, only late.
     *
     * @param App<ContainerInterface> $app
     */
    private function expireChallenge(App $app, string $challengePublicId): void
    {
        $repository = $this->container($app)->get(EmailOtpChallengeRepository::class);
        self::assertInstanceOf(InMemoryEmailOtpChallengeRepository::class, $repository);

        foreach ($repository->all() as $challenge) {
            if ($challenge->publicId()->toString() !== $challengePublicId) {
                continue;
            }

            $id = $challenge->id();
            self::assertInstanceOf(EmailOtpChallengeId::class, $id);
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $repository->save(EmailOtpChallenge::reconstitute(
                id: $id,
                publicId: $challenge->publicId(),
                emailAddress: $challenge->emailAddress(),
                otpHash: $challenge->otpHash(),
                purpose: $challenge->purpose(),
                expiresAtUtc: $now->modify('-1 minute'),
                attemptCount: $challenge->attemptCount(),
                maximumAttempts: $challenge->maximumAttempts(),
                consumedAtUtc: null,
                invalidatedAtUtc: null,
                requestedIpAddress: $challenge->requestedIpAddress(),
                requestedUserAgent: $challenge->requestedUserAgent(),
                createdAtUtc: $now->modify('-11 minutes'),
            ));

            return;
        }

        self::fail('challenge ' . $challengePublicId . ' was not stored');
    }

    /**
     * @param list<string> $permissions
     *
     * @return App<ContainerInterface>
     */
    private function app(array $permissions): App
    {
        $app = TestAppFactory::create();
        $users = $this->container($app)->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(self::ME, $permissions, ['EMPLOYEE']);

        return $app;
    }

    /**
     * A training module, a policy, a form and a document, all belonging to
     * `$owner`, and a trainee record for the signed-in user.
     *
     * @param list<string> $permissions
     * @param list<string> $reached
     *
     * @return App<ContainerInterface>
     */
    private function appWithEverything(
        array $permissions,
        int $owner = self::ME,
        array $reached = [],
        ?AssignmentStatus $formStatus = null,
    ): App {
        $app = $this->app($permissions);
        $this->records($app)->withRecord(self::ME, new OnboardingTraineeRecord('trainee-1', '2026-10-01', null));
        $this->records($app)->withRecord(self::SOMEBODY_ELSE, new OnboardingTraineeRecord('trainee-2', '2026-10-01', null));

        $this->training($app)->withTraining($owner, $this->trainingDetail(self::TRAINING, $reached), $this->questions());
        $this->policies($app)->withPolicy($owner, $this->policyDetail(self::POLICY));

        $forms = $this->forms($app);
        $forms->withForm($owner, new OnboardingFormDetail(
            assignmentPublicId: self::FORM,
            title: 'Employee details',
            versionPublicId: self::FORM_VERSION,
            versionNumber: 1,
            status: $formStatus ?? AssignmentStatus::notStarted(),
            schema: ['fields' => [
                ['path' => 'preferredName', 'label' => 'Preferred name', 'type' => 'text', 'required' => true],
            ]],
            draftData: [],
            draftRowVersion: null,
            draftUpdatedAtUtc: null,
            submittedRevisionCount: $formStatus === null ? 0 : 1,
            lastSubmittedAtUtc: null,
            reviewerNote: null,
        ));
        $forms->withClassifications(self::FORM_VERSION, ['preferredName' => DataClass::ORDINARY_HR]);

        $this->documents($app)->withDocument($owner, new OnboardingDocumentRef(
            publicId: self::DOCUMENT,
            ownerUserId: $owner,
            documentKind: 'employee_details',
            dataClass: DataClass::payroll(),
            blobContainer: 'onboarding',
            blobPath: 'synthetic/doc-1.pdf',
            contentType: 'application/pdf',
            byteSize: 1024,
            contentSha256: str_repeat('c', 64),
            generatedAtUtc: '2026-09-22T00:00:00+00:00',
        ));

        return $app;
    }

    /**
     * @param list<string> $reached
     */
    private function trainingDetail(string $assignmentPublicId, array $reached = []): OnboardingTrainingDetail
    {
        return new OnboardingTrainingDetail(
            assignmentPublicId: $assignmentPublicId,
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
        );
    }

    /**
     * @return list<AssessmentQuestion>
     */
    private function questions(): array
    {
        return [
            AssessmentQuestion::of(
                'q1',
                1,
                'A guard is missing from a machine. What do you do first?',
                ['a' => 'Carry on', 'b' => 'Stop the line'],
                'b',
                'Because the guard is the last line',
            ),
            AssessmentQuestion::of('q2', 2, 'Second question', ['a' => 'No', 'b' => 'Yes'], 'b'),
        ];
    }

    private function policyDetail(string $assignmentPublicId): OnboardingPolicyDetail
    {
        return new OnboardingPolicyDetail(
            assignmentPublicId: $assignmentPublicId,
            title: 'Site Safety',
            versionPublicId: self::POLICY_VERSION,
            versionNumber: 2,
            status: AssignmentStatus::notStarted(),
            contentBlocks: [['type' => 'paragraph', 'text' => 'Wear the boots.']],
            declarationText: 'I have read and accept the Site Safety policy.',
            effectiveFrom: '2026-01-01',
            acknowledgedAtUtc: null,
            requiresAcknowledgement: true,
            documentReference: 'WHS-POL-001-V005',
        );
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, mixed>    $extra
     */
    private function startAttempt(App $app, array $extra = []): string
    {
        $started = $app->handle($this->json('POST', '/api/v1/onboarding/training/' . self::TRAINING . '/attempts', [
            ...$extra,
            'versionPublicId' => self::TRAINING_VERSION,
        ]));
        self::assertSame(201, $started->getStatusCode(), (string) $started->getBody());
        $publicId = $this->data($started)['publicId'] ?? null;
        self::assertIsString($publicId);

        return $publicId;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function statusOf(App $app, string $type, string $assignmentPublicId): string
    {
        $detail = match ($type) {
            'training' => $this->training($app)->findTraining(self::ME, $assignmentPublicId),
            'form' => $this->forms($app)->findForm(self::ME, $assignmentPublicId),
            default => $this->policies($app)->findPolicy(self::ME, $assignmentPublicId),
        };
        self::assertNotNull($detail);

        return $detail->status->toString();
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
        self::assertIsArray($payload['data'] ?? null, (string) $response->getBody());

        /** @var array<string, mixed> */
        return $payload['data'];
    }

    private function code(ResponseInterface $response): ?string
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;

        return is_array($error) && isset($error['code']) && is_string($error['code']) ? $error['code'] : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $value): array
    {
        self::assertIsArray($value);
        $rows = [];
        foreach ($value as $row) {
            self::assertIsArray($row);
            /** @var array<string, mixed> $row */
            $rows[] = $row;
        }

        return $rows;
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

    /** @param App<ContainerInterface> $app */
    private function contributions(App $app): InMemoryOnboardingFormContributionRepository
    {
        $repository = $this->container($app)->get(OnboardingFormContributionRepository::class);
        self::assertInstanceOf(InMemoryOnboardingFormContributionRepository::class, $repository);

        return $repository;
    }

    /** @param App<ContainerInterface> $app */
    private function container(App $app): ContainerInterface
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }
}
