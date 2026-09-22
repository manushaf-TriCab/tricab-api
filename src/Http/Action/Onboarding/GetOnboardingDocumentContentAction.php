<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\DTO\OnboardingDocumentAccess;
use TriCab\Core\Onboarding\Application\Service\OpenOwnDocumentService;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingDocumentNotFound;
use TriCab\Core\Product\Contract\ProductBlobStore;

/**
 * GET /api/v1/onboarding/documents/{documentPublicId}/content
 *
 * Two behaviours behind one route, chosen by
 * {@see \TriCab\Core\Onboarding\Domain\Service\DocumentDeliveryPolicy} and not
 * by the caller:
 *
 *  - **C3, C4 and anything unclassified** are streamed through this endpoint.
 *    The bytes travel over the authenticated request, the read is in
 *    `audit.OnboardingDocumentAccessEvents` with an actor, and no capability
 *    outlives the response.
 *  - **C0–C2** get a short-lived signed URL, following the expense precedent.
 *
 * A client cannot ask for one or the other. There is no `?mode=` and no header —
 * the point of the policy is that the answer does not depend on who is asking
 * for what.
 *
 * ## Why a JSON envelope for the signed URL rather than a 302
 *
 * A redirect puts the SAS URL in the browser's history and in every proxy log
 * that records a `Location`. Returning it in the body keeps it to the one
 * response, and the SPA fetches it without navigating.
 *
 * ## The failures
 *
 * A reference whose bytes are missing answers 404, not 500. The row exists and
 * the blob does not, which is an operational fault — but reporting it
 * distinguishably would tell whoever is probing that the reference is real, by a
 * slower route. It is visible where it should be: in the logs, and in the audit
 * row recording an authorized read that returned nothing.
 */
final class GetOnboardingDocumentContentAction
{
    public function __construct(
        private readonly OpenOwnDocumentService $documents,
        private readonly ProductBlobStore $blobs,
        private readonly OnboardingSelfServiceResponder $responder,
    ) {
    }

    /** How long a C0–C2 signed URL lives. Long enough to click, short enough not to share. */
    private const SIGNED_URL_SECONDS = 120;

    /**
     * @param array<string, string> $arguments
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $arguments = [],
    ): ResponseInterface {
        unset($response);

        return $this->responder->run($request, function (AuthenticatedUser $user) use ($request, $arguments): ResponseInterface {
            $access = $this->documents->open(
                $user->userId,
                $arguments['documentPublicId'] ?? '',
                'download',
                self::correlationId($request),
                self::clientIp($request),
                $request->getHeaderLine('User-Agent') ?: null,
            );

            return $access->mustBeStreamed()
                ? $this->stream($access)
                : $this->sign($access);
        });
    }

    private function stream(OnboardingDocumentAccess $access): ResponseInterface
    {
        $document = $access->document;
        $binary = $this->blobs->getObjectBinary($document->blobContainer, $document->blobPath);

        if ($binary === null) {
            throw OnboardingDocumentNotFound::forThisPerson();
        }

        $response = new Response(200);
        $response->getBody()->write($binary);

        return $response
            ->withHeader('Content-Type', $document->contentType !== '' ? $document->contentType : 'application/octet-stream')
            // `attachment`, always. A payroll PDF rendered inline is a payroll
            // PDF sitting in a tab somebody walks away from.
            ->withHeader('Content-Disposition', 'attachment; filename="' . $document->downloadFilename() . '"')
            // Never cached. A shared or kiosk browser holding a copy of a
            // restricted document defeats the audit trail this route exists for.
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    private function sign(OnboardingDocumentAccess $access): ResponseInterface
    {
        $document = $access->document;
        $ticket = $this->blobs->issueReadDownload(
            $document->blobContainer,
            $document->blobPath,
            self::SIGNED_URL_SECONDS,
        );

        return $this->responder->success([
            'deliveryMode' => $access->mode,
            'url' => $ticket->uploadUrl,
            'httpMethod' => $ticket->httpMethod,
            'expiresAtUtc' => $ticket->expiresAtUtc,
            'contentType' => $document->contentType,
            'filename' => $document->downloadFilename(),
        ]);
    }

    /**
     * Whatever correlates this request in the platform's own logs, so an audit
     * row can be lined up against them. Absent is fine; a fabricated one is not,
     * so nothing is generated here.
     */
    private static function correlationId(ServerRequestInterface $request): ?string
    {
        foreach (['X-Correlation-Id', 'X-Request-Id'] as $header) {
            $value = trim($request->getHeaderLine($header));

            if ($value !== '') {
                return mb_substr($value, 0, 100);
            }
        }

        return null;
    }

    /**
     * The address the server observed, not one a header claimed.
     *
     * `X-Forwarded-For` is deliberately ignored. It is client-supplied, and an
     * audit row recording an address the reader chose is worse than one
     * recording none — it looks like evidence.
     */
    private static function clientIp(ServerRequestInterface $request): ?string
    {
        $params = $request->getServerParams();
        $remote = $params['REMOTE_ADDR'] ?? null;

        return is_string($remote) && $remote !== '' ? mb_substr($remote, 0, 64) : null;
    }
}
