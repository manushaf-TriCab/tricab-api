<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\VerifiedIdentityAttribute;
use TriCab\Core\DocumentGeneration\Application\Exception\InvalidOcrStream;
use TriCab\Core\DocumentGeneration\Application\Exception\UnsupportedDocumentTemplate;
use TriCab\Core\DocumentGeneration\Application\Handler\GenerateOcrPdfHandler;
use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;
use TriCab\Core\Identity\Application\Exception\IdentityAmbiguous;
use TriCab\Core\Identity\Application\Exception\IdentityConflict;
use TriCab\Core\Identity\Application\Exception\UserNotRegistered;
use TriCab\Core\Identity\Application\Handler\ResolveAuthenticatedUserHandler;
use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;

final class GenerateDocumentPdfAction
{
    private const MAX_OCR_BYTES = 5 * 1024 * 1024;

    /** @var list<string> */
    private const SUPPORTED_MEDIA_TYPES = [
        'text/plain',
        'application/octet-stream',
        'application/x-ocr',
    ];

    public function __construct(
        private readonly GenerateOcrPdfHandler $generatePdf,
        private readonly ResolveAuthenticatedUserHandler $resolveUser,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $authenticationError = $this->authenticate($request);
        if ($authenticationError instanceof ResponseInterface) {
            return $authenticationError;
        }

        $declaredLength = trim($request->getHeaderLine('Content-Length'));
        if ($declaredLength !== '' && ctype_digit($declaredLength) && (int) $declaredLength > self::MAX_OCR_BYTES) {
            return $this->responder->error(
                'OCR_PAYLOAD_TOO_LARGE',
                'The OCR stream exceeds the 5 MB limit.',
                413,
            );
        }

        $mediaType = $this->mediaType($request->getHeaderLine('Content-Type'));
        if (!in_array($mediaType, self::SUPPORTED_MEDIA_TYPES, true)) {
            return $this->responder->error(
                'UNSUPPORTED_OCR_MEDIA_TYPE',
                'Use Content-Type: text/plain, application/octet-stream, or application/x-ocr.',
                415,
            );
        }

        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $ocrContents = $body->getContents();

        if ($ocrContents === '') {
            return $this->responder->error(
                'EMPTY_OCR_STREAM',
                'The request body must contain an OCR record stream.',
                400,
            );
        }

        if (strlen($ocrContents) > self::MAX_OCR_BYTES) {
            return $this->responder->error(
                'OCR_PAYLOAD_TOO_LARGE',
                'The OCR stream exceeds the 5 MB limit.',
                413,
            );
        }

        try {
            $result = $this->generatePdf->handle($ocrContents);
        } catch (InvalidOcrStream|UnsupportedDocumentTemplate $exception) {
            return $this->responder->error(
                'INVALID_OCR_STREAM',
                $exception->getMessage(),
                422,
            );
        }

        $response->getBody()->write($result->contents);

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', $result->filename))
            ->withHeader('Content-Length', (string) strlen($result->contents))
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-OCR-Template', $result->templateName)
            ->withHeader('X-OCR-Warning-Count', (string) count($result->warnings));
    }

    private function authenticate(ServerRequestInterface $request): ?ResponseInterface
    {
        $verifiedIdentity = $request->getAttribute(VerifiedIdentityAttribute::KEY);
        if (!$verifiedIdentity instanceof VerifiedIdentity) {
            return $this->responder->error(
                'UNAUTHENTICATED',
                'Authentication is required.',
                401,
            );
        }

        try {
            $this->resolveUser->handle($verifiedIdentity);
        } catch (UserNotRegistered) {
            return $this->responder->error(
                'USER_NOT_REGISTERED',
                'No registered TriCab user matches this verified identity.',
                403,
            );
        } catch (IdentityAmbiguous) {
            return $this->responder->error(
                'IDENTITY_AMBIGUOUS',
                'Multiple TriCab accounts match this verified email. Contact IT for help.',
                403,
            );
        } catch (IdentityConflict) {
            return $this->responder->error(
                'IDENTITY_CONFLICT',
                'This sign-in could not be linked safely. Contact IT for help.',
                403,
            );
        } catch (AuthenticationNotAllowed) {
            return $this->responder->error(
                'DOCUMENT_GENERATION_FORBIDDEN',
                'This TriCab account cannot generate documents.',
                403,
            );
        }

        return null;
    }

    private function mediaType(string $contentType): string
    {
        if (trim($contentType) === '') {
            return 'text/plain';
        }

        return strtolower(trim(explode(';', $contentType, 2)[0]));
    }
}
