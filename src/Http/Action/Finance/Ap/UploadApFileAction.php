<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Domain\ApInvalid;
use TriCab\Core\Finance\Ap\Service\ApAttachmentService;

/**
 * POST /api/v1/finance/ap/uploads (source route 45): the capture dialog's file, multipart with one part named `file`. It is kept
 * unattached in the finance file store (app `AP`) with its SHA-256, and answers 201 `{upload_id, name}`; `POST /invoices` takes
 * the id and puts the file on the invoice. An upload never put on an invoice is swept after a day. Gated on `FINANCE.AP.INVOICES.WRITE`.
 */
final class UploadApFileAction
{
    use ApActionSupport;

    public function __construct(
        private readonly ApAttachmentService $service,
        private readonly ApActorResolver $actors,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response, $args);
        $actor = $this->actors->actorOf($request);
        if ($actor === null) {
            return $this->unauthenticated($this->responder);
        }
        try {
            $file = $request->getUploadedFiles()['file'] ?? null;
            if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
                return $this->ok($this->responder, $this->service->upload($actor, null, ''), 201);
            }
            if ($file->getError() !== UPLOAD_ERR_OK) {
                throw new ApInvalid('The file did not upload completely. Try again.');
            }

            return $this->ok($this->responder, $this->service->upload($actor, $file->getClientFilename(), (string) $file->getStream()->getContents()), 201);
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
