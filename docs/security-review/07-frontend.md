# 07 — Frontend security review: tricab-web (React 19 + Vite SPA)

Scope: `/home/user/tricab-web` (read-only). API cross-checks in `/home/user/tricab-api` only where a frontend finding depends on them.
Method: I read the code for every sink and auth path. I grepped `.ts/.tsx/.js/.jsx`, including the ported finance module under `src/components/finance/**`. `node_modules` is absent, so I did not run a production build. The build-related checks are marked as such.

## Summary table

| # | Title | Severity | Confidence | Location |
|---|-------|----------|------------|----------|
| F1 | TriExpense receipt "Open" renders an uploader-controlled MIME type as a same-origin blob: stored XSS against approvers/finance | **High** | Likely | `src/components/admin/triExpense/ReceiptAttachmentPanel.tsx:154-163` (+ `:78-92`, `:139-146`), `src/api/admin/triExpense.ts:2262-2278` |
| F2 | MSAL token cache in `localStorage` turns any XSS into persistent cross-tab token theft | Low (Medium when combined with F1) | Confirmed | `src/auth/msalConfig.ts:87-92` |
| F3 | No CSP or security headers defined for the SPA; inline bootstrap script | Low | Needs-verification (hosting config not in repo) | `index.html:15-26`; no `staticwebapp.config.json`/`web.config` in repo |
| F4 | E2E identity-switch mode is gated only on a build env var, not `import.meta.env.DEV`; a real employee email ships in the bundle | Low | Confirmed (exploitability blocked by API) | `src/auth/customerContactE2e.ts:7,17-33`, `src/auth/AuthProvider.tsx:345-360`, `src/api/apiClient.ts:277,397,481` |
| F5 | Logout leaves act-as session, staff pricing-context token and passkey registration token in sessionStorage | Low | Confirmed | `src/auth/clearAuthenticatedSession.ts:9-16` |
| F6 | Google Sign-In does not use a nonce (ID-token replay window) | Low | Likely | `src/auth/services/googleIdentity.ts:~150` (`initialize` without `nonce`) |
| F7 | AR email iframe: sandbox is solid, but the CSP does not block in-frame navigation or meta refresh | Info | Needs-verification | `src/components/finance/ar/components/EmailBodyFrame.jsx:21-63,118-124` |
| F8 | Assistant (LLM) Markdown allows arbitrary external links | Info | Confirmed | `src/components/assistant/AssistantMarkdown.tsx:81-90,123-130` |
| F9 | Data-driven `href`s have no shared scheme allowlist (relies on React 19 blocking `javascript:`) | Info | Confirmed | see list in F9 |
| F10 | Third-party runtime resources without SRI (GIS, Apple JS, Google Fonts, drei HDRI CDN, YouTube) | Info | Confirmed | `src/auth/services/googleIdentity.ts:3`, `src/auth/services/appleIdentity.ts:3-4`, `index.html:9-14`, `src/components/admin/products/studio/viewer/ThreeDCanvas.tsx:131-141` |
| F11 | Internal infra and personal metadata in repo (not in the bundle) | Info | Confirmed | `docs/**`, `tools/**`, `vite.config.ts:71-77`, `public/finance-files/tri-task-import-template.xlsx` |
| F12 | Mail fixtures and mock fallbacks look excluded from prod; confirm with a build grep | Info | Likely | `src/api/mail/inbox.ts:145-151`, `src/auth/permissionAccess.ts:84-90`, `src/pages/publicProductMockFallback.ts:7-21` |

---

## F1 — TriExpense receipt "Open": stored XSS in the SPA origin via an uploader-controlled blob MIME type (High, Likely)

**Evidence (frontend)**

`src/api/admin/triExpense.ts:2262-2278`: the blob keeps whatever `Content-Type` storage returns:
```ts
const response = await fetch(ticket.url, { method: ticket.httpMethod || "GET", headers: ticket.requiredHeaders, signal });
...
return response.blob();
```
`src/components/admin/triExpense/ReceiptAttachmentPanel.tsx:154-163`:
```ts
const ticket = await getAttachmentDownloadTicket(receipt.publicId)
const blob = await fetchAttachmentBlob(ticket)
const url = URL.createObjectURL(blob)
const opened = window.open(url, '_blank', 'noopener,noreferrer')
```
A `blob:` URL created by the SPA carries the SPA's origin. If `blob.type` is `text/html` (or `image/svg+xml`, `text/xml`), the new tab renders it as an active document in the TriCab origin. `noopener` only removes `window.opener`. The new document can still read `localStorage`, including the MSAL cache (F2), and can call the API with the victim's credentials.

