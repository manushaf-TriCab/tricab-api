<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Npr;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;

/**
 * GET /api/v1/npr/field-dictionary: `resources/npr/field-dictionary.json` as it
 * is, so the web does not bundle its own copy. The dictionary is form structure
 * (sections, fields, options, owners), not request data. Gated on `NPR.SELF.READ`
 * (the routes allow one permission each; the TSD and manager screens hold it too
 * in the seeded roles, or can be given it).
 */
final class GetNprFieldDictionaryAction
{
    use NprActionSupport;

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly string $path,
    ) {
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response, $args);
        if ($this->user($request) === null) {
            return $this->unauthenticated($this->responder);
        }
        $raw = @file_get_contents($this->path);
        $dictionary = $raw === false ? null : json_decode($raw, true);
        if (!is_array($dictionary)) {
            return $this->responder->error('NPR_DICTIONARY_UNAVAILABLE', 'The field dictionary is not available.', 503);
        }

        /** @var array<string, mixed> $dictionary */
        return $this->responder->success($dictionary, 200, ['Cache-Control' => 'private, max-age=300']);
    }
}
