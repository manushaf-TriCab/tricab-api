# TriCab Product Module — Phase 10A: Product Classification Management

**Status:** Complete for non-production approval  
**Date:** 2026-08-06  
**Scope:** Admin management for Product Families, Categories, and Series. No ERP, migration, AI Search, pricing, public redesign, or production deploy.

## Classification hierarchy

```
Product Family
  → Product Category (requires Family)
    → Product Series (requires Category)
      → Product
```

Codes are **globally unique** (`FamilyCode`, `CategoryCode`, `SeriesCode`). Parent FKs are nullable in SQL but required by application rules on create.

## Database tables

| Table | Soft-delete | RowVersion | Notes |
|-------|-------------|------------|-------|
| `[product].[ProductFamilies]` | Yes | Yes | Global UQ on FamilyCode |
| `[product].[ProductCategories]` | Yes | Yes | Global UQ on CategoryCode; FK Family |
| `[product].[ProductSeries]` | Yes | Yes | Global UQ on SeriesCode; FK Category |

## Domain / services

- `ManageProductClassificationService` — create/update/activate/deactivate/archive/restore/reorder/list/get/usage
- Exceptions: `ProductClassificationNotFound`, `ProductClassificationInUse`, `ProductClassificationConcurrencyConflict`, `DuplicateProductClassificationCode`
- Permission: `PRODUCT.READ` (list/get/usage), `PRODUCT.LOOKUPS.MANAGE` (mutations)

## Usage protection

| Action | Family | Category | Series |
|--------|--------|----------|--------|
| Deactivate | Blocked if active categories | Blocked if active series | Allowed (products keep inactive series) |
| Archive | Blocked if categories or products | Blocked if series or products | Blocked if products |

## API inventory

Base paths:

- `/api/v1/admin/product-families`
- `/api/v1/admin/product-categories`
- `/api/v1/admin/product-series`

Per resource: `GET` list, `POST` create, `GET/{id}`, `PUT/{id}`, `POST/{id}/activate|deactivate|archive|restore`, `GET/{id}/usage`, `POST/.../reorder`.

Errors: `PRODUCT_CLASSIFICATION_NOT_FOUND` (404), `PRODUCT_CLASSIFICATION_CONCURRENCY_CONFLICT` (409), `DUPLICATE_PRODUCT_CLASSIFICATION_CODE` (409), `PRODUCT_CLASSIFICATION_IN_USE` (422).

## React

Routes (static before `:publicId`):

- `/admin/products/families`
- `/admin/products/categories`
- `/admin/products/series`

Product create/edit cascading Family → Category → Series with inactive option labels.

## Tests

| Suite | Result |
|-------|--------|
| Core `ManageProductClassificationServiceTest` | 4 OK |
| API `ProductClassificationEndpointsTest` | 3 OK |

## Deferred

- Legacy classification migration / production seed approval
- Public catalogue navigation redesign
- ERP classification mapping
- Azure AI Search indexing
- Production deployment

**Phase 10A stopped pending approval.**
