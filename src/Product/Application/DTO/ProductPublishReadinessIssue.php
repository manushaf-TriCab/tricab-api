<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * A single readiness finding. Severity "error" blocks publication;
 * "warning" is informational only.
 */
final readonly class ProductPublishReadinessIssue
{
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARNING = 'warning';

    public function __construct(
        public string $code,
        public string $area,
        public string $message,
        public string $severity,
        public ?string $entityPublicId = null,
        public ?string $field = null,
    ) {
    }

    public function isError(): bool
    {
        return $this->severity === self::SEVERITY_ERROR;
    }
}
