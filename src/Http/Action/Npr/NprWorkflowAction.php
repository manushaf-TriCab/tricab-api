<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Npr;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Npr\Application\Service\NprAttachmentService;
use TriCab\Core\Npr\Application\Service\NprRequestView;
use TriCab\Core\Npr\Application\Service\NprWorkflowService;
use TriCab\Core\Npr\Domain\Exception\InvalidNprInput;
use TriCab\Core\Npr\Domain\Exception\NprRequestConflict;
use TriCab\Core\Npr\Domain\Exception\NprValidationFailed;
use TriCab\Core\Npr\Domain\Exception\NprWorkflowRefused;

/**
 * The manager, TSD and signatory steps of an NPR. One class; the route names
 * the step (`->setArgument('step', ...)`), and gates it on one permission:
 *
 * | Step                       | Route                                                        | Permission            | Body |
 * |----------------------------|--------------------------------------------------------------|-----------------------|------|
 * | `team.endorse`             | POST /npr/team/requests/{publicId}/endorse                   | `NPR.TEAM.ENDORSE`    | `{version, declaration: true, comment?}` |
 * | `team.return`              | POST /npr/team/requests/{publicId}/return                    | `NPR.TEAM.ENDORSE`    | `{version, reason}` |
 * | `team.reject`              | POST /npr/team/requests/{publicId}/reject                    | `NPR.TEAM.ENDORSE`    | `{version, reason}` |
 * | `tsd.acknowledge`          | POST /npr/tsd/requests/{publicId}/acknowledge                | `NPR.TSD.WORK`        | `{version, formComplete, notes?}` |
 * | `tsd.assign`               | POST /npr/tsd/requests/{publicId}/assign                     | `NPR.TSD.WORK`        | `{version, ownerUserId or ownerEmail}` |
 * | `tsd.return`               | POST /npr/tsd/requests/{publicId}/return                     | `NPR.TSD.WORK`        | `{version, reason}` |
 * | `tsd.request-screening`    | POST /npr/tsd/requests/{publicId}/request-screening          | `NPR.TSD.WORK`        | `{version, signatoryUserId or signatoryEmail}` |
 * | `tsd.assessment`           | PUT  /npr/tsd/requests/{publicId}/assessment                 | `NPR.TSD.WORK`        | `{version, answers}` |
 * | `signatory.decide`         | POST /npr/tsd/requests/{publicId}/screening-decision         | `NPR.SCREENING.DECIDE`| `{version, outcome, rationale, conditions?}` |
 *
 * Every step needs the `version` last read (409 `NPR_VERSION_CONFLICT` when
 * stale). A request the caller has no part in, or that does not exist, is 404.
 * Answers with the request as GET would, with refreshed `allowedActions`.
 */
final class NprWorkflowAction
{
    use NprActionSupport;

    public function __construct(
        private readonly NprWorkflowService $workflow,
        private readonly JsonResponder $responder,
        private readonly NprAttachmentService $attachments,
    ) {
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        $user = $this->user($request);
        if ($user === null) {
            return $this->unauthenticated($this->responder);
        }
        $body = $this->body($request);
        $version = $this->version($body);
        if ($version === null) {
            return $this->versionRequired($this->responder);
        }
        $id = $args['publicId'] ?? '';
        $text = static fn (string $key): ?string => is_string($body[$key] ?? null) ? $body[$key] : null;

        try {
            /** @var NprRequestView|null $view */
            $view = match ($args['step'] ?? '') {
                'team.endorse' => $this->workflow->endorse($user, $id, $version, $body['declaration'] ?? null, $text('comment')),
                'team.return' => $this->workflow->managerReturn($user, $id, $version, $body['reason'] ?? null),
                'team.reject' => $this->workflow->managerReject($user, $id, $version, $body['reason'] ?? null),
                'tsd.acknowledge' => $this->workflow->acknowledge($user, $id, $version, $body['formComplete'] ?? null, $text('notes')),
                'tsd.assign' => $this->workflow->assignOwner($user, $id, $version, $body['ownerUserId'] ?? null, $body['ownerEmail'] ?? null),
                'tsd.return' => $this->workflow->tsdReturn($user, $id, $version, $body['reason'] ?? null),
                'tsd.request-screening' => $this->workflow->requestScreening($user, $id, $version, $body['signatoryUserId'] ?? null, $body['signatoryEmail'] ?? null),
                'tsd.assessment' => $this->workflow->saveAssessment($user, $id, $version, is_array($body['answers'] ?? null) ? $body['answers'] : []),
                'signatory.decide' => $this->workflow->decide($user, $id, $version, $body['outcome'] ?? null, $body['rationale'] ?? null, $body['conditions'] ?? null),
                default => throw new \LogicException('Unknown NPR workflow step.'),
            };
        } catch (InvalidNprInput|NprRequestConflict|NprValidationFailed|NprWorkflowRefused $failure) {
            return $this->failure($this->responder, $failure);
        }

        return $view === null ? $this->notFound($this->responder) : $this->responder->success(NprRequestPresenter::detail($view, $this->attachments->forRequest($view->request->publicId)));
    }
}
