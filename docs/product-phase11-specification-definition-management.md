# Phase 11 — Technical Specification Definition Management

## Status

Implemented and ready for approval. Value assignment (Phase 4/5) remains unchanged; this phase adds **definition master-data** administration.

## Inspection summary (Step 1)

Existing before Phase 11:

- Tables: `SpecificationGroups`, `SpecificationDefinitions`, `SpecificationOptions`, product/variant value tables
- No units table (units were free-text `NVARCHAR(50)` on definitions)
- Core assign services + admin PUT value endpoints
- Thin lookups for definitions; no definition CRUD UI

## Hierarchy

```
SpecificationGroup
  └── SpecificationDefinition (data type, scope, validation, display)
        └── SpecificationOption (single/multi select)
SpecificationUnit (reusable lookup; symbol copied onto Definition.Unit)
```

## Entities / DTOs

- `SpecificationGroupRecord`
- `SpecificationDefinitionRecord` (+ nested options + `SpecificationValidationRules`)
- `SpecificationOptionRecord`
- `SpecificationUnitRecord`
- `SpecificationUsage`
- `SpecificationImportResult`

## ValidationRulesJson

Stored on definitions. Shape:

- regex, minLength, maxLength, unique
- placeholder, helpText, defaultValue, validationMessage
- isVisible, isAdminOnly, isPublicVisible, allowExport, allowImport

Column-backed fields remain: MinimumValue, MaximumValue, DecimalPrecision, IsRequired, IsFilterable, IsSearchable, IsComparable, DisplayOrder, ScopeCode, DataTypeCode, Unit.

## Data types (supported only)

text, long-text, integer, decimal, boolean, date, url, single-select, multi-select, measurement, range

## Scope

- `product` — product values only
- `variant` — variant values only
- `both` — either

Assign services already enforce scope.

## Services

- `SpecificationGroupService`
- `SpecificationDefinitionService` (includes import/export)
- `SpecificationOptionService`
- `SpecificationUnitService`

Each supports create/update/activate/deactivate/archive/restore/reorder/usage with RowVersion concurrency (no auto-retry).

## Repository

Extended `ProductSpecificationRepository` (no duplicate interface). In-memory + Azure SQL admin collaborator (`AzureSqlSpecificationDefinitionAdmin`).

## Schema addition

`[product].[SpecificationUnits]` added to development create scripts + SSDT. Seed units: V, A, mm², mm, kg/km, °C, kV, Hz, N, m.

## API inventory

| Method | Route | Permission |
|--------|-------|------------|
| GET/POST | `/api/v1/admin/specification-groups` | READ / SPECIFICATIONS.MANAGE |
| PUT + status + usage + reorder | `/api/v1/admin/specification-groups...` | as above |
| GET/POST + import/export | `/api/v1/admin/specification-definitions` | READ / MANAGE |
| CRUD + status | `/api/v1/admin/specification-units` | READ / MANAGE |
| CRUD + status | `/api/v1/admin/specification-options` | READ / MANAGE |

Existing value endpoints unchanged:

- `PUT /api/v1/admin/products/{id}/specifications`
- `PUT /api/v1/admin/product-variants/{id}/specifications`

## UI inventory

| Page | Route |
|------|-------|
| Specification Groups | `/admin/products/specification-groups` |
| Specification Definitions | `/admin/products/specification-definitions` |
| Units | `/admin/products/specification-units` |
| Options | `/admin/products/specification-options` |

Hooks/clients: `src/api/admin/products/specificationApi.ts`  
Product editor: dynamic `SpecificationsPanel` loads definitions by type/group/scope.

## Import / Export

- JSON `{ "definitions": [ ... ] }`
- Definitions (+ nested options); **no product values**
- Summary: created / updated / skipped / errors

## Usage protection

Archive blocked when:

- Group has definitions
- Definition has product/variant values
- Option is referenced by values
- Unit symbol is used by definitions

Deactivate remains allowed.

## Permissions

- Manage: `PRODUCT.SPECIFICATIONS.MANAGE`
- Read: `PRODUCT.READ`

## Tests

- Core: `SpecificationMasterServicesTest` (4)
- API: `SpecificationDefinitionEndpointsTest` (3)

## Deferred

- ERP integration
- Legacy migration
- AI Search
- Production deployment
- Specification templates
- Localization of specification definitions
- Dedicated Admin GET for current product values (editor still replaces full set)

## Files (high level)

**Created:** core DTOs/commands/services/exceptions; in-memory + Azure admin; API actions/requests; React `specificationApi.ts` + management pages; schema units; this doc.

**Modified:** `ProductSpecificationRepository`, Azure/InMemory specification repos, AppBuilder routes, ContainerFactory, error mapper/serializer, React App/nav/product editor panel.
