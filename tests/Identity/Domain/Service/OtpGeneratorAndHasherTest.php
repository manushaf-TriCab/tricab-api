<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Domain\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Domain\Service\PasswordOtpHasher;
use TriCab\Core\Identity\Domain\Service\SecureOtpGenerator;
use TriCab\Core\Identity\Domain\ValueObject\OtpCode;

final class OtpGeneratorAndHasherTest extends TestCase
{
    public function test_secure_generator_produces_six_digit_numeric_codes(): void
    {
        $generator = new SecureOtpGenerator();

        for ($i = 0; $i < 20; ++$i) {
            $otp = $generator->generate();
            self::assertMatchesRegularExpression('/^\d{6}$/', $otp->toString());
        }
    }

    public function test_password_hasher_round_trips(): void
    {
        $hasher = new PasswordOtpHasher();
        $otp = OtpCode::fromString('123456');

        $hash = $hasher->hash($otp);

        self::assertNotSame('123456', $hash);
        self::assertTrue($hasher->verify($otp, $hash));
        self::assertFalse($hasher->verify(OtpCode::fromString('000000'), $hash));
    }
}
