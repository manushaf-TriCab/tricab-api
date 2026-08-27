# Phase 13.7 — Cart Schema Proposal

**Status:** `CART_SCHEMA_APPROVAL_REQUIRED`  
**Do not apply automatically.**

## Why

New TriCab web cart is UI-only (`cartStore` + badge localStorage).  
Durable, server-authoritative commercial carts require Azure SQL tables.

## Proposed model (minimal)

```sql
-- schema: commerce (or cart)
CREATE TABLE [commerce].[Carts] (
  [CartId] UNIQUEIDENTIFIER NOT NULL PRIMARY KEY DEFAULT NEWSEQUENTIALID(),
  [PublicId] UNIQUEIDENTIFIER NOT NULL,
  [OwnerUserId] INT NOT NULL,
  [EffectiveCustomerId] INT NULL,
  [PricingMode] NVARCHAR(40) NOT NULL, -- SELF_CUSTOMER | STAFF_SELECTED_CUSTOMER
  [CurrencyCode] NVARCHAR(3) NOT NULL,
  [Status] NVARCHAR(30) NOT NULL, -- OPEN | SUBMITTED | ABANDONED
  [CreatedAtUtc] DATETIME2 NOT NULL,
  [UpdatedAtUtc] DATETIME2 NOT NULL,
  [DeletedAtUtc] DATETIME2 NULL
);

CREATE TABLE [commerce].[CartLines] (
  [CartLineId] UNIQUEIDENTIFIER NOT NULL PRIMARY KEY DEFAULT NEWSEQUENTIALID(),
  [CartId] UNIQUEIDENTIFIER NOT NULL,
  [ProductVariantId] INT NOT NULL,
  [Quantity] INT NOT NULL,
  [Length] INT NULL,
  [UomCode] NVARCHAR(10) NULL,
  [Availability] NVARCHAR(20) NULL,
  [PricingStatus] NVARCHAR(40) NOT NULL,
  [UnitSellPrice] DECIMAL(18,6) NULL,
  [LineTotal] DECIMAL(18,2) NULL,
  [CurrencyCode] NVARCHAR(3) NULL,
  [MarginSource] NVARCHAR(40) NULL, -- TMMKTM | STAFF_OVERRIDE
  [ResolvedAtUtc] DATETIME2 NULL,
  [CreatedAtUtc] DATETIME2 NOT NULL,
  [UpdatedAtUtc] DATETIME2 NOT NULL,
  [DeletedAtUtc] DATETIME2 NULL
);
```

No base cost / tmktm id / PriceListId columns on customer-visible DTOs.

## Interim (implemented now)

`POST /api/v1/products/{slug}/cart/items/prepare`  
→ server resolves price  
→ returns snapshot  
→ `cartPersistence: CART_SCHEMA_APPROVAL_REQUIRED`  
→ UI may stage badge via non-authoritative `cartStore` for UX only

## After approval

Implement:

- `POST /api/v1/cart/items`
- `GET /api/v1/cart`
- reprice / remove / customer-switch rules
- Cart → RFQ convert with freshness check
