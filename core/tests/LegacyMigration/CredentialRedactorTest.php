<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\LegacyMigration;

use PHPUnit\Framework\TestCase;
use TriCab\Core\LegacyMigration\Support\CredentialRedactor;

final class CredentialRedactorTest extends TestCase
{
    public function test_strips_password_and_session_fields(): void
    {
        $out = CredentialRedactor::strip([
            'email' => 'a@b.com',
            'user_pass' => 'hash',
            'user_activation_key' => 'key',
            'meta' => [
                'session_tokens' => 'secret',
                'first_name' => 'Ada',
            ],
        ]);
        self::assertSame('a@b.com', $out['email']);
        self::assertArrayNotHasKey('user_pass', $out);
        self::assertArrayNotHasKey('user_activation_key', $out);
        self::assertSame(['first_name' => 'Ada'], $out['meta']);
        $withList = CredentialRedactor::strip([
            'contactMatches' => [['matchType' => 'EXPLICIT_WP_ID', 'user_pass' => 'x']],
        ]);
        self::assertSame('EXPLICIT_WP_ID', $withList['contactMatches'][0]['matchType']);
        self::assertArrayNotHasKey('user_pass', $withList['contactMatches'][0]);
    }
}