The in-page preview (`:139-146`, `:78-92`) is safe: it uses an `<iframe>` only when `blob.type === 'application/pdf'` and `<img>` for everything else. The **Open** button is the problem.

**Why the uploader controls the type (API side)**
- `core/src/Expense/Application/Service/InitiateExpenseAttachmentService.php:57-80` validates the *declared* extension and MIME at initiate time only.
- `src/Product/Storage/AzureBlobAccountSasIssuer.php:28-95` issues a write SAS with `sp=cw`. The `rsct` value it signs is a *response-header override for reads*. It does not constrain the `Content-Type`/`x-ms-blob-content-type` a client sends on `PUT`. The `Content-Type` in `requiredHeaders` is advisory: a client can PUT directly with `x-ms-blob-content-type: text/html` and any body.
- `core/src/Expense/Application/Service/CompleteExpenseAttachmentService.php:50` checks only `objectExists`. It does not re-check the stored content type or magic bytes.
- `issueBlobReadSas` (`AzureBlobAccountSasIssuer.php:99+`) sets no `rsct`/`rscd`, so Azure serves the stored, attacker-chosen type.

**Exploit scenario**
1. A low-privileged employee who can file expenses starts a receipt upload for `x.png` (`image/png`).
2. They skip the SPA and `PUT` an HTML payload to the SAS URL with `x-ms-blob-content-type: text/html`, then call complete.
3. They submit the expense. Their manager or approver, or a finance user resolving it (`ReceiptDetailPage.tsx` renders `ReceiptAttachmentPanel` for the approver and finance flows), clicks **Open**.
4. The HTML runs in the TriCab origin. It reads the MSAL `localStorage` cache, or runs `acquireTokenSilent` from an injected MSAL, and acts as the approver: approving its own expenses, reading finance data, or using admin functions if the victim is an administrator.

Confidence is "Likely" rather than "Confirmed" because it depends on Azure keeping the client-supplied content type, which is standard Put Blob behaviour. I did not exercise it.

**Contrast:** the ported finance modules already handle this correctly. `src/components/finance/ap2/api.js:48-66` (`viewableType`) and `src/components/finance/ar/components/AttachmentViews.jsx:7-20,74` rebuild the Blob with a type taken from an allowlisted extension and never trust the server. The inbox serves mail attachments as `application/octet-stream` with `CSP: sandbox` (`tricab-api/src/Http/Action/Mail/DownloadMailAttachmentAction.php:40-46`).

**Fix**
- Frontend: do what AP2 and AR do. Use `new Blob([blob], { type: ALLOWLIST[ext] ?? 'application/octet-stream' })` with the type from the stored file name or extension (pdf, png, jpeg, gif, heic). Only `window.open` PDFs and images. Download anything else with `a.download`.
- API: on complete, `HEAD` the blob and reject or quarantine when the content type is not the declared one, and sniff magic bytes. Issue read SAS URLs with `rsct=<allowlisted type>` and `rscd=attachment` for non-previewable types.

---

## F2 — MSAL cache in `localStorage` (Low on its own; raises the impact of any XSS)

`src/auth/msalConfig.ts:87-92`:
```ts
cache: { cacheLocation: BrowserCacheLocation.LocalStorage },
```
The cache is kept in `localStorage` on purpose, so Admin pages opened in a new tab reuse the sign-in. MSAL v4+ encrypts the `localStorage` cache, but the key sits in a JS-readable session cookie, so same-origin script can still use the tokens. Any XSS (F1) therefore gets refresh-token-backed, cross-tab access to the API as staff and administrators.

Guest, email OTP, Google and Apple session tokens are in **sessionStorage** (`src/auth/services/GuestTokenStorage.ts`), which is better.

**Fix:** if the cross-tab UX can be given up, use `sessionStorage` for admin sessions. Otherwise accept the trade-off explicitly and put the effort into CSP (F3) and removing XSS sinks (F1).

## F3 — No CSP or security headers in the repo (Low, Needs-verification)

The repo has no hosting header configuration: no `staticwebapp.config.json`, `web.config`, `_headers`, or nginx file. `index.html` has an inline theme script (`:15-26`), so a CSP would need a hash for it. The SPA also loads Google Fonts CSS, GIS, Apple JS, and a drei HDRI from a CDN (F10), and frames YouTube.

