<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Rfq;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Rfq\Contract\RfqParserClient;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\ParserContract;
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Parser\RfqServiceLimits;

/**
 * POST /api/v1/rfqs/ocr — Scan Screenshot (parity plan E4.3). Multipart, one
 * `file`: a png, jpeg or webp. Answers `rfq.screenshot-text.v1`: the text the
 * vision model transcribed, with its confidence and the model's name.
 *
 * The text goes back into the paste box for the rep to read and correct, then
 * through Match Text like any paste; nothing is matched, stored or created
 * here, and the image is neither kept nor logged. Gated on `RFQ.DRAFT.CREATE`,
 * since its only use is to start an RFQ. The provider being off, refused or
 * throttled is a typed `RFQ_PARSER_*` failure with plain words.
 */
final class ScanRfqScreenshotAction
{
    use RfqWriteFailures;

    /** What the model reads; checked here so a bad file never costs a provider call. */
    private const TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    public function __construct(
        private readonly RfqParserClient $parser,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        if (!$request->getAttribute(AuthenticatedUserAttribute::KEY) instanceof AuthenticatedUser) {
            return $this->responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->responder->error('RFQ_INPUT_INVALID', 'Attach one screenshot as `file`.', 422);
        }
        $size = $file->getSize() ?? 0;
        if ($size < 1 || $size > RfqServiceLimits::MAX_IMAGE_BYTES) {
            return $this->responder->error('RFQ_INPUT_INVALID', sprintf('A screenshot must be between 1 byte and %d MB.', RfqServiceLimits::MAX_IMAGE_BYTES / (1024 * 1024)), 422);
        }
        $type = strtolower(trim(explode(';', (string) $file->getClientMediaType())[0]));
        if (!in_array($type, self::TYPES, true)) {
            return $this->responder->error('RFQ_INPUT_INVALID', 'Scan Screenshot reads png, jpeg and webp images.', 422);
        }
        $name = trim((string) $file->getClientFilename());
        $id = CorrelationId::fromHeaderOrGenerate($request->getHeaderLine(ParserContract::HEADER_CORRELATION_ID));
        try {
            $text = $this->parser->readScreenshot($id, $name !== '' ? $name : 'screenshot', $type, (string) $file->getStream()->getContents());
        } catch (RfqParserFailure $failure) {
            return $this->failure($this->responder, $failure);
        }

        return $this->responder->success($text->toArray());
    }
}
