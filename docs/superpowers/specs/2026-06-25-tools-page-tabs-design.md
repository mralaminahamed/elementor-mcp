# Tools Page — Platform Tabs (Elementor / WordPress)

**Status:** Approved (design), ready for implementation plan.
**Date:** 2026-06-25

## Goal

Reorganize the EMCP Tools admin **Tools** page so its tool-category sections are grouped under two sub-tabs — **Elementor** and **WordPress** — instead of one long flat list. Each tool category is tagged with the platform it belongs to, so future page builders can get their own tab. This is an **admin-UI-only** change: tool registration, capability gating, the disabled-tools option, and the save handler are all unchanged.

## Decisions (locked in brainstorming)

- **Two sub-tabs** inside the existing Tools page: "Elementor" and "WordPress". (Not separate admin menu pages; not a hardcoded split.)
- **Data-driven:** every category carries a `'platform'` key (`'elementor'` | `'wordpress'`); the view groups by it. Untagged → defaults to `'elementor'`. A future builder = a new platform value + one more sub-tab.
- **Keep "Stock & Media Images" whole**, under the **WordPress** tab.
- **PHP Snippets** under the **WordPress** tab.
- **Split the "SEO & Accessibility" group**: the **SEO** tools go under **WordPress**; the **Accessibility** tools go under **Elementor**.

## Tab assignments

| Tab | Categories (catalog ids) |
|---|---|
| **Elementor** | `query`, `page`, `layout`, `widgets`, `template`, `global`, `composite`, `svg_icons`, `custom_code`, `a11y` (Pro), `widget_builder` (Pro) |
| **WordPress** | `wp_content`, `wp_settings`, `wp_packages`, `wp_users`, `stock_images`, `php_snippets`, `seo` (Pro) |

### SEO / Accessibility split

The current dynamic `seo_a11y` category (Pro-only, added in `get_all_tools()`) is split into two categories — same 7 tool slugs, regrouped:

- **`seo`** — label "SEO", platform `wordpress`: `audit-page-seo`, `extract-keywords-from-content`, `generate-meta-tags`, `generate-schema-markup`.
- **`a11y`** — label "Accessibility", platform `elementor`: `audit-page-a11y`, `fix-color-contrast`, `add-alt-text-from-context`.

Both remain Pro-gated (rendered only when `emcp_tools_fs()->can_use_premium_code()`), keep their `pro`/`read-only`/`destructive` badges, and ship disabled-by-default exactly as now — `EMCP_Tools_Admin::seo_a11y_tool_slugs()` (the seeding source of truth) is **unchanged**; only the UI grouping changes.

## Architecture

Three touch points; no change to tool registration or the MCP surface.

1. **`includes/admin/class-admin.php`**
   - Add `'platform' => 'wordpress'` to the `wp_content`, `wp_settings`, `wp_packages`, `wp_users`, `stock_images` categories in `get_tool_catalog()`, and to `php_snippets` + the new `seo` category in `get_all_tools()`. Every other category gets `'platform' => 'elementor'` (explicit, for clarity), and the new `a11y` category gets `'elementor'`.
   - Replace the single `seo_a11y` category block in `get_all_tools()` with two blocks (`seo`, `a11y`) carrying the same tools split per the table above.
   - Add a small helper `platform_tabs(): array` returning the ordered tab definitions `[ 'elementor' => 'Elementor', 'wordpress' => 'WordPress' ]` so the label/order live in one place (the view reads it).

2. **`includes/admin/views/page-tools.php`**
   - After loading `$emcp_tools_all_tools`, partition it into one bucket per platform (preserving category order). Compute each tab's enabled/total counts.
   - Render a **sub-tab nav** (`<div class="elementor-mcp-subtabs" role="tablist">`) with one button per tab showing `Label N/M`, then one `<div class="elementor-mcp-tabpanel" role="tabpanel">` per tab containing that tab's category sections (the existing collapsible `.elementor-mcp-category` markup, unchanged, moved inside the panel loop).
   - The Low-tools card/banner, the global Enable/Disable-All + Save bar, and the summary line stay **above** the sub-tabs (they apply form-wide). The per-category All/None controls are unchanged.
   - A category whose `platform` is missing/unknown falls into the `elementor` bucket (safe default).

