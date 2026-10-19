<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Npr;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Npr\Application\Service\NprAttachmentService;
use TriCab\Core\Npr\Attachment\NprAttachmentRules;
use TriCab\Core\Npr\Domain\Exception\InvalidNprInput;
use TriCab\Core\Npr\Domain\Exception\NprAttachmentRejected;
use TriCab\Core\Npr\Domain\Exception\NprAttachmentStorageUnavailable;
use TriCab\Core\Npr\Domain\Exception\NprRequestNotEditable;

/**
 * Files on an NPR (N2.3). One class; the route names the scope (`own`, `team`,
 * `tsd`) and the step, and gates it on one permission:
 *
 * | Route                                                                   | Permission        | Notes |
 * |-------------------------------------------------------------------------|-------------------|-------|
 * | GET    /npr/me/requests/{publicId}/attachments                          | `NPR.SELF.READ`   | list |
 * | POST   /npr/me/requests/{publicId}/attachments                          | `NPR.SELF.CREATE` | multipart: `file`, optional `documentType`, `title`; only while Sales can edit |
 * | DELETE /npr/me/requests/{publicId}/attachments/{attachmentPublicId}     | `NPR.SELF.CREATE` | soft delete, own file, only while Sales can edit |
 * | GET    /npr/me/requests/{publicId}/attachments/{attachmentPublicId}/download | `NPR.SELF.READ` | |
 * | GET    /npr/team/requests/{publicId}/attachments[/{id}/download]        | `NPR.TEAM.ENDORSE`| read only |
 * | GET    /npr/tsd/requests/{publicId}/attachments[/{id}/download]         | `NPR.TSD.READ`    | read only |
 * | POST   /npr/tsd/requests/{publicId}/attachments                         | `NPR.TSD.WORK`    | with TSD or awaiting screening |
 *
 * A request the caller has no part in, or a file that is not there, is 404.
 * A download is served from here (never a public URL), as an attachment with
 * `nosniff` and no caching.
 */
final class NprAttachmentAction
{
    use NprActionSupport;

    public function __construct(
        private readonly NprAttachmentService $attachments,
        private readonly JsonResponder $responder,
    ) {
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $user = $this->user($request);
        if ($user === null) {
            return $this->unauthenticated($this->responder);
        }
        $scope = $args['scope'] ?? '';
        $id = $args['publicId'] ?? '';
        $fileId = $args['attachmentPublicId'] ?? '';

        try {
            switch ($args['step'] ?? '') {
                case 'list':
                    $files = $this->attachments->list($user, $scope, $id);

                    return $files === null
                        ? $this->notFound($this->responder)
                        : $this->responder->success(['items' => array_map(NprRequestPresenter::attachment(...), $files)]);
                case 'upload':
                    $upload = $this->upload($request);
                    if ($upload instanceof ResponseInterface) {
                        return $upload;
                    }
                    $body = $this->body($request);
                    $saved = $this->attachments->upload(
                        $user,
                        $scope,
                        $id,
                        $upload['name'],
                        $upload['bytes'],
                        is_string($body['documentType'] ?? null) ? $body['documentType'] : null,
                        is_string($body['title'] ?? null) ? $body['title'] : null,
                    );

                    return $saved === null ? $this->notFound($this->responder) : $this->responder->success(NprRequestPresenter::attachment($saved), 201);
                case 'delete':
                    $removed = $this->attachments->remove($user, $id, $fileId);

                    return $removed === null
                        ? $this->responder->error('NPR_NOT_FOUND', 'Attachment not found.', 404)
                        : $this->responder->success(NprRequestPresenter::attachment($removed));
                default:
                    $found = $this->attachments->download($user, $scope, $id, $fileId);
                    if ($found === null) {
                        return $this->responder->error('NPR_NOT_FOUND', 'Attachment not found.', 404);
                    }
                    $name = preg_replace('/[^A-Za-z0-9 ._()-]+/', '_', $found['file']->filename) ?: 'attachment';
                    $response->getBody()->write($found['bytes']);

                    return $response
                        ->withHeader('Content-Type', $found['file']->mimeType)
                        ->withHeader('Content-Length', (string) strlen($found['bytes']))
                        ->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', $name))
                        ->withHeader('X-Content-Type-Options', 'nosniff')
                        ->withHeader('Content-Security-Policy', 'sandbox')
                        ->withHeader('Cache-Control', 'private, no-store');
            }
        } catch (InvalidNprInput|NprAttachmentRejected|NprAttachmentStorageUnavailable|NprRequestNotEditable $failure) {
            return $this->failure($this->responder, $failure);
        }
    }

    /** @return array{name: string, bytes: string}|ResponseInterface */
    private function upload(ServerRequestInterface $request): array|ResponseInterface
    {
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            return $this->failure($this->responder, new NprAttachmentRejected(NprAttachmentRejected::NO_FILE, 'Send one file as the multipart field "file".'));
        }
        if ($file->getError() === UPLOAD_ERR_INI_SIZE || $file->getError() === UPLOAD_ERR_FORM_SIZE
            || ($file->getSize() ?? 0) > NprAttachmentRules::MAX_FILE_BYTES) {
            return $this->failure($this->responder, new NprAttachmentRejected(NprAttachmentRejected::TOO_LARGE, 'File exceeds the 10 MiB limit.', 413));
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->failure($this->responder, new NprAttachmentRejected(NprAttachmentRejected::NO_FILE, 'The file did not upload. Try again.'));
        }

        return ['name' => $file->getClientFilename() ?? '', 'bytes' => $file->getStream()->getContents()];
    }
}
