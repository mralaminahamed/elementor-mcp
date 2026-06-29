# Optional Elementor Dependency — Design

**Status:** Approved design → ready for implementation plan
**Target version:** v3.0.0 line (`@since 3.0.0` where new code applies)
**Scope:** Make Elementor an *optional* dependency. The plugin and all beyond-Elementor tools work without Elementor; the Elementor tool family + Elementor-specific admin areas degrade to a warning.

## Goal

Today, if Elementor is not active the **entire plugin bails** (`check_dependencies()` fails → `boot()` returns early → nothing loads). Since the toolset now spans general WordPress management, that hard requirement is wrong. After this change:
- The plugin loads and runs with Elementor inactive.
- All pure-WordPress MCP tools register and work.
- The Elementor tool family does **not** register (no fatals), and the admin shows a non-blocking **warning** explaining that Elementor must be installed/activated to use those tools.
- The **Brand Kits** and **Templates** admin tabs show a notice when Elementor is inactive.

## Constraints & non-goals

- **No plugin-header change.** There is no `Requires Plugins` header, so WordPress never blocked activation; the gate is purely the runtime `check_dependencies()`.
- **Hard dependencies still bail:** PHP 8.1+, the WordPress Abilities API (`wp_register_ability`), and the bundled MCP Adapter (`\WP\MCP\Core\McpAdapter`). Only Elementor moves to optional.
- **Group-level gating** (decided): any ability group that touches Elementor is fully disabled when Elementor is inactive — no per-tool splitting inside mixed groups.
- **Elementor sub-tab stays visible** on the Tools page (greyed/disabled + warning), not hidden.
- No change to how the tools behave *with* Elementor active — full parity with today.

## The dependency model (bootstrap)

`includes/class-bootstrap.php`:

- Add `public static function elementor_active(): bool` → `(bool) did_action( 'elementor/loaded' )`.
- `check_dependencies()` keeps the PHP 8.1, Abilities API, and MCP Adapter checks as hard blockers (still `return false` + error notice). **Remove Elementor from the `$missing` hard-blocker list.**
- After hard deps pass, if `! self::elementor_active()`, register a **warning** admin notice (CSS class `notice-warning`, not `notice-error`), shown only to `current_user_can('manage_options')`:
  > **EMCP Tools is active.** Install and activate **Elementor** to enable the Elementor page-building tools (widgets, layout, templates, brand kits). All other tools — WordPress content, plugins & themes, users, media, performance, security, filesystem, and database — work without it.

  Include an action link to the Elementor install/activate screen (`Elementor` plugin-install search, or the Plugins page). The notice is informational and reflects current state (re-evaluated each load); no dismissal persistence required.
- `boot()` no longer early-returns on missing Elementor: when hard deps pass it always proceeds to `load_classes()` / `wire_hooks()` / `load_admin()` / `EMCP_Tools_Plugin::instance()`.

Class loading is unchanged: every file still `require_once`'s safely (Elementor calls only happen at execute-time, never at include-time). `wire_hooks()` (CPT registration, sandbox loaders, library refresher) needs no Elementor and stays unconditional.

## Tool gating (registrar)

The gate moves into `EMCP_Tools_Ability_Registrar::register_all()`:

- Change the signature to `register_all( bool $elementor_active ): array`.
- `EMCP_Tools_Plugin` (call site at `includes/class-plugin.php:311`) passes `EMCP_Tools_Bootstrap::elementor_active()`.
- **Always register (pure WordPress):** Media Library, Content, Settings, Plugins, Themes, Users, Performance, Filesystem, Database, Security, PHP Snippets. (The 3 surfaced `core/*` abilities are added by the MCP server config, not the registrar — unaffected.)
- **Register only when `$elementor_active`:** Query, Pages, Layout, Widgets, Templates, Globals, Composite, Stock Images, SVG Icons, Custom Code, Atomic Widgets, Atomic Layout, Global Classes, Brand Kits (System Kit), Widget Builder, SEO, A11y.
  - Wrap each of those group blocks in `if ( $elementor_active ) { ... }`. The existing inner self-guards (atomic/global-classes on Elementor 4.0+, Pro groups on license) remain and compose — they simply never run when Elementor is absent.
- The `emcp_tools_ability_names` filter still applies at the end.

Result with Elementor inactive: `tools/list` exposes only the beyond-Elementor + core surface (~50 tools); no Elementor group constructs or executes against `\Elementor\Plugin`, so there is no fatal path.

