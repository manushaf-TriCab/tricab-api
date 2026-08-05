# Product Module — Phase 7: Product Management Admin UI

Status: complete. Product Management is wired into the existing TriCab Admin Dashboard using Customer Management patterns and Phase 6 API hooks. No TanStack Query, no `features/` folder, no Blob uploads.

## 1. Inspection report

| Area | Finding |
|---|---|
| Dashboard | `AdminLayout` + Sidebar + Outlet routes under `/admin` |
| Routing | Lazy routable modules (`customers`, `contacts`, …) vs local placeholders |
| Shared UI | Inline Tailwind + `admin-glass`; `AdminAccessGate`; `ConfirmActionModal` |
| Forms | Framer-motion modals (CustomerFormModal pattern) |
| Tables | Domain tables with sort headers, actions menus, pagination |
| Feedback | Inline error/success — no toast library |
| Permissions | `hasPermission` + colocated `PRODUCT_*` helpers |
| Tests | Vitest + Testing Library + `vi.mock` API modules |

## 2. Page inventory

| Page | Route | Permission | Hooks | Main actions |
|---|---|---|---|---|
| Product list | `/admin/products` | `PRODUCT.READ` | `useAdminProducts`, `useProductLookups`, create/update/archive/soft-delete mutations | Search, filter, sort, paginate, create, archive, soft-delete |
| Product editor | `/admin/products/:publicId` | `PRODUCT.READ` (+ tab-specific) | Detail, readiness, lookups, lazy tab hooks | Edit overview, publish/unpublish, archive/restore, soft-delete, tab workflows |

## 3. Component inventory

| Component | Purpose |
|---|---|
| `ProductManagementPage` | List workspace |
| `ProductDetailsPage` | Tabbed editor workspace |
| `ProductFiltersToolbar` | Debounced search + lookup filters |
| `ProductTable` | Catalog table + actions |
| `ProductFormModal` | Create / overview edit |
| `ProductStatusBadge` / `ProductLifecycleBadge` | Status chips |
| `ConcurrencyConflictPanel` | `PRODUCT_CONCURRENCY_CONFLICT` UI |
| `PublicationReadinessPanel` | Errors, warnings, tab deep-links |
| `ProductEditorPanels` | Variants, specs, coding, media, docs, markets, relationships, SEO, pricing, audit |
| `productEditorTabs.ts` | Permission-aware tab definitions |

## 4. Files created / modified

### Created

- `src/components/admin/products/ProductManagementPage.tsx`
- `src/components/admin/products/ProductManagementPage.test.tsx`
- `src/components/admin/products/ProductDetailsPage.tsx`
- `src/components/admin/products/ProductDetailsPage.test.tsx`
- `src/components/admin/products/ProductFiltersToolbar.tsx`
- `src/components/admin/products/ProductTable.tsx`
- `src/components/admin/products/ProductFormModal.tsx`
- `src/components/admin/products/ProductStatusBadge.tsx`
- `src/components/admin/products/ProductEditorPanels.tsx`
- `src/components/admin/products/PublicationReadinessPanel.tsx`
- `src/components/admin/products/ConcurrencyConflictPanel.tsx`
- `src/components/admin/products/productEditorTabs.ts`
- `tricab-core/docs/product-phase7-admin-ui.md`

### Modified

- `src/App.tsx` — `/admin/products` routes
- `src/components/admin/AdminLayout.tsx` — routable `products` module
- `src/components/admin/Sidebar.tsx` / `useCommandPalette.ts` — `PRODUCT.READ` visibility
- `src/components/admin/data/mockDashboard.ts` — Catalog label

## 5. Permission matrix (UI)

| Action | Permission |
|---|---|
| View list/detail | `PRODUCT.READ` |
| Create | `PRODUCT.CREATE` |
| Edit overview / soft-delete / markets / SEO writes | `PRODUCT.UPDATE` |
| Publish / unpublish | `PRODUCT.PUBLISH` |
| Archive | `PRODUCT.ARCHIVE` |
| Restore archive / deletion | `PRODUCT.RESTORE` |
| Variants write | `PRODUCT.VARIANTS.MANAGE` |
| Specs write | `PRODUCT.SPECIFICATIONS.MANAGE` |
| Media write | `PRODUCT.MEDIA.MANAGE` |
| Documents write | `PRODUCT.DOCUMENTS.MANAGE` |
| Relationships | `PRODUCT.RELATIONSHIPS.MANAGE` |
| Coding | `PRODUCT.CODES.MANAGE` |
| Pricing tab | `PRODUCT.PRICING.VIEW` |
| Audit tab | `PRODUCT.AUDIT.VIEW` |

API middleware remains authoritative.

## 6. RowVersion report

- Product list/detail store Base64 `rowVersion`
- Overview updates send `expectedRowVersion`
- Lifecycle actions send product RowVersion
- Variants use separate aggregate tokens in API clients
- Conflicts → `ConcurrencyConflictPanel` (no auto-retry, no force overwrite)
- Reload refreshes detail + readiness

## 7. Publication UI

- Header shows ready/blocked
- Publication tab lists errors + warnings (warnings stay when ready)
- Issue → tab links (markets, seo, variants, …)
- Publish confirmation blocked when readiness errors exist

## 8. Mock-data report

| Source | Admin Product UI | Status |
|---|---|---|
| Phase 6 Product APIs | **Used** | Live list/editor |
| `productDetails.ts` / catalog marketing mocks | **Unchanged** | Public marketing only |
| Admin products placeholder | Replaced by routable Catalog | — |

## 9. API gaps reflected in UI

- No GET for specifications / relationships / markets collections → editor uses detail codes + replace PUT + explicit forms
- No Blob upload → media/docs clearly labeled metadata-only; no `input[type=file]`
- Product coding requires rule-set public ID (lookups do not yet expose rules)

## 10. Tests

```bash
cd tricab-web
npm test -- src/components/admin/products
npm test
npm run typecheck
```

Results at completion:

- Product admin UI tests: pass
- Full frontend suite: **65 tests** pass
- TypeScript: pass

## 11. Deferred

- Public Product page redesign
- Blob / raw file uploads
- ERP / migration / Azure AI Search
- Live Azure SQL Product E2E
- Production deployment
- Data-router `useBlocker` (project uses `BrowserRouter`; unsaved protection uses `beforeunload` + back-link confirm)
