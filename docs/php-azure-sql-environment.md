# PHP Azure SQL environment requirements

## API root

`/Users/thilanh/Documents/Projects/tricab-core/tricab-api`

## Repository switch

```env
USER_REPOSITORY=memory      # default for local tests
USER_REPOSITORY=azure-sql   # AzureSqlUserRepository via PDO
```

## Required environment variables

```env
USER_REPOSITORY=azure-sql
DB_DRIVER=sqlsrv
DB_HOST=tricab-mel.database.windows.net
DB_PORT=1433
DB_DATABASE=tc
DB_USERNAME=<secure-user>
DB_PASSWORD=<secure-secret>
DB_ENCRYPT=yes
DB_TRUST_SERVER_CERTIFICATE=false
```

Database authentication must use an approved SQL database identity, service principal, managed identity, or supported Microsoft Entra access-token flow.

Do **not** place a Microsoft 365 password in configuration.

## Required local dependencies

- PHP 8.3+
- Composer
- Microsoft ODBC Driver 18 for SQL Server
- `pdo_sqlsrv` (and typically `sqlsrv`)

## Expected PDO connection format

```php
new PDO(
    'sqlsrv:Server=tcp:' . $host . ',' . $port . ';Database=' . $database .
    ';Encrypt=yes;TrustServerCertificate=no',
    $username,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
);
```

## Health

```bash
curl http://localhost:8080/health/database
```

## Implementation note

Azure SQL adapters live only in the API layer. TriCab Core remains framework-independent and defines repository contracts + domain behaviour only.
