<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\CableFit;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\CableFit\Application\ResearchService;
use TriCab\Core\CableFit\Domain\CandidateNotFound;
use TriCab\Core\CableFit\Domain\ResearchDailyLimitReached;
use TriCab\Core\CableFit\Domain\ResearchInputInvalid;
use TriCab\Core\CableFit\Domain\ResearchNoSources;
use TriCab\Core\CableFit\Domain\ResearchOff;
use TriCab\Core\CableFit\Domain\ResearchRevisionMismatch;
use TriCab\Core\CableFit\Domain\ResearchRunActive;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * POST /api/v1/tsd/cablefit/candidates/{candidatePublicId}/research. Two bodies:
 *
 * - Evaluate (C4): {applicationCode, application?, standardCode?, candidateRevision, ruleCheck?, force?}. 200
 *   {runPublicId, status: 'succeeded', reused: true} when a succeeded run for that saved revision, application
 *   and standard exists (not counted towards the cap); otherwise 202 {runPublicId, status: 'queued', reused:
 *   false}. 409 CABLEFIT_REVISION_MISMATCH (error.currentRevision) when the revision is no longer current.
 * - The history panel and older clients: {application, parameters?}, always a new run.
 *
 * Both: 202 {runPublicId, status: 'queued'}; 422 CABLEFIT_NO_SOURCES with no enabled source; 503
 * CABLEFIT_RESEARCH_OFF while research is switched off; 409 CABLEFIT_RUN_ACTIVE (error.activeRunPublicId) while the
 * candidate has a queued or running run; 429 CABLEFIT_RESEARCH_DAILY_LIMIT (error.limit, error.retryAt, Retry-After)
 * once the user has used the daily cap. The model runs later, in the worker.
 * Gated on TSD.CABLEFIT.RESEARCH.
 */
final class RequestResearchRunAction
{
    public function __construct(
        private readonly ResearchService $research,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);

        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser) {
            return $this->responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->responder->error('CABLEFIT_INPUT_INVALID', 'Send the request as a JSON object.', 422);
        }
        $parameters = $body['parameters'] ?? null;
        if ($parameters !== null && !is_array($parameters)) {
            return $this->responder->error('CABLEFIT_INPUT_INVALID', 'parameters must be an object.', 422);
        }

        $ruleCheck = $body['ruleCheck'] ?? null;
        if ($ruleCheck !== null && !is_array($ruleCheck)) {
            return $this->responder->error('CABLEFIT_INPUT_INVALID', 'ruleCheck must be an object.', 422);
        }
        $force = $body['force'] ?? false;
        if (!is_bool($force)) {
            return $this->responder->error('CABLEFIT_INPUT_INVALID', 'force must be true or false.', 422);
        }

        try {
            if (array_key_exists('applicationCode', $body)) {
                $result = $this->research->evaluate(
                    $user,
                    $args['candidatePublicId'] ?? '',
                    $body['applicationCode'],
                    $body['application'] ?? null,
                    $body['standardCode'] ?? null,
                    $body['candidateRevision'] ?? null,
                    $ruleCheck,
                    $force,
                );

                return $this->responder->success(
                    ['runPublicId' => $result->runPublicId, 'status' => $result->status, 'reused' => $result->reused],
                    $result->reused ? 200 : 202,
                );
            }
            $runId = $this->research->requestRun($user, $args['candidatePublicId'] ?? '', $body['application'] ?? null, $parameters);
        } catch (ResearchRevisionMismatch $mismatch) {
            return $this->responder->errorWith(
                'CABLEFIT_REVISION_MISMATCH',
                'The design has been saved since; reload it and evaluate again.',
                409,
                ['currentRevision' => $mismatch->currentRevision],
            );
        } catch (ResearchOff) {
            return $this->responder->error('CABLEFIT_RESEARCH_OFF', 'Research is switched off.', 503);
        } catch (CandidateNotFound) {
            return $this->responder->error('CABLEFIT_CANDIDATE_NOT_FOUND', 'Candidate not found.', 404);
        } catch (ResearchInputInvalid $invalid) {
            return $this->responder->error('CABLEFIT_INPUT_INVALID', $invalid->getMessage(), 422);
        } catch (ResearchNoSources) {
            return $this->responder->error('CABLEFIT_NO_SOURCES', 'No research source is enabled.', 422);
        } catch (ResearchRunActive $active) {
            return $this->responder->errorWith(
                'CABLEFIT_RUN_ACTIVE',
                'A research run is already queued or running for this candidate.',
                409,
                ['activeRunPublicId' => $active->activeRunPublicId],
            );
        } catch (ResearchDailyLimitReached $limit) {
            $retryAt = $limit->retryAt->setTimezone(new \DateTimeZone('UTC'));

            return $this->responder->errorWith(
                'CABLEFIT_RESEARCH_DAILY_LIMIT',
                sprintf('You have started %d research runs in the last 24 hours, the daily limit.', $limit->limit),
                429,
                ['limit' => $limit->limit, 'retryAt' => $retryAt->format('Y-m-d\TH:i:s\Z')],
                ['Retry-After' => (string) max(1, $retryAt->getTimestamp() - time())],
            );
        }

        return $this->responder->success(['runPublicId' => $runId, 'status' => 'queued'], 202);
    }
}