**Fix:** set headers on the App Service or Front Door:
- `Content-Security-Policy`:
  - `default-src 'self'`
  - `script-src 'self' 'sha256-<theme script>' https://accounts.google.com https://appleid.cdn-apple.com`
  - `style-src 'self' 'unsafe-inline' https://fonts.googleapis.com`
  - `font-src https://fonts.gstatic.com`
  - `img-src 'self' data: blob: https://<blob-account>.blob.core.windows.net`
  - `connect-src 'self' <api> https://login.microsoftonline.com https://<blob>`
  - `frame-src https://www.youtube.com https://accounts.google.com https://appleid.apple.com blob:`
  - `object-src 'self' blob:`
  - `base-uri 'self'`
  - `frame-ancestors 'none'`
- Also `X-Content-Type-Options: nosniff` and `Referrer-Policy: strict-origin-when-cross-origin`.

A CSP would not stop F1, because a blob document inherits the creator's CSP, but it would limit where exfiltrated data can be sent.

## F4 — E2E identity switch not gated on DEV; real employee identity in the bundle (Low)

`src/auth/customerContactE2e.ts:7`:
```ts
export const customerContactE2eEnabled = import.meta.env.VITE_CUSTOMER_CONTACT_E2E_MODE === 'true'
```
Compare `inbox.ts`/`permissionAccess.ts`, which also require `import.meta.env.DEV`.

If the flag is ever set in a release build (CI or pipeline variable leakage):
- `AuthProvider.tsx:345-360` skips real sign-in and treats `/api/v1/me` as authenticated.
- `apiClient.ts:277,397,481` sends `X-Dev-Entra-Tenant/Subject/Email` for an identity chosen in `sessionStorage`, including `{ id:'platform-admin', email:'thilanh@tricab.com', subject:'development-thilan' }` (`customerContactE2e.ts:22-27`).

The API ignores these headers unless `APP_ENV=development` and `DEV_AUTH_ENABLED=true`, and also skips them when a Bearer token is present (`tricab-api/src/Http/Middleware/DevelopmentAuthenticationMiddleware.php:16-45`). So this is not exploitable against prod on its own.

The `CUSTOMER_CONTACT_E2E_IDENTITIES` array, with the real staff email labelled "Platform admin", is referenced by `CustomerContactE2eIdentitySwitch.tsx` and most likely ships in the production bundle. Verify with `grep -r thilanh dist/`.

**Fix:** use `import.meta.env.DEV && import.meta.env.VITE_CUSTOMER_CONTACT_E2E_MODE === 'true'`. Move the identities into a dev-only dynamic import. Replace the real email with a `.test` identity. Add a CI check that fails a release build if `dist/` contains `X-Dev-Entra` or the E2E identities.

## F5 — Incomplete local logout (Low)

`clearAuthenticatedSessionStorage()` (`src/auth/clearAuthenticatedSession.ts:9-16`) clears the guest token, the provider and the caches. It does **not** clear:
- `tricab.actAsSession` (`src/auth/actAsSession.ts:15`): the `X-Act-As-Session` header keeps being sent, and the server-side act-as session is not ended on logout.
- `tricab.cart.pricingContextToken` (`src/lib/cart/cartStore.ts:9`): the staff "price as customer" context.
- The passkey registration session token (`src/auth/passkey/passkeyAuthApi.ts:58-70`).

The API binds act-as (`ActAsMiddleware` resolves using the caller) and pricing tokens (`ResolvePricingActorContextService.php:55` checks the actor) to the original user. A different user in the same tab therefore cannot reuse them, so the impact is stale state and act-as sessions left open. MSAL `logoutPopup` failures are only logged (`AuthProvider.tsx:807-821`). Local MSAL state is cleared by MSAL before the popup opens.

**Fix:** call `clearActAsSession()` (and end it server-side), clear the pricing token, and clear the passkey registration key in `signOutLocally`.

## F6 — Google Sign-In without nonce (Low, Likely)

The Apple flow checks `state` and passes a server `nonce` (`appleIdentity.ts:126-149`, `AuthProvider.tsx:607`). The GIS `initialize` call (`googleIdentity.ts:~150`) passes no `nonce`, and no nonce check was found in `tricab-api/src/Identity/Authentication/GoogleOidcIdTokenValidator.php`. A captured Google ID token for TriCab's client ID (from logs, a proxy or a browser extension) can be replayed to `/api/v1/auth/google` until it expires (about 1 hour).

