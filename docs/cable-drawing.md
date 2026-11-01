# Cable drawing endpoint

## Contract

```http
GET /api/v1/cable-drawings?code=CA-PAXN/5C16BK
Accept: image/svg+xml
```

Public, like product images, so an `<img>` can point straight at it (URL-encode the `/` in the code if your client needs to). Returns a 1080×1080 SVG of the cable's cross-section in the style of the product thumbnails, drawn by the private Composer package `tricab/cablegen`.

| Status | Body | When |
| --- | --- | --- |
| 200 | `image/svg+xml`, `Cache-Control: public, max-age=86400`; `X-Cable-Spec` names the spec row drawn, e.g. `CA 4C16+1E6` | |
| 400 | JSON `CABLE_CODE_REQUIRED` / `CABLE_CODE_INVALID` | no code, or not a cable code |
| 404 | JSON `CABLE_SPEC_NOT_FOUND` | no `cable_specs` row matches |
| 422 | JSON `CABLE_DRAWING_UNAVAILABLE` | the row can't be drawn, e.g. AWG sizes (diameters in inches) |

## Reading the code

Codes follow `CableProductCodeGenerator`: `STYLE-BLOCK/{count}{C|P|T}{size}{colour}`.

- The count includes one for the earth when the row has earths (not for OD), so `CA-PAXN/5C16BK` is the CA row with 4 cores and an earth (4C16+1E6).
- A block ending in `A` (`PAXA`, `P9XA`) means aluminium. The calculator offers aluminium for any row, so an aluminium code uses an aluminium (`ct = a`) row when there is one, otherwise the copper row drawn with aluminium conductors.
- The suffix is the sheath colour (`EA` draws a green/yellow earth core). Core colours are not in the code; Australian defaults are used.

Every size of a style is drawn at one scale (the style's largest cable fills the canvas), so smaller sizes look smaller.

## Data

The calculator's dataset has no layer diameters, so drawings read their own snapshot of legacy `tc.cable_specs`: `resources/cable-drawing/v1/cableSpecs.json` (override with `CABLE_DRAWING_DATASET`). Rebuild it from a fresh CSV export with:

```sh
php bin/build-cable-drawing-dataset.php cable_specs_YYYYMMDDhhmm.csv
```

The current snapshot is the 29 Jul 2026 export. It lacks 14 styles the calculator knows (CB, PA, PS, TX, JD, VX, VA, VN, FD, PT, HB, KB, SD, SY), so their codes return 404 until a newer export is loaded.

## Private package

CI installs `tricab/cablegen` over SSH with its own read-only deploy key, the `CABLEGEN_DEPLOY_KEY` secret, loaded by `.github/actions/private-packages-ssh` alongside docgen's (see [document-generation.md](document-generation.md)). A deploy key serves one repository, so each package has its own key and SSH host alias.
