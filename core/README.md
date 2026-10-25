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

TriCab documents (quotation, order confirmation, pick list) and the 4js OCR stream reader are in the separate [tricab/docgen](https://github.com/tricab/docgen) package, not in Core.

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

## Document-generation references

- Legacy record dictionary: `docs/legacy-record-dictionary.md` in [tricab/docgen](https://github.com/tricab/docgen)
- [Migration plan](docs/document-generation/migration-plan.md)
