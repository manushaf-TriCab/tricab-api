<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Document;

/**
 * A generated document's bytes, before they are stored (5.4).
 *
 * `$documentKind` must be one of `CK_onboarding_DocumentReferences_Kind`.
 */
final readonly class RenderedDocument
{
    public const KINDS = ['employee_details', 'legal_forms', 'workflow', 'policy_declaration', 'assessment', 'photo'];

    public string $sha256;

    public function __construct(
        public string $documentKind,
        public string $contentType,
        public string $bytes,
    ) {
        if (!in_array($documentKind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Unknown document kind: ' . $documentKind);
        }
        if ($bytes === '') {
            throw new \InvalidArgumentException('A rendered document has no bytes.');
        }
        $this->sha256 = hash('sha256', $bytes);
    }

    public function byteSize(): int
    {
        return strlen($this->bytes);
    }
}
