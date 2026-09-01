<?php

declare(strict_types=1);

namespace TriCab\Core\CableCalculator\Application\Exception;

final class CableCalculationValidationException extends \DomainException
{
    /** @param array<string, string> $fieldErrors */
    public function __construct(
        string $message,
        public readonly array $fieldErrors = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string, string> $fieldErrors */
    public static function withFieldErrors(array $fieldErrors): self
    {
        return new self('Cable calculation input is invalid.', $fieldErrors);
    }
}
