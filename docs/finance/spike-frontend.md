# Spike: finance screens in tricab-web as JavaScript

Written 6 Oct 2026 (P0.5). Spike branch: `spike/finance-js` in `tricab-web`, commit `459f8bc`, local, never merged.

## Verdict

**Plan §7 holds, with listed changes.** A real Tri-Task page and the shadcn files it imports run unchanged in JavaScript on React 19.2, Tailwind 4.3 and Vite 8, and pass typecheck, lint, test and build. The work is in configuration and theming, not in rewriting pages.

What was and was not shown:

- **Shown:** the page mounts and works on React 19 in a jsdom test (list, open the add dialog, type, save, new row appears). `npm run typecheck`, `lint` and `build` pass; `npm test` runs the spike test and it passes; the full suite shows 4 failures against the 3 recorded in P0.1 (the extra one is a timing-dependent product test that also timed out once in the baseline).
- **Not shown:** a visual comparison. `tricab-web` wraps every route in its sign-in gate, which cannot be passed without Manusha, and P0.6 (running Tri-Task locally) has not been done. Do the by-eye comparison once both can be run.
- **Page choice differs from the task.** No Tri-Task page uses `ui/table`. The page used is `pages/Entities.jsx` (form in a `Dialog`, confirm in an `AlertDialog`, toasts). `ui/table.jsx` was copied across and compiles; it was not exercised.

## What was ported

`Entities.jsx` and these `components/ui` files: `dialog`, `alert-dialog`, `button`, `input`, `label`, `textarea`, `toast`, `toaster`, `use-toast`, `table`, plus `lib/utils.js`. Under `src/components/finance/_spike/`. The `@/` imports were rewritten to relative paths and the `base44` client was replaced by an in-memory stub (`stubClient.js`). Nothing else in the page changed.

## Configuration changes needed

1. **`tsconfig.app.json`: `"allowJs": true`.** Without it, importing a `.jsx` file from a `.tsx` file fails with TS7016 (`could not find a declaration file`). Without any import from TypeScript the `.jsx` files are simply invisible to `tsc`, so typecheck passes without checking them. `checkJs` stays off, so `noUnusedLocals` and the other strict flags do not apply to JavaScript. One setting, not scoped to the finance folder (a scoped alternative is a `finance/tsconfig` or a `declare module '*.jsx'` shim, which types everything as `any`; not tried).
2. **`src/index.css`: one `@import` of the finance theme file** (after `@import 'tailwindcss'`). A separate theme file that does its own `@import 'tailwindcss'` compiles Tailwind a second time (184 kB of CSS, including the base reset, which would restyle the whole app once the lazy chunk loads). Imported from `index.css`, there is one compile.
3. **`src/App.tsx`:** the temporary route (spike only; Phase 1 wires `/finance` the usual way).
4. **`package.json`:** `@radix-ui/react-dialog`, `-alert-dialog`, `-slot`, `-label`, `-toast`, `class-variance-authority`, `clsx`, `tailwind-merge` (3.7), `tailwindcss-animate` (1.0.7). `npm install` reported no peer-dependency warnings or errors against React 19, and `npm ls` shows no invalid or unmet entries.
5. **Lint:** no change needed. `oxlint` reads the `.jsx` files and gave one warning (`only-export-components` in `button.jsx`), the same kind the repo already has.

## Tailwind differences

Ported from Tri-Task's `tailwind.config.js` and `index.css`:

| Tailwind 3 | Tailwind 4 in the spike |
|---|---|
| `theme.extend.colors` (`hsl(var(--primary))` and so on) | `@theme inline { --color-primary: hsl(var(--primary)); ... }` |
| `borderRadius` `lg/md/sm` from `--radius` | `--radius-lg/md/sm` in the same block |
| `:root { --primary: ... }` | the same variables on a `.finance-spike` wrapper |
| `tailwindcss-animate` in `plugins` | `@plugin 'tailwindcss-animate'` |

What did not carry over cleanly:

- **`@theme` is global.** Scoping the variable values to a wrapper works, but the colour names (`bg-primary`, `text-muted-foreground`, `border-border`) become global utilities. Whether they clash with classes in the existing site was not checked; do that before Phase 1.
- **Portalled content** (dialogs, toasts) renders outside the wrapper, so the variables are not inherited. The spike's CSS lists `[data-radix-popper-content-wrapper]` and `[data-finance-portal]` as extra scopes; the dialog in the test mounted, but its appearance was not checked. The Phase 1 shell needs to put the variables where portals can see them (for example on the portal container).
- **Not ported:** Tri-Task's `darkMode: ["class"]` (the site uses `data-theme`), the `opacity` scale extension, the sidebar, chart and keyframe tokens, the `Outfit` / `Inter` font loading, `postcss.config.js`, `autoprefixer`.
- **Tailwind 3 to 4 utility renames** in the copied components were not audited beyond this page. Plan an audit of shadcn's classes (`shadow-sm`, `rounded`, `outline-none`, `ring` defaults changed in 4).

