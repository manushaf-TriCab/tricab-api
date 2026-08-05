# TriCab Core

Framework-independent PHP domain and application library for the TriCab Platform Rebuild.

## Boundaries

TriCab Core owns:

- domain entities and value objects
- business rules
- application use cases / handlers
- repository contracts
- legacy OCR stream parsing and document-generation contracts
- domain and application exceptions

TriCab Core does **not** depend on Slim, PDO, Azure SQL, Microsoft Entra SDKs, HTTP messages, PHP sessions, React, application config, or environment variables.

Infrastructure (routing, auth token validation, SQL repositories, DI, JSON responses) belongs in the API application.

The `DocumentGeneration` module provides the reusable OCR parser, record model, document data projection, `PdfRenderer` contract, and `GenerateOcrPdfHandler`. Concrete PDF engines, templates, assets, and HTTP transport remain in consuming applications such as `tricab-api`.

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

- [Legacy record dictionary](docs/document-generation/legacy-record-dictionary.md)
- [Migration plan](docs/document-generation/migration-plan.md)