**Fix:** fetch a one-time nonce from the API, pass it as `nonce` to `google.accounts.id.initialize`, and verify it server-side, the same way the Apple flow does.

## F7 — AR email body iframe (Info, Needs-verification)

`EmailBodyFrame.jsx` puts untrusted HTML (`base44.entities.EmailRecord.body` → AR email record body fetched from Outlook) into `srcDoc` with `sandbox=""` and a meta CSP of `default-src 'none'; style-src 'unsafe-inline'; img-src data: cid:; font-src data:`. This is a sound boundary: no scripts, forms, popups, or same-origin access. CSP does not govern navigation, though. A `<meta http-equiv="refresh" content="0;url=https://attacker">` in the mail may navigate the frame itself, which can confirm the mail was read and show attacker content inside the app chrome. No script runs, because the sandbox flags are inherited.

**Fix (optional):** strip `<meta>` and `<base>` from `content` before building `srcDoc`, for example with DOMPurify using `FORBID_TAGS: ['meta','base','link']`, as the inbox already does.

## F8 — Assistant Markdown links (Info)

`AssistantMarkdown.tsx`:
- Raw HTML is not enabled (no `rehype-raw`).
- `img` is disallowed, so there is no zero-click exfiltration.
- react-markdown's default `urlTransform` removes `javascript:` and `data:` before the custom `a` renderer.
- Links open with `rel="noopener noreferrer nofollow"`.

What remains: a prompt injection through catalogue data can make the model output a convincing link to any `https` host, which could be used for phishing or for exfiltration through the query string after a click. Product cards (`ProductAssistantDrawer.tsx:612-620`) use `href`s that the server builds (`tricab-api/src/Assistant/Application/GroundedProduct.php:33-37`), so they are fine.

**Fix (optional):** pass a `urlTransform` that allows only TriCab domains and relative paths, or show the destination hostname next to external links.

## F9 — Data-driven hrefs without a central allowlist (Info)

`<a href={…}>` takes values from API data in:
- `tsd/cablefit/ResearchAssessmentView.tsx:75` (`finding.url`, from web research or LLM output)
- `product/ProductDocuments.tsx:33`, `pages/ProductDetailPage.tsx:419` (`doc.url`)
- `customer-product/CustomerProductDocuments.tsx:40` (`doc.publicUrl`)
- `customerContact/pages/DetailPage.tsx:448,775`
- `rfq/review/SyncStatus.tsx:89`
- `onboarding/OnboardingTrainingItemPage.tsx:430`

React 19 replaces `javascript:` URLs with a throwing stub. The onboarding URLs are also forced to `https://` by `core/src/Onboarding/Domain/Service/SlideMaterialRules.php:158-183`. All `target=_blank` links checked have `rel="noreferrer"` or `rel="noopener noreferrer"`, and all `window.open` calls pass `noopener,noreferrer`.

**Fix (defence in depth):** add a `safeHref()` helper that allows `https:`, `mailto:`, `tel:` and relative paths, and use it at these call sites.

## F10 — Third-party runtime resources (Info)

- GIS `https://accounts.google.com/gsi/client` and Apple `appleid.auth.js` are injected at runtime without SRI. The vendors update these in place, so SRI is not practical; allowlist them in CSP instead.
- Google Fonts CSS is loaded in `index.html`.
- The admin 3D studio `<Environment preset=…>` fetches HDRIs from a public CDN, as the code comment says (`ThreeDCanvas.tsx:131-141`). Consider self-hosting the HDR files.
- The documentary YouTube iframe (`about/Documentary.tsx:25`) is static and trusted. It has no `sandbox`, which is acceptable.
- `useGLTF(url, false, true)` does not load the Draco decoder from the gstatic CDN.

## F11 — Internal information committed to the repo, not bundled (Info)

- `docs/**` and `tools/**` name the production Azure SQL server (`tricab-mel.database.windows.net` / `tc`), App Service names (`tc-web`, `tc-web-prod`), and the operator's email and machine name (`docs/database-performance-live-verification.md:14,32,171`, `tools/legacy-customer-migration/RUNBOOK.md:4`).
- `vite.config.ts:71-77` embeds real-looking Entra tenant, client and API IDs for tests. These are public identifiers in any SPA build anyway.
- `public/finance-files/tri-task-import-template.xlsx` carries an author name in its metadata.
- I found no secrets, keys, `.env` files, customer exports or PII datasets. `.gitignore` excludes `tools/*/out/` and `.azure-cli-*`, and a git history scan for `.env`, `.pem`, `.p8`, `AccountKey=` and private keys was clean.
- `tools/legacy-customer-migration/cleanup_sample_data.sql` refers only to seeded sample customers. Mail fixtures use `.example` domains.

