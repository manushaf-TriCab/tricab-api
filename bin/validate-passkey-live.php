<?php

declare(strict_types=1);

/**
 * Live Guest Passkey validation against non-production Azure SQL + webauthn-lib.
 *
 * Does not send Graph email. Captures the setup URL in-process.
 * Does not print raw tokens, JWTs, cookies, challenges, or private keys.
 *
 * Usage (from tricab-api):
 *   php bin/validate-passkey-live.php
 *   PASSKEY_LIVE_TEST_GUEST_EMAIL=guest@example.com php bin/validate-passkey-live.php
 */

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use TriCab\Api\Bootstrap\AppBuilder;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Config\Settings;
use TriCab\Api\Identity\Email\DevelopmentGuestPasskeyEmailSender;
use TriCab\Api\Identity\Passkey\WebauthnLibPasskeyCeremony;
use TriCab\Api\Infrastructure\Database\SqlBinary;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\Service\RequestGuestPasskeySetupLinkService;
use TriCab\Core\Identity\Contract\GuestPasskeyEmailSender;
use TriCab\Core\Identity\Contract\PasskeyCeremony;
use TriCab\Core\Identity\Domain\Service\PasskeyTokenHasher;

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';
CoreAutoload::register();

$settings = Environment::settings($basePath);
if ($settings->isProduction()) {
    fwrite(STDERR, "STOP: APP_ENV=production. Live passkey validation is not allowed.\n");
    exit(4);
}
if (!$settings->usesAzureSqlUserRepository()) {
    fwrite(STDERR, "STOP: USER_REPOSITORY is not azure-sql.\n");
    exit(4);
}

$env = Environment::load($basePath);
$liveSettings = Settings::fromEnvironment(array_merge($env, [
    'GUEST_EMAIL_PROVIDER' => 'development',
    'PASSKEY_FAKE_CEREMONY' => 'false',
]));
$container = ContainerFactory::create($liveSettings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);

$envRows = $db->fetchAll('SELECT @@SERVERNAME AS [serverName], DB_NAME() AS [dbName]');
$server = (string) ($envRows[0]['serverName'] ?? '');
$database = (string) ($envRows[0]['dbName'] ?? '');
if (!str_contains(strtolower($server), 'tricab-mel') || strtolower($database) !== 'tc') {
    fwrite(STDERR, "STOP: target is {$server} / {$database}; expected tricab-mel / tc\n");
    exit(4);
}

$ceremony = $container->get(PasskeyCeremony::class);
$report = [
    'database' => ['server' => $server, 'database' => $database, 'appEnv' => $liveSettings->appEnv],
    'ceremony' => $ceremony::class,
    'fakeCeremony' => !($ceremony instanceof WebauthnLibPasskeyCeremony),
    'rpId' => $liveSettings->passkeyRpId,
    'allowedOrigins' => $liveSettings->passkeyAllowedOrigins,
    'publicAppBaseUrl' => $liveSettings->passkeyPublicAppBaseUrl,
    'schema' => [],
    'setupLink' => [],
    'registrationOptions' => [],
    'invalidTokens' => [],
];

$tables = [
    'UserWebAuthnHandles',
    'UserPasskeys',
    'GuestPasskeySetupTokens',
    'PasskeyChallenges',
    'PasskeyRegistrationSessions',
];
foreach ($tables as $table) {
    $rows = $db->fetchAll(
        "SELECT CASE WHEN OBJECT_ID(N'[identity].[{$table}]', N'U') IS NULL THEN 0 ELSE 1 END AS [TableExists]",
    );
    $report['schema'][$table] = ((int) ($rows[0]['TableExists'] ?? 0) === 1) ? 'present' : 'missing';
}

$columnProbe = $db->fetchAll(
    "SELECT c.[TABLE_NAME], c.[COLUMN_NAME]
     FROM INFORMATION_SCHEMA.COLUMNS c
     WHERE c.[TABLE_SCHEMA] = N'identity'
       AND c.[TABLE_NAME] IN (N'GuestPasskeySetupTokens', N'UserPasskeys')
       AND c.[COLUMN_NAME] IN (N'TokenHash', N'Token', N'SetupToken', N'PrivateKey', N'CredentialId', N'PublicKey', N'ExpiresAtUtc', N'ConsumedAtUtc')
     ORDER BY c.[TABLE_NAME], c.[COLUMN_NAME]",
);
$columnNames = [];
foreach ($columnProbe as $row) {
    $columnNames[] = (string) $row['TABLE_NAME'] . '.' . (string) $row['COLUMN_NAME'];
}
$report['schema']['columns'] = $columnNames;
$report['schema']['rawTokenColumnPresent'] = in_array('GuestPasskeySetupTokens.Token', $columnNames, true)
    || in_array('GuestPasskeySetupTokens.SetupToken', $columnNames, true);
