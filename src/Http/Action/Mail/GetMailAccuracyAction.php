<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Mail;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Mail\Application\DTO\ExportQuery;
use TriCab\Core\Mail\Application\Service\MailAccuracyService;
use TriCab\Core\Mail\Domain\Exception\MailFailure;

/**
 * GET /api/v1/mail/accuracy?from&to: the M5 gate numbers over the labelled set (see {@see MailAccuracyService}).
 * Gated on `MAIL.REVIEW`; the service also needs `MAIL.ACCESS`. Read-only, and not written to the Activity tab.
 */
final class GetMailAccuracyAction
{
    use MailActionSupport;

    public function __construct(private readonly MailAccuracyService $accuracy, private readonly JsonResponder $responder)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        $actor = $this->actor($request);
        if ($actor === null) {
            return $this->unauthenticated($this->responder);
        }
        try {
            $query = ExportQuery::fromInput(array_filter($request->getQueryParams(), 'is_string', ARRAY_FILTER_USE_KEY));

            return $this->responder->success($this->accuracy->accuracy($actor, $query));
        } catch (MailFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
