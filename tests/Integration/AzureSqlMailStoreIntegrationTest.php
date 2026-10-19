<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Mail\Persistence\AzureSqlMailStore;
use TriCab\Api\Tests\Mail\MailStoreBehaviour;
use TriCab\Core\Mail\Contract\MailStore;

/**
 * The same behaviour the in-memory store is held to, run against the real SQL in `create-mail-schema.sql`.
 *
 * Opt-in: `MAIL_SQL_STORE_TESTS=true` and a `.env` pointing at the LOCAL database (`use-db.sh local`, with
 * `create-mail-schema.sql` applied). It deletes every row in `[mail]` before each test, so it refuses to run
 * against anything but localhost: never point it at tricab-mel.
 *
 * @group azure-sql
 */
#[Group('azure-sql')]
final class AzureSqlMailStoreIntegrationTest extends MailStoreBehaviour
{
    protected function newStore(): MailStore
    {
        if (getenv('MAIL_SQL_STORE_TESTS') !== 'true') {
            self::markTestSkipped('Set MAIL_SQL_STORE_TESTS=true (local database only) to run the mail SQL store tests.');
        }
        if (!extension_loaded('pdo_sqlsrv')) {
            self::markTestSkipped('pdo_sqlsrv extension is not installed.');
        }
        $env = Environment::load(dirname(__DIR__, 2));
        if (!in_array($env['DB_HOST'] ?? '', ['localhost', '127.0.0.1'], true)) {
            self::fail('Refusing to empty [mail] on a database that is not this machine.');
        }
        $settings = Settings::fromEnvironment(array_merge($env, [
            'USER_REPOSITORY' => Settings::USER_REPOSITORY_AZURE_SQL,
            'GUEST_JWT_SECRET' => $env['GUEST_JWT_SECRET'] ?? str_repeat('t', 64),
        ]));
        $container = ContainerFactory::create($settings);
        $connection = $container->get(SqlConnection::class);
        self::assertInstanceOf(SqlConnection::class, $connection);
        // Children first: the foreign keys run threads <- messages <- assessments <- decisions.
        foreach (['activity', 'message_links', 'label_decisions', 'assessments', 'attachments', 'messages', 'threads', 'mailboxes'] as $table) {
            $connection->execute('DELETE FROM [mail].[' . $table . ']');
        }
        $store = $container->get(MailStore::class);
        self::assertInstanceOf(AzureSqlMailStore::class, $store);

        return $store;
    }
}