$report['schema']['privateKeyColumnPresent'] = in_array('UserPasskeys.PrivateKey', $columnNames, true);

$constraints = $db->fetchAll(
    "SELECT kc.[TABLE_NAME], kc.[CONSTRAINT_NAME], kc.[CONSTRAINT_TYPE]
     FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS kc
     WHERE kc.[TABLE_SCHEMA] = N'identity'
       AND kc.[TABLE_NAME] IN (N'UserWebAuthnHandles', N'UserPasskeys', N'GuestPasskeySetupTokens', N'PasskeyChallenges', N'PasskeyRegistrationSessions')
     ORDER BY kc.[TABLE_NAME], kc.[CONSTRAINT_TYPE], kc.[CONSTRAINT_NAME]",
);
$report['schema']['constraints'] = array_map(static function (array $row): string {
    return (string) $row['TABLE_NAME'] . ' ' . (string) $row['CONSTRAINT_TYPE'] . ' ' . (string) $row['CONSTRAINT_NAME'];
}, $constraints);

$jsonRequest = static function (string $method, string $path, array $body = [], array $headers = []): ServerRequestInterface {
    $request = (new ServerRequestFactory())->createServerRequest($method, $path)
        ->withHeader('Content-Type', 'application/json')
        ->withHeader('Origin', 'http://localhost:5173');
    foreach ($headers as $name => $value) {
        $request = $request->withHeader($name, $value);
    }
    $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));
    $request->getBody()->rewind();

    return $request;
};

$readJson = static function (ResponseInterface $response): array {
    $response->getBody()->rewind();
    $decoded = json_decode((string) $response->getBody(), true);

    return is_array($decoded) ? $decoded : [];
};

/** @var App<ContainerInterface> $app */
$app = AppBuilder::build($container);
$diagnostics = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/api/v1/dev/passkey-diagnostics'));
$diagJson = $readJson($diagnostics);
$report['diagnosticsStatus'] = $diagnostics->getStatusCode();
$report['diagnostics'] = is_array($diagJson['data'] ?? null) ? $diagJson['data'] : null;

$guestEmail = getenv('PASSKEY_LIVE_TEST_GUEST_EMAIL');
if (!is_string($guestEmail) || trim($guestEmail) === '') {
    $guestRows = $db->fetchAll(
        <<<'SQL'
SELECT TOP 1 u.[EmailAddress]
FROM [identity].[Users] u
INNER JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId]
INNER JOIN [identity].[UserRoles] ur ON ur.[UserId] = u.[UserId] AND ur.[IsActive] = 1
INNER JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId] AND r.[IsActive] = 1
WHERE s.[AllowsLogin] = 1
  AND LOWER(s.[StatusCode]) = N'active'
  AND UPPER(r.[RoleCode]) = N'GUEST'
ORDER BY
  CASE
    WHEN LOWER(u.[EmailAddress]) LIKE N'%@example.com' THEN 0
    WHEN LOWER(u.[EmailAddress]) LIKE N'%test%' THEN 1
    WHEN LOWER(u.[EmailAddress]) LIKE N'%guest%' THEN 2
    ELSE 3
  END,
  u.[UserId]
SQL,
    );
    $guestEmail = isset($guestRows[0]['EmailAddress']) ? (string) $guestRows[0]['EmailAddress'] : '';
}

$report['setupLink']['guestEmailSelected'] = $guestEmail !== '';
$report['setupLink']['guestEmailDomain'] = $guestEmail !== '' && str_contains($guestEmail, '@')
    ? substr($guestEmail, (int) strrpos($guestEmail, '@') + 1)
    : null;