Consider whether a public-facing repo or artifact should name the production DB host.

## F12 — Dev fixtures and mock modes (Info, Likely)

- `src/api/mail/inbox.ts:145-151` puts `import('./fixtureAdapter')` behind an inline `import.meta.env.DEV && …`. Vite replaces DEV with `false`, and Rollup removes the dead branch and the chunk with it.
- `isInboxModuleOn` (`permissionAccess.ts:84-90`) needs DEV.
- `isPublicProductMockFallbackEnabled` returns false when `PROD`.
- `NewExpensePage.tsx:193`, `useCableCalculator.ts:48-61` and `ScrollCinematicSection.tsx:146,343` are DEV-gated.

**Verify:** run `npm run build && grep -rE "harbour-logistics|t-uncertain-injection|cc-e2e-|thilanh" dist/`. Only the F4 strings should be found.

---

## Looks correct

- **Inbox HTML mail** (`components/inbox/features/inbox/sanitize.ts`, `MessageBody.tsx`):
  - DOMPurify forbids `svg`, `math`, `style`, `form`, `iframe`, `object`, `embed`, `base`, `meta`, `link`, `audio`, `video`, `source`, and the attributes `style`, `class`, `id`, `name`, `srcset`, `background`, `action`, `formaction`. Forbidding `id`/`name` prevents DOM clobbering, and forbidding `svg`/`math` reduces mXSS through namespace confusion.
  - The changes made after sanitising only set constant attributes or `textContent`.
  - Links are limited to `http(s)`, `mailto`, `tel` and `#`, with `noopener noreferrer`.
  - Remote images are blocked by default, and `no-referrer` is set when the reader shows them.
  - Using `RETURN_DOM` and then serialising is acceptable here. Keep DOMPurify current.
  - Minor: `<area href>` is not given `target=_blank`.
- **Inbox attachments:** the API returns `application/octet-stream` with `CSP: sandbox` and `nosniff`. PDFs are shown through `<object type="application/pdf">` and images through `<img>`, so they cannot render as HTML.
- **AP2 and AR finance file previews:** the blob type is forced from an allowlisted extension.
- **No other XSS sinks:** apart from the sanitised inbox body and the static shadcn `chart.jsx` style block, there is no `dangerouslySetInnerHTML`, `innerHTML`, `insertAdjacentHTML`, `document.write`, `eval`/`new Function`, or string `setTimeout`. `ChartContainer` is unused. Product, page and admin-authored content is rendered as React text. Theme colours go through React `style` objects.
- **No postMessage listeners.** `auth-logout-complete.html` posts to `window.opener` with `targetOrigin = location.origin`. `auth-redirect.html` uses the MSAL redirect bridge (BroadcastChannel) and does not load the app.
- **Return paths:** `authReturnPath.ts:24-26` allows only same-origin absolute paths (no `//`, no backslash, no control characters). The passkey return path uses a fixed allowlist (`passkeyReturnPath.ts:13-31`). No `returnUrl`/`next` query parameter reaches `navigate` or `location`. `NotificationPanel.tsx:96` restricts `href` to `/admin`. Menu `href`s are static.
- **Passkey setup token:** removed from the URL with `history.replaceState` (`GuestSetupPasskeyPage.tsx:36-41`). The default referrer policy sends only the origin to Google Fonts.
- **Apple Sign-In** checks `state` and uses a server nonce, and ignores profile data from the browser.
- **Staff pricing diagnostics:** the SPA strips `diagnostic` when the user lacks `PRODUCT.PRICING.VIEW` (`CustomerProductVariantMatrix.tsx:202-214`), and the API also gates it (`PostCustomerVariantPricingBatchAction.php:88,109-119` unsets `baseCost`/`marginPercent` for non-staff). Cost data is not hidden only by the client.
- **UI permission checks** (`permissionAccess.ts`) are documented as navigation-only, with the API as the authority. Module switches open nothing when `/me` omits them.
- **Auth diagnostics** (`authDebug.ts`) log only booleans and codes, never tokens. Activity tracking sends only module codes.
