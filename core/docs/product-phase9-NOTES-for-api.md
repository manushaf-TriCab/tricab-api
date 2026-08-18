# Product Phase 9 — NOTES for tricab-api

Core Phase 9 adds upload lifecycle (media/documents/360/AR) with Azure-free ports.
Adapters and Slim wiring live in **tricab-api**.

## Constructor / DTO breaking changes

### `ProductMediaSummary` (optional trailing args — positional callers still work if they only used the first 8)

Previous:
```php
__construct(
  string $publicId,
  string $mediaCategoryCode,
  string $mediaTypeCode,
  string $title,
  ?string $publicUrl,
  ?string $altText,
  int $displayOrder,
  bool $isPrimary,
)
```

Now also accepts (defaults shown):
```php
  ?string $uploadStatusCode = 'active',
  ?string $scanStatusCode = 'not_required',
  ?int $byteSize = null,
  ?string $contentType = null,
  ?string $originalFileName = null,
  ?int $widthPx = null,
  ?int $heightPx = null,
  ?string $thumbnailUrl = null,
  ?string $storagePath = null, // admin serializers may include; omit for public
)
```

### `ProductDocumentSummary`

Previous 8 required fields unchanged; added optional:
```php
  ?string $uploadStatusCode = 'active',
  ?string $scanStatusCode = 'not_required',
  ?int $byteSize = null,
  ?string $contentType = null,
  ?string $originalFileName = null,
  ?string $fileExtension = null,
  ?string $storagePath = null,
)
```

**API action:** update Azure SQL / InMemory serializers that `new ProductMediaSummary(...)` / `new ProductDocumentSummary(...)` if they need the new fields; existing 8-arg construction remains valid.

## Repository contract extensions (must implement in API adapters)

### `ProductMediaRepository`
- `findByPublicId(string): ?ProductMediaFileRecord`
- `createUploadSession(ProductPublicId, array, UserId): ProductMediaFileRecord`
- `markUploadCompleted(string, array, UserId): ProductMediaFileRecord`
- `beginReplaceUpload(string, array, UserId): ProductMediaFileRecord`
- `clearFile(string, UserId): void`

### `ProductDocumentRepository`
- Same upload lifecycle methods with `ProductDocumentFileRecord`

### New repositories
- `Product360Repository`
- `ProductArModelRepository`

## New ports (no Azure SDK in core)

### `ProductBlobStore`
Implement with Azure Blob / Azurite / InMemory in API:
- `issueWriteUpload(...)` → PUT SAS ticket (`ProductBlobWriteTicket`)
- `issueReadDownload(...)` → GET SAS ticket (same DTO shape)
- `headObject`, `deleteObject`, `copyObject`
- `putObject` — **dev/test only**; production Azure adapter may `throw new LogicException`
- `buildPublicUrl`

### `ProductFileScanPolicy`
Wire `ConfigurableProductFileScanPolicy($autoMarkClean)` until real AV scanning exists.

### `ProductImageMetadataExtractor`
Prefer `GdProductImageMetadataExtractor` when GD/`getimagesizefromstring` available; else `NullProductImageMetadataExtractor`.

### `ProductFilePathBuilder`
Use as-is (concrete helper in core).

## Application services to wire

| Service | Notes |
|---|---|
| `InitiateProductMediaUploadService` | Audit `upload-session-created` — **never log SAS URL** |
| `CompleteProductMediaUploadService` | `headObject` verify size/type; scan policy; optional binary for dims |
| `ReplaceProductMediaUploadService` | Pending path + previous cleanup on complete |
| `DeleteProductMediaFileService` | Best-effort blob delete; `UploadStatusCode=deleted`; audit `file-deleted` |
| `InitiateProductDocumentUploadService` | `isCad` flag selects CAD vs PDF validation |
| `CompleteProductDocumentUploadService` | |
| `ReplaceProductDocumentUploadService` | |
| `DeleteProductDocumentFileService` | |
| `GetRestrictedDocumentDownloadService` | Public → CDN URL; restricted needs actor + `PRODUCT.DOCUMENTS.MANAGE` flag on command |
| `CreateProduct360SetService` | |
| `Initiate360FrameUploadService` | |
| `Complete360FrameUploadService` | |
| `ActivateProduct360SetService` | Fails if uploaded frames &lt; expected |
| `InitiateArModelUploadService` | Allowlist: **glb, usdz** (not gltf) |
| `CompleteArModelUploadService` | |

## Domain codes (seed / lookups)

- `UploadStatusCode`: pending_upload, uploaded, active, failed, deleted, replaced
- `ScanStatusCode`: not_required, pending_upload, pending_scan, clean, rejected, failed, skipped
- `ProductFileKind`: image, document, cad, ar, **360-frame**

## Suggested Slim routes (API)

- `POST /admin/products/{id}/media/upload-sessions`
- `POST /admin/media/{mediaId}/upload-complete`
- `POST /admin/media/{mediaId}/replace-upload`
- `DELETE /admin/media/{mediaId}/file`
- Parallel document routes
- `GET /products/{id}/documents/{docId}/download` (restricted SAS / public CDN)
- 360 set + frame upload + activate
- AR initiate/complete

## Containers (suggested)

- `product-media` — images, 360 frames, AR
- `product-documents` — PDF / CAD

## Checklist for API agent

- [ ] Implement `AzureProductBlobStore` (or Azurite) outside core
- [ ] Extend SQL media/document repositories for upload columns + new methods
- [ ] Map new summary fields in admin JSON serializers (`storagePath` admin-only)
- [ ] Permission gate `PRODUCT.DOCUMENTS.MANAGE` → `GetRestrictedDocumentDownloadCommand::$actorHasDocumentsManagePermission`
- [ ] Do not put Azure SDK packages into tricab-core
- [ ] Update any InMemory product store in tricab-api mirroring core test doubles

---

## Phase 9 close-out (2026-08-06)

See `product-phase9-azure-blob-product-file-management.md` for the full deliverable.

Wired:
- Blob store DI (`memory` / `connection-string` / `disabled`)
- Media/document/360/AR upload HTTP routes + actions
- Public restricted document download route
- ProductFileUploadEndpointsTest (11)
- Admin UI media/document upload with `originalFileName`
