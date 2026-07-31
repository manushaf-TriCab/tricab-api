<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Identity;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;
use TriCab\Api\Identity\Authentication\EntraJwtValidationDebugLogger;

final class EntraJwtValidationDebugLoggerTest extends TestCase
{
    public function test_classifies_common_failure_reasons(): void
    {
        $logger = new EntraJwtValidationDebugLogger(Settings::fromEnvironment([
            'APP_ENV' => 'development',
        ]));

        self::assertSame('expired token', $logger->classifyFailure('Access token has expired.'));
        self::assertSame('invalid signature', $logger->classifyFailure('Access token signature is invalid.'));
        self::assertSame('invalid audience', $logger->classifyFailure('Access token audience is invalid.'));
        self::assertSame('invalid issuer', $logger->classifyFailure('Access token issuer is invalid.'));
        self::assertSame('missing scope', $logger->classifyFailure('Required delegated scope "access_as_user" is missing.'));
        self::assertSame('missing oid', $logger->classifyFailure('Required identity claim "oid" is missing.'));
        self::assertSame('missing tid', $logger->classifyFailure('Access token tenant is invalid.'));
        self::assertSame('JWKS download failure', $logger->classifyFailure('Unable to download Microsoft Entra JWKS.'));
        self::assertSame('unknown key id (kid)', $logger->classifyFailure('"kid" invalid, unable to lookup correct key'));
    }
}
