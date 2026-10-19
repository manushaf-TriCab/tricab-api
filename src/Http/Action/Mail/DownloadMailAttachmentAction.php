<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Mail;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Mail\Application\Service\MailMessageService;
use TriCab\Core\Mail\Domain\Exception\MailFailure;

/**
 * GET /api/v1/mail/attachments/{id}: the file's bytes, fetched from Exchange now. The type and name
 * are the sender's, so the file is served as an opaque download (`attachment`, `nosniff`, a sandbox
 * policy) and never rendered from this origin. Over the RFQ parser's size limits it is 413.
 * Gated on `MAIL.ACCESS`.
 */
final class DownloadMailAttachmentAction
{
    use MailActionSupport;

    public function __construct(private readonly MailMessageService $messages, private readonly JsonResponder $responder)
    {
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        if ($this->actor($request) === null) {
            return $this->unauthenticated($this->responder);
        }
        try {
            $file = $this->messages->attachment($args['id'] ?? '');
        } catch (MailFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
        $name = preg_replace('/[^A-Za-z0-9 ._()-]+/', '_', $file->fileName) ?: 'attachment';
        $response->getBody()->write($file->content);

        return $response
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Length', (string) strlen($file->content))
            ->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', $name))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', 'sandbox')
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