if ($guestEmail !== '') {
    /** @var RequestGuestPasskeySetupLinkService $setup */
    $setup = $container->get(RequestGuestPasskeySetupLinkService::class);
    $setup->execute($guestEmail, '127.0.0.1');
    $sender = $container->get(GuestPasskeyEmailSender::class);
    if (!$sender instanceof DevelopmentGuestPasskeyEmailSender) {
        fwrite(STDERR, "STOP: expected DevelopmentGuestPasskeyEmailSender for capture.\n");
        exit(5);
    }
    $last = $sender->lastMessage();
    $setupUrl = is_array($last) ? (string) ($last['setupUrl'] ?? '') : '';
    $query = parse_url($setupUrl, PHP_URL_QUERY);
    $params = [];
    if (is_string($query)) {
        parse_str($query, $params);
    }
    $raw = is_string($params['t'] ?? null) ? (string) $params['t'] : '';
    $report['setupLink']['urlHasOnlyT'] = array_keys($params) === ['t'];
    $report['setupLink']['opaqueTokenLength'] = strlen($raw);
    $report['setupLink']['emailCapturedInProcess'] = true;

    $hashRows = $db->fetchAll(
        'SELECT COUNT(*) AS [cnt] FROM [identity].[GuestPasskeySetupTokens] WHERE [TokenHash] = :hash',
        [':hash' => new SqlBinary(PasskeyTokenHasher::hash($raw))],
    );
    $report['setupLink']['dbStoresMatchingHash'] = ((int) ($hashRows[0]['cnt'] ?? 0)) === 1;

    $rawScan = $db->fetchAll(
        "SELECT COUNT(*) AS [cnt] FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = N'identity' AND TABLE_NAME = N'GuestPasskeySetupTokens'
           AND COLUMN_NAME IN (N'Token', N'SetupToken', N'RawToken')",
    );
    $report['setupLink']['rawTokenColumnAbsent'] = ((int) ($rawScan[0]['cnt'] ?? 0)) === 0;

    $verify = $app->handle($jsonRequest('POST', '/api/v1/auth/guest/passkey/setup-link/verify', [
        'token' => $raw,
    ]));
    $verifyJson = $readJson($verify);
    $verifyData = is_array($verifyJson['data'] ?? null) ? $verifyJson['data'] : [];
    $report['setupLink']['verifyStatus'] = $verify->getStatusCode();
    $report['setupLink']['verifyIssuesAccessToken'] = array_key_exists('accessToken', $verifyData);
    $session = is_string($verifyData['registrationSessionToken'] ?? null)
        ? (string) $verifyData['registrationSessionToken']
        : '';

    $malformed = $app->handle($jsonRequest('POST', '/api/v1/auth/guest/passkey/setup-link/verify', [
        'token' => '%%%',
    ]));
    $unknown = $app->handle($jsonRequest('POST', '/api/v1/auth/guest/passkey/setup-link/verify', [
        'token' => str_repeat('ab', 32),
    ]));
    $report['invalidTokens'] = [
        'malformedStatus' => $malformed->getStatusCode(),
        'unknownStatus' => $unknown->getStatusCode(),
    ];

    if ($session !== '') {
        $options = $app->handle($jsonRequest(
            'POST',
            '/api/v1/auth/passkeys/registration/options',
            [],
            ['X-TriCab-Passkey-Registration-Session' => $session],
        ));
        $optionsJson = $readJson($options);
        $optionsData = is_array($optionsJson['data'] ?? null) ? $optionsJson['data'] : [];
        $publicKey = is_array($optionsData['publicKey'] ?? null) ? $optionsData['publicKey'] : [];
        $rp = is_array($publicKey['rp'] ?? null) ? $publicKey['rp'] : [];
        $selection = is_array($publicKey['authenticatorSelection'] ?? null) ? $publicKey['authenticatorSelection'] : [];
        $report['registrationOptions'] = [
            'status' => $options->getStatusCode(),
            'rpId' => $rp['id'] ?? null,
            'residentKey' => $selection['residentKey'] ?? null,
            'userVerification' => $selection['userVerification'] ?? null,
            'challengeIdPresent' => isset($optionsData['challengeId']),
            'challengeBytesOmittedFromReport' => true,
        ];

        $authOptions = $app->handle($jsonRequest('POST', '/api/v1/auth/passkeys/authentication/options', []));
        $report['authenticationOptionsStatus'] = $authOptions->getStatusCode();
    }
}

$out = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
echo $out === false ? "{}\n" : $out . "\n";
if ($ceremony instanceof WebauthnLibPasskeyCeremony) {
    exit(0);
}
fwrite(STDERR, "STOP: live ceremony is not WebauthnLibPasskeyCeremony\n");
exit(6);
