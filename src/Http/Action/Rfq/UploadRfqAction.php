<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Rfq;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Rfq\Application\Service\ResolveRfqActorService;
use TriCab\Core\Rfq\Application\Service\RfqReaderService;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\Exception\RfqStoreUnavailable;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\ParserContract;
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Parser\RfqServiceLimits;

/**
 * POST /api/v1/rfqs/upload — Upload RFQ Files (plan 3.3, parity plan E4.1).
 * Multipart, one or more `files[]`. Answers 201 with `rfq.uploaded.v1`.
 *
 * As the source's upload does, the files are read at once and the rows become
 * a draft; each file a model reader will do better on is queued for a
 * background read, whose rows replace or are offered against these
 * (`RfqReaderService`). The file names are display metadata only.
 *
 * Gated on `RFQ.DRAFT.CREATE`. Limits are the service's (`RfqServiceLimits`),
 * checked here before anything is read and again by the parser.
 */
final class UploadRfqAction
{
    use RfqWriteFailures;

    public function __construct(
        private readonly ResolveRfqActorService $actors,
        private readonly RfqReaderService $reader,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser) {
            return $this->responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }
        $files = self::files($request->getUploadedFiles());
        if (is_string($files)) {
            return $this->responder->error('RFQ_INPUT_INVALID', $files, 422);
        }

        $id = CorrelationId::fromHeaderOrGenerate($request->getHeaderLine(ParserContract::HEADER_CORRELATION_ID));
        try {
            $uploaded = $this->reader->upload($this->actors->forUser($user), $user->emailAddress, $files, $id);
        } catch (InvalidRfqValue|RfqActionForbidden|RfqParserFailure|RfqStoreUnavailable $failure) {
            return $this->failure($this->responder, $failure);
        }

        return $this->responder->success($uploaded->toArray(), 201);
    }

    /**
     * @param array<array-key, mixed> $uploaded
     *
     * @return list<array{fileName: string, contentType: string, bytes: string}>|string
     */
    private static function files(array $uploaded): array|string
    {
        $list = $uploaded['files'] ?? ($uploaded['file'] ?? null);
        if ($list instanceof UploadedFileInterface) {
            $list = [$list];
        }
        if (!is_array($list) || $list === []) {
            return 'Attach at least one file as `files[]`.';
        }
        if (count($list) > RfqServiceLimits::MAX_DOCUMENTS_PER_INTAKE) {
            return sprintf('Upload at most %d files at once.', RfqServiceLimits::MAX_DOCUMENTS_PER_INTAKE);
        }
        $out = [];
        foreach ($list as $file) {
            if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
                return 'A file did not upload completely. Try again.';
            }
            $size = $file->getSize() ?? 0;
            if ($size < 1 || $size > RfqServiceLimits::MAX_DOCUMENT_BYTES) {
                return sprintf('Each file must be between 1 byte and %d MB.', RfqServiceLimits::MAX_DOCUMENT_BYTES / (1024 * 1024));
            }
            $out[] = [
                'fileName' => trim((string) $file->getClientFilename()) !== '' ? (string) $file->getClientFilename() : 'upload',
                'contentType' => strtolower(trim(explode(';', (string) $file->getClientMediaType())[0])),
                'bytes' => (string) $file->getStream()->getContents(),
            ];
        }

        return $out;
    }
}