> **Registration-time safety note:** the registrar constructor receives `data` / `factory` / `schema_generator` / `validator`. Constructing those must not call Elementor (they don't today — Elementor access is lazy, at execute-time). The gating wraps registration only; construction of the shared services is unchanged and Elementor-free.

## Admin UX

`includes/admin/class-admin.php` + views. Introduce a single source of truth: the admin reads `EMCP_Tools_Bootstrap::elementor_active()` once and passes `$elementor_active` to the views.

1. **Site-wide warning** — the `notice-warning` from the bootstrap section (covers all admin screens).
2. **Tools page** (`views/page-tools.php`):
   - The Elementor sub-tab renders as today but, when `! $elementor_active`, shows a warning banner at the top of that tab: *"Elementor is not active. Install and activate Elementor to use these tools."* (with an install link).
   - Elementor-platform categories render with an `is-unavailable` state: toggles are `disabled` and visually greyed. (They're not registered, so toggling is a no-op regardless; `disabled` makes that honest.)
   - **Stats truthfulness:** `get_enabled_tool_count()` / `get_total_tool_count()` (and the Pro/category counters feeding the stats bar) must **exclude Elementor-platform categories when `! $elementor_active`**, so "X of Y enabled" reflects only the tools that actually exist. Implement via a helper that filters `get_all_tools()` by `platform` against `elementor_active`.
   - The WordPress sub-tab is the default tab when Elementor is inactive.
3. **Brand Kits tab** (`views/page-brand-kits.php`) and **Templates tab** (`views/page-templates.php`): when `! $elementor_active`, render a notice at the top — *"This feature requires Elementor. Install and activate Elementor to use Brand Kits / Templates."* The existing UI stays visible beneath it (Pro users see what they'd get; the underlying actions are inert without Elementor).
4. Connection, Prompts, Context, Skills, Changelog tabs: unchanged.

Where the admin currently assumes Elementor (e.g. reading the active kit ID for the stats/quick links), guard those reads with `elementor_active()` so they don't warn/fatal when Elementor is absent.

## Testing & verification

**Unit (PHPUnit, existing stub harness):**
- A registrar gating test: with the ability-name list produced for `register_all(false)`, assert it **contains** representative WordPress slugs (`emcp-tools/list-posts`, `emcp-tools/scan-security`, `emcp-tools/query`-DB, `emcp-tools/list-plugins`) and **excludes** representative Elementor slugs (`emcp-tools/list-widgets`, `emcp-tools/add-free-widget`, `emcp-tools/add-container`, `emcp-tools/save-as-template`); and that `register_all(true)` **includes** the Elementor slugs. (Requires the involved ability classes to be autoloadable in `tests/bootstrap.php`; add any missing `$map` entries. If full registrar instantiation proves too heavy for the harness, fall back to testing a pure helper that returns the Elementor-dependent group keys, and assert the admin/registrar use it — but prefer the end-to-end ability-name assertion.)
- An admin test: a pure helper (e.g. `EMCP_Tools_Admin::is_elementor_platform( $category )` or a filter method) correctly marks Elementor-platform categories unavailable when `elementor_active` is false, and the stats counters exclude them.

**Live (WP-CLI MCP) — the real acceptance gate:**
- Deactivate Elementor (`wp plugin deactivate elementor elementor-pro`). Confirm: (a) EMCP Tools stays active and loads with no fatal; (b) `tools/list` on `emcp-tools-server` shows only the WordPress + core tools and none of the Elementor tools; (c) the admin shows the warning notice and the Brand Kits/Templates notices, and the Tools page Elementor sub-tab is visible-but-disabled with truthful counts.
- Reactivate Elementor. Confirm the full tool surface and normal admin return (parity with today).
- Full PHPUnit suite stays green.

## Docs (after implementation)

- `CLAUDE.md` — Dependencies & Requirements: Elementor moves from a hard dependency to **optional** (enables the Elementor tool family; the plugin and all beyond-Elementor tools work without it). Note the gating model.
- `readme.txt` — requirements/description wording; a changelog line.
- `CHANGELOG.md` — 3.0.0 entry: "Elementor is now optional — the plugin and all WordPress tools work without it; the Elementor tools show a warning until Elementor is active."
- `emcp-tools.php` description line may broaden from "expose Elementor data…" to mention general WordPress management (optional, cosmetic).

## Out of scope (future)

Per-tool gating inside mixed groups; a dedicated onboarding/setup wizard; auto-installing Elementor; changing which tools are disabled-by-default; any change to the MCP server route or namespace.
