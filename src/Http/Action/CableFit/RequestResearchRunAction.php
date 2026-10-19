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
use TriCab\Core\CableFit\Domain\ResearchRunActive;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * POST /api/v1/tsd/cablefit/candidates/{candidatePublicId}/research - body {application, parameters?}.
 * 202 {runPublicId, status: 'queued'}; 422 CABLEFIT_NO_SOURCES with no enabled source; 503
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

        try {
            $runId = $this->research->requestRun($user, $args['candidatePublicId'] ?? '', $body['application'] ?? null, $parameters);
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
