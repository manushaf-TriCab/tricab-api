<?php
declare(strict_types=1);
/**
 * Run a .sql file against Azure SQL using the authentication the API itself uses.
 *
 * Replaces `platform/run-sql-azure.php`, a one-off helper that had two faults:
 * it sent a whole file as a single statement, so it could not run anything
 * declaring a trigger, and its own reporting query said `identity.Permissions`
 * unbracketed, which fails because IDENTITY is a reserved T-SQL keyword. The
 * second fault meant it errored before executing anything — safe, but useless.
 *
 * Why this exists at all: `rebuild-local-db.ps1` drives sqlcmd and is the right
 * tool on Windows. `sqlcmd -G` asks for Azure AD *Integrated* authentication,
 * which does not work on macOS and hangs until the login timeout, and `-G -U`
 * prompts for a password that must never be an M365 password. The access-token
 * path below is what AzureSqlPdoFactory does, so if the API can reach the
 * database, so can this.
 *
 * Dry run is the DEFAULT: it lists the batches and executes nothing.
 *
 * Usage:
 *   php bin/run-azure-sql-script.php <file.sql>                    # dry run
 *   php bin/run-azure-sql-script.php <file.sql> --apply
 *   php bin/run-azure-sql-script.php <file.sql> --apply --database=tc --server=tricab-mel.database.windows.net
 *
 * Exit codes: 1 usage or connection, 2 wrong database, 3 a batch failed.
 */

use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

/** @var list<string> $argvList */
$argvList = $argv ?? [];

$scriptPath = '';
$server = 'tricab-mel.database.windows.net';
$database = 'tc';

foreach (array_slice($argvList, 1) as $arg) {
    if (str_starts_with($arg, '--server=')) {
        $server = substr($arg, 9);
    } elseif (str_starts_with($arg, '--database=')) {
        $database = substr($arg, 11);
    } elseif (!str_starts_with($arg, '--') && $scriptPath === '') {
        $scriptPath = $arg;
    }
}

$apply = in_array('--apply', $argvList, true);
$acknowledged = in_array('--i-understand-this-can-destroy-data', $argvList, true);

if ($scriptPath === '' || !is_readable($scriptPath)) {
    fwrite(STDERR, "usage: php bin/run-azure-sql-script.php <file.sql> [--apply] [--server=...] [--database=...]\n");
    exit(1);
}

$sql = (string) file_get_contents($scriptPath);

/**
 * Split on `GO`.
 *
 * GO is a sqlcmd batch separator, not T-SQL: sending it to the server is a
 * syntax error. It has to be a line of its own, which is why the pattern is
 * anchored — a column called `GO` or the word inside a comment is not a
 * separator.
 *
 * @return list<string>
 */
$splitBatches = static function (string $script): array {
    $parts = preg_split('/^\s*GO\s*$/mi', $script);

    if ($parts === false) {
        return [$script];
    }

    return array_values(array_filter(
        array_map('trim', $parts),
        static fn (string $batch): bool => $batch !== '',
    ));
};

$batches = $splitBatches($sql);

/** Strip comments before looking for destructive verbs, so a comment cannot arm the guard. */
$withoutComments = (string) preg_replace(['#/\*.*?\*/#s', '#--[^\n]*#'], '', $sql);
$destructive = preg_match('/\b(DROP\s+(TABLE|DATABASE|SCHEMA)|TRUNCATE\s+TABLE|DELETE\s+FROM)\b/i', $withoutComments) === 1;

printf("server    %s\ndatabase  %s\nscript    %s\nbatches   %d\n", $server, $database, basename($scriptPath), count($batches));

if ($destructive) {
    print("contains  DROP / TRUNCATE / DELETE\n");
}

print("\n");

if (!$apply) {
    foreach ($batches as $index => $batch) {
        $withoutLeadingComment = (string) preg_replace('#/\*.*?\*/#s', '', $batch);
        $firstLine = strtok(trim($withoutLeadingComment), "\n");
        printf("  %2d  %s\n", $index + 1, $firstLine === false ? '(empty)' : trim($firstLine));
    }

    print("\nDRY RUN. Nothing executed. Re-run with --apply.\n");
    exit(0);
}

if ($destructive && !$acknowledged) {
    fwrite(STDERR, "REFUSING: this script drops, truncates or deletes. Add --i-understand-this-can-destroy-data.\n");
    exit(1);
}

$tokenJson = shell_exec('az account get-access-token --resource https://database.windows.net/ --output json 2>/dev/null');
$token = is_string($tokenJson) ? json_decode($tokenJson, true) : null;

if (!is_array($token) || !is_string($token['accessToken'] ?? null)) {
    fwrite(STDERR, "Could not get an Azure SQL access token. Is `az login` current?\n");
    exit(1);
}

// The token is a DSN keyword. There is no PDO::SQLSRV_ATTR_ACCESS_TOKEN — that
// is a sqlsrv_connect() option, not a PDO one. Mirrors
// AzureSqlPdoFactory::buildAccessTokenDsn().
$dsn = sprintf(
    'sqlsrv:server = %s; Database = %s; AccessToken = %s; Encrypt = yes; TrustServerCertificate = no; LoginTimeout = 60',
    $server,
    $database,
    $token['accessToken'],
);

try {
    $pdo = new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        // These scripts declare table variables and run TRY/CATCH. Routing them
        // through sp_prepare fails; direct execution is required.
        PDO::SQLSRV_ATTR_DIRECT_QUERY => true,
    ]);
} catch (Throwable $exception) {
    // Never let the DSN reach the message: it carries the access token.
    fwrite(STDERR, 'Connection failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

/*
 * There are two Azure SQL servers and each has a `tc` database. Only
 * tricab-mel has the identity schema, and a script aimed at the other one fails
 * halfway through with "Invalid object name". Check before writing anything.
 *
 * Bracketed deliberately: IDENTITY is a reserved keyword, and the unbracketed
 * form is what broke the helper this replaces.
 */
$check = $pdo->query("SELECT CASE WHEN OBJECT_ID(N'[identity].[Users]', N'U') IS NULL THEN 0 ELSE 1 END");

if ($check === false || (int) ($check->fetchColumn() ?: 0) !== 1) {
    fwrite(STDERR, sprintf("REFUSING: [identity].[Users] is not on %s/%s. Wrong server?\n", $server, $database));
    exit(2);
}

foreach ($batches as $index => $batch) {
    try {
        $statement = $pdo->query($batch);

        if ($statement !== false) {
            do {
                try {
                    /** @var list<array<string, mixed>> $rows */
                    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $row) {
                        print('      -> ' . json_encode($row) . "\n");
                    }
                } catch (PDOException) {
                    // This batch produced no result set, which is the normal case.
                }
            } while ($statement->nextRowset());
        }

        printf("  %2d  ok\n", $index + 1);
    } catch (Throwable $exception) {
        fwrite(STDERR, sprintf(
            "\n  %2d  FAILED: %s\n\nThe batch that failed:\n%s\n",
            $index + 1,
            $exception->getMessage(),
            $batch,
        ));
        fwrite(STDERR, "\nEarlier batches have been applied. These scripts guard every object, so fix and re-run.\n");
        exit(3);
    }
}

print("\nAll batches executed.\n");
