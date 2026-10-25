<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Service\TaskFileService;

/** The one multipart part named `file` that Tri-Task's two upload routes read. */
trait TaskFileUploadSupport
{
    /**
     * @return array{0: ?string, 1: string, 2: string} the name the sender gave, its type and its bytes; a missing file is a null name
     */
    private function uploadedFile(ServerRequestInterface $request): array
    {
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return [null, '', ''];
        }
        if ($file->getError() === UPLOAD_ERR_INI_SIZE || $file->getError() === UPLOAD_ERR_FORM_SIZE) {
            // PHP dropped it for size before the app saw it: say the app's own limit.
            throw new TaskInvalid(TaskFileService::TOO_BIG);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new TaskInvalid('The file did not upload completely. Try again.');
        }

        return [$file->getClientFilename(), (string) $file->getClientMediaType(), (string) $file->getStream()->getContents()];
    }
}
