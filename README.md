# TriCab Core

Framework-independent PHP domain and application library for the TriCab Platform Rebuild.

## Boundaries

TriCab Core owns:

- domain entities and value objects
- business rules
- application use cases / handlers
- repository contracts
- domain and application exceptions

TriCab Core does **not** depend on Slim, PDO, Azure SQL, Microsoft Entra SDKs, HTTP messages, PHP sessions, React, application config, or environment variables.

Infrastructure (routing, auth token validation, SQL repositories, DI, JSON responses) belongs in the API application.

## Requirements

- PHP 8.3+
- Composer

## Install (local path package)

From a consuming application:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../tricab-core",
            "options": {
                "symlink": true
            }
        }
    ],
    "require": {
        "tricab/tricab-core": "dev-main"
    }
}
```

## Development

```bash
composer install
composer test
composer analyse
composer check
```

## Namespace

```text
TriCab\Core\
```
