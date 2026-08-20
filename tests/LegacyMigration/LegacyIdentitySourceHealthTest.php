<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\LegacyMigration;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Product\Migration\RecordingLegacyReadOnlyConnection;
use TriCab\Core\LegacyMigration\Live\LegacyIdentitySourceHealth;
use TriCab\Core\Product\Migration\Live\InMemoryLegacySqlStatementLogger;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;

final class LegacyIdentitySourceHealthTest extends TestCase
{
    public function test_ssl_required_with_empty_cipher_is_unsafe(): void
    {
        $conn = new RecordingLegacyReadOnlyConnection(new LegacyReadOnlySqlGuard(), new InMemoryLegacySqlStatementLogger(), [
            'SELECT DATABASE()' => [['db_name' => 'tricab']],
            'SELECT CURRENT_USER()' => [['session_user_name' => 'ro@%']],
            'SHOW GRANTS' => [['GRANT' => 'GRANT SELECT ON `tricab`.* TO `ro`@`%`']],
            "SHOW STATUS LIKE 'Ssl_cipher'" => [['Variable_name' => 'Ssl_cipher', 'Value' => '']],
        ], 'wordpress');
        $report = (new LegacyIdentitySourceHealth())->inspect($conn, 'wordpress', 'tricab', true, 'required');
        self::assertFalse($report['ssl']);
        self::assertFalse($report['safeForExtraction']);
    }
}
