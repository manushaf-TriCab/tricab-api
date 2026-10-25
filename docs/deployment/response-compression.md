# Response compression (gzip) for the API

Status: **prepared, not enabled.** `deploy/app-service/default` (the nginx site config that
`startup.sh` copies into `/etc/nginx` on every boot) is unchanged. The directives live in
`deploy/app-service/nginx-compression.conf` until someone switches them on.

## Why

nginx currently sends every JSON response uncompressed. Measured against production payloads on
2026-10-23 through a local nginx running exactly `nginx-compression.conf`:

| Endpoint (AU) | Uncompressed | gzip | Saved |
|---|---:|---:|---:|
| `GET /api/v1/catalogue/panel` | 200,197 B | 29,917 B | 85% |
| `GET /api/v1/catalogue/tabs/products/tree` | 199,771 B | 29,773 B | 85% |
| `GET /api/v1/catalogue/nodes/accessories` | 83,970 B | 13,596 B | 83% |
| `GET /api/v1/products/un-insulated/customer-view` | 33,092 B | 4,186 B | 87% |
| `GET /api/v1/products/shipboard-power-control/customer-view` | 27,484 B | 3,271 B | 88% |
| `GET /api/v1/product-search?query=cable` | 12,431 B | 3,049 B | 75% |
| `GET /api/v1/customer-view-theme` | 912 B | 489 B | 46% |

Responses below 1 KB (`gzip_min_length 1024`) and error bodies stay uncompressed.

## Deployment requirements

1. **Deploy the API change first, or in the same release.** nginx turns a strong `ETag` into a weak
   one (`W/"…"`) when it compresses, and browsers send the weak form back in `If-None-Match`.
   Before this change the catalogue panel compared the header literally, so with gzip on every
   revalidation fell back to a full `200` (verified against production: weak ETag → `200`).
   `PayloadEtag::matches` now accepts the weak form (verified locally: weak ETag → `304`, 0 bytes,
   for panel, tree, node and customer-view).
2. **Enable** by adding one line inside the `server { … }` block of `deploy/app-service/default`:

   ```nginx
   include /home/site/wwwroot/nginx-compression.conf;
   ```

   `scripts/build-app-service-release.sh` already ships `nginx-compression.conf` next to `default`
   (and refuses to build without it), so the include always resolves. Shipping the file alone
   changes nothing: nginx only reads it once `default` includes it.
3. **Check before reload** on the instance (SSH console):

   ```sh
   nginx -t
   ```

4. **Streaming is unaffected.** `text/event-stream` is not in `gzip_types`, and
   `@tricab_api_stream` keeps `gzip off` and `fastcgi_buffering off`.
5. **Brotli is optional.** It needs the `ngx_brotli` module, which stock nginx (including the App
   Service PHP image and Homebrew's build used for validation) does not include. Only uncomment the
   Brotli block if `nginx -V 2>&1 | grep -q brotli` succeeds on the instance. gzip alone gives the
   savings above.
6. **`gzip_proxied any`** is required: requests reach nginx through the App Service front end, and
   nginx's default skips compression for requests that carry a `Via` header.

## Verify after enabling

```sh
curl -s -D - -o /dev/null -H 'Accept-Encoding: gzip' 'https://tricab.ai/api/v1/catalogue/panel?regionCode=AU' \
  | grep -iE 'content-encoding|vary|etag'
# Expect: Content-Encoding: gzip, Vary: Accept-Encoding, ETag: W/"…"

E=$(curl -s -D - -o /dev/null -H 'Accept-Encoding: gzip' 'https://tricab.ai/api/v1/catalogue/panel?regionCode=AU' \
  | awk 'tolower($1)=="etag:"{print $2}' | tr -d '\r')
curl -s -o /dev/null -w '%{http_code}\n' -H 'Accept-Encoding: gzip' -H "If-None-Match: $E" \
  'https://tricab.ai/api/v1/catalogue/panel?regionCode=AU'
# Expect: 304
```

## Rollback

Remove the `include` line from `default` and redeploy (or edit `/etc/nginx/sites-available/default`
on the instance and `service nginx reload`). Nothing else depends on compression.
