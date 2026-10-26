<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap2;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Action\Finance\Ap\ApActionSupport;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap2\Service\Ap2MailService;

/** GET /mail/{id}/attachments/{aid}/file. `FINANCE.AP2.MAIL.READ`. */
final class GetAp2MailAttachmentFileAction
{
    use ApActionSupport;
    use Ap2FileResponse;

    public function __construct(
        private readonly Ap2MailService $service,
        private readonly Ap2ActorResolver $actors,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $actor = $this->actors->actorOf($request);
        if ($actor === null) {
            return $this->unauthenticated($this->responder);
        }
        try {
            $file = $this->service->attachmentFile((string) ($args['id'] ?? ''), (string) ($args['aid'] ?? ''), $actor->userId);
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }

        return $this->fileResponse($response, $file);
    }
}