## Package list and React 19 status

Union of `dependencies` and `devDependencies` across the six apps' `package.json` files (read from the working trees): **82 packages** (the plan's 63 counts something narrower; the machine's figure is given). Looked up with `npm view <pkg> peerDependencies.react`; nothing installed.

- **43** declare a React peer range that includes 19 at their latest release (all the `@radix-ui/*` used, `@tanstack/react-query`, `react-hook-form`, `recharts`, `lucide-react`, `sonner`, `framer-motion`, `embla-carousel-react`, `react-resizable-panels`, `vaul`, `next-themes`, `input-otp`, `cmdk`, `react-leaflet`, `@hello-pangea/dnd`, `react-quill-new` and others).
- **33** have no React peer (not React libraries: `zod`, `date-fns`, `clsx`, `tailwind-merge`, `jspdf`, `html2canvas`, `three`, `lodash`, `moment` and so on).
- **6** are counted as "excludes 19" by a naive text check of the latest peer range, but five are open or explicit ranges that include 19 (`react-router-dom` `>=18`, `react-markdown` `>=18`, `react-hot-toast` `>=16`, `react-day-picker` `>=16.8`, `@stripe/react-stripe-js` 7.x `<20`); the sixth, `react-quill`, really excludes it.

**No package lacks a React 19 release except `react-quill`** (2.0.0, peer `^16 || ^17 || ^18`, in Tri-AP, Argo Collect and Tri-Credit Notes). Tri-AR already uses its maintained fork `react-quill-new`; use that.

**Versions the apps pin today whose peer range excludes React 19** (the latest release is fine, so these need a version bump when ported, not a replacement):

| Package | Pinned | Peer on pinned | Action |
|---|---|---|---|
| `react-day-picker` | ^8.10.1 | `^16.8 \|\| ^17 \|\| ^18` | move to v9 or later (API changed; the `calendar.jsx` wrapper needs a rewrite) |
| `react-leaflet` | ^4.2.1 | `^18` | move to v5 |
| `@hello-pangea/dnd` | ^17.0.0 | `^18` | move to v18 |
| `@stripe/react-stripe-js` | ^3.0.0 | `^16.8 \|\| ^17 \|\| ^18` | move to a release that lists 19 |
| `react-quill` | ^2.0.0 | `^16 \|\| ^17 \|\| ^18` | use `react-quill-new` |
| `cmdk` | ^1.0.0 | `^18` | move to the latest 1.x |

Pinned versions that already list 19: `recharts` 2.15, `framer-motion` 11.16, `react-resizable-panels` 2.1, `next-themes`, `vaul`, `embla-carousel-react`, `input-otp`, `react-markdown` and `react-hot-toast` (open ranges).

Facts that differ from the plan §4.4 / the task:

- **All six apps already use `react-router-dom` ^7**, not 6, so there is no router major to cross. `tricab-web` has 7.
- `tricab-web` already has `recharts` 3.10 (the apps pin ^2.15.4) and `lucide-react` 1.23 (Tri-Task pins 0.475). Both are majors apart from what the apps use; icon names should be checked on port (`Plus`, `Pencil`, `Trash2`, `Building2` worked).
- `tailwind-merge` must be 3.x for Tailwind 4 (3.7 installed); the apps' pinned version was not looked up.

## Recommendation for Phase 1's frontend foundation task

1. Set `allowJs` (no `checkJs`) in `tsconfig.app.json` and import the finance theme file from `index.css`, as in the spike. Keep finance code under `src/components/finance/`.
2. Put the shadcn kit in `src/components/finance/ui/` and install the Radix, `class-variance-authority`, `clsx` and `tailwind-merge` 3.x packages once, in that task.
3. Write the finance theme as one `@theme inline` block plus variables on a wrapper, and decide up front where portalled content gets its variables (the shell's portal container). Review the global colour names for clashes first.
4. Decide `react-day-picker` v9 and `react-query` before the first screen that needs them; these are the only ports that are rewrites. Replace `react-quill` with `react-quill-new` where rich text is used.
5. Do the by-eye comparison against Tri-Task (P0.6) on the first real ported page, not on this one.
6. Expect the 3 baseline web test failures (P0.1) to continue; do not count them against finance work.
