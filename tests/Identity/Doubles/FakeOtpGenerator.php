<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Doubles;

use TriCab\Core\Identity\Contract\OtpGenerator;
use TriCab\Core\Identity\Domain\ValueObject\OtpCode;

/**
 * Deterministic OTP generator for tests that assert call counts.
 */
final class FakeOtpGenerator implements OtpGenerator
{
    /** @var list<string> */
    private array $codes;

    public int $generateCount = 0;

    /**
     * @param list<string> $codes
     */
    public function __construct(array $codes = ['123456'])
    {
        $this->codes = $codes === [] ? ['123456'] : $codes;
    }

    public function generate(): OtpCode
    {
        $index = min($this->generateCount, count($this->codes) - 1);
        ++$this->generateCount;

        return OtpCode::fromString($this->codes[$index]);
    }
}