3. **Assets**
   - `assets/css/admin.css` — style `.elementor-mcp-subtabs` (flat pill nav, active underline/fill) and `.elementor-mcp-tabpanel` (`display:none` unless `.is-active`). Flat colors only, no gradients (house style).
   - `assets/js/admin.js` — on sub-tab click, set the active panel + active pill (`aria-selected`), and persist the active platform to `localStorage` (key `emcpToolsActiveTab`) so a save/reload restores it; default to `elementor` (or `wordpress` if that's the only populated tab). Tab switching is presentation-only — it does not touch checkbox state, so the single form still submits every tool's checkbox regardless of which tab is visible.

## Data flow

Unchanged server-side. One `<form>` posts the full `OPTION_DISABLED_TOOLS[]` set across both tabs (hidden panels still submit their inputs — `display:none` does not remove form fields). The global Enable/Disable-All buttons still operate on all checkboxes in the form (both tabs); the help text notes this. Per-category All/None scope to their section as today.

## Edge cases

- **Hidden-panel submit:** panels use CSS `display:none`, NOT removal, so inactive-tab checkboxes still submit. Verified as a tested behavior.
- **Low-tools mode:** the paused/greyed inputs and essentials highlighting render inside whichever tab their category lives in; the per-tab counts reflect the effective (essentials) state in low-tools mode, matching the existing per-category count logic.
- **Empty bucket:** the renderer guards an empty tab (renders the panel with no category sections rather than erroring). In practice both tabs are always populated on any install; Pro-only categories (`a11y`, `widget_builder`, `seo`) simply don't appear on free sites.
- **No JS:** if `admin.js` fails to load (the plugin already detects a quarantined `admin.js`), fall back gracefully — the panels should default to the first (Elementor) visible. Achieve this by marking the first tab/panel `is-active` server-side in the PHP, so the page is usable before JS runs.

## File structure

**Changed:**
- `includes/admin/class-admin.php` — `platform` keys on categories; split `seo_a11y` → `seo` + `a11y`; add `platform_tabs()` helper.
- `includes/admin/views/page-tools.php` — partition by platform; render sub-tab nav + panels.
- `assets/css/admin.css` — sub-tab styles.
- `assets/js/admin.js` — sub-tab switching + persistence.
- `CHANGELOG.md`, `readme.txt` — note the Tools-page reorg (folds into the single v3.0.0 entry — admin UX improvement, no version bump).

**New:**
- `tests/unit/admin/ToolCatalogPlatformTest.php` — asserts every category in `get_all_tools()` has a `platform` of `elementor`|`wordpress`, the WordPress bucket is exactly `{wp_content, wp_settings, wp_packages, wp_users, stock_images, php_snippets, seo}` (when Pro) / the non-Pro subset otherwise, and that the SEO/A11y split preserves all 7 original slugs (no slug added or dropped vs `seo_a11y_tool_slugs()`).

## Testing strategy

- **Unit:** the catalog-platform test above (platform validity + bucket membership + SEO/A11y slug preservation). Also assert the existing admin catalog↔registry drift test still passes (no slug churn — the split only regroups).
- **Browser (Playwright, authenticated):** the two sub-tabs render with per-tab counts; clicking "WordPress" shows Content/Settings/Plugins&Themes/Users/Stock&Media/PHP-Snippets(+SEO on Pro) and hides the Elementor categories, and vice-versa; toggling a tool on the WordPress tab + Save persists (proves hidden panels still submit); the active tab survives reload; no PHP notices or JS console errors.

## Out of scope

- Any change to tool registration, capability gating, the disabled-by-default seeding, or the MCP surface.
- Reordering tools within a category, or renaming tools.
- A third builder tab (the design makes it trivial later, but none is added now).
- Splitting `stock_images` or `custom_code` (kept whole per the brainstorming decision).

## Open questions

None blocking.
