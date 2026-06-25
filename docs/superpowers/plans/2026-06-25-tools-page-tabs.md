# Tools Page Platform Tabs — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Group the admin Tools-page tool categories under two sub-tabs ("Elementor" / "WordPress"), driven by a `platform` key on each category, with the SEO/Accessibility group split (SEO→WordPress, Accessibility→Elementor). Admin-UI only — no change to tool registration, gating, or the disabled-tools save path.

**Architecture:** Each category in `get_tool_catalog()`/`get_all_tools()` gains `'platform' => 'elementor'|'wordpress'`. Two pure static helpers (`platform_tabs()`, `partition_by_platform()`) provide the tab labels/order and the grouping; the latter is unit-tested in isolation. `page-tools.php` partitions by platform and renders a sub-tab nav + one panel per tab (existing collapsible category markup, moved inside the panel loop). CSS + JS add the tab switching with `localStorage` persistence. Hidden panels use `display:none`, so every tool checkbox still submits.

**Tech Stack:** PHP 8.2, WordPress admin, vanilla JS (`assets/js/admin.js`), CSS (`assets/css/admin.css`), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-06-25-tools-page-tabs-design.md`

---

## File Structure

| File | Change |
|---|---|
| `includes/admin/class-admin.php` | `platform` keys on every catalog category; split `seo_a11y` → `seo` + `a11y`; add `platform_tabs()` + `partition_by_platform()` static helpers. |
| `includes/admin/views/page-tools.php` | Partition by platform; render sub-tab nav + panels. |
| `assets/css/admin.css` | Sub-tab nav + panel styles (flat, no gradients). |
| `assets/js/admin.js` | Sub-tab switching + `localStorage` persistence. |
| `tests/unit/admin/ToolCatalogPlatformTest.php` | NEW — unit-test `partition_by_platform()` + `platform_tabs()`. |
| `phpunit.xml` | Add an `Admin` testsuite (if not already covering `tests/unit/admin`). |
| `CHANGELOG.md`, `readme.txt` | Note the Tools-page reorg (folds into v3.0.0; no version bump). |

---

## Task 1: Static helpers + unit test

**Files:** Modify `includes/admin/class-admin.php`; Create `tests/unit/admin/ToolCatalogPlatformTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/unit/admin/ToolCatalogPlatformTest.php`:

```php
<?php
/**
 * Platform partitioning for the Tools-page sub-tabs.
 * @group admin
 * @package EMCP_Tools\Tests\Admin
 */
namespace EMCP_Tools\Tests\Admin;

use PHPUnit\Framework\TestCase;

class ToolCatalogPlatformTest extends TestCase {

	/** @test */
	public function test_platform_tabs_order_and_labels(): void {
		$tabs = \EMCP_Tools_Admin::platform_tabs();
		$this->assertSame( array( 'elementor', 'wordpress' ), array_keys( $tabs ) );
		$this->assertSame( 'Elementor', $tabs['elementor'] );
		$this->assertSame( 'WordPress', $tabs['wordpress'] );
	}

	/** @test */
	public function test_partition_groups_by_platform(): void {
		$cats = array(
			'query'      => array( 'label' => 'Query', 'platform' => 'elementor', 'tools' => array() ),
			'wp_content' => array( 'label' => 'Content', 'platform' => 'wordpress', 'tools' => array() ),
			'seo'        => array( 'label' => 'SEO', 'platform' => 'wordpress', 'tools' => array() ),
			'a11y'       => array( 'label' => 'A11y', 'platform' => 'elementor', 'tools' => array() ),
		);
		$buckets = \EMCP_Tools_Admin::partition_by_platform( $cats );
		$this->assertSame( array( 'elementor', 'wordpress' ), array_keys( $buckets ) );
		$this->assertSame( array( 'query', 'a11y' ), array_keys( $buckets['elementor'] ) );
		$this->assertSame( array( 'wp_content', 'seo' ), array_keys( $buckets['wordpress'] ) );
	}

	/** @test */
	public function test_partition_defaults_unknown_platform_to_elementor(): void {
		$cats = array(
			'no_platform'  => array( 'label' => 'X', 'tools' => array() ),
			'bad_platform' => array( 'label' => 'Y', 'platform' => 'martian', 'tools' => array() ),
			'wp'           => array( 'label' => 'Z', 'platform' => 'wordpress', 'tools' => array() ),
		);
		$buckets = \EMCP_Tools_Admin::partition_by_platform( $cats );
		$this->assertArrayHasKey( 'no_platform', $buckets['elementor'] );
		$this->assertArrayHasKey( 'bad_platform', $buckets['elementor'] );
		$this->assertArrayHasKey( 'wp', $buckets['wordpress'] );
		$this->assertCount( 2, $buckets['elementor'] );
		$this->assertCount( 1, $buckets['wordpress'] );
	}

	/** @test */
	public function test_partition_preserves_category_order_within_bucket(): void {
		$cats = array(
			'a' => array( 'platform' => 'wordpress', 'tools' => array() ),
			'b' => array( 'platform' => 'wordpress', 'tools' => array() ),
			'c' => array( 'platform' => 'wordpress', 'tools' => array() ),
		);
		$buckets = \EMCP_Tools_Admin::partition_by_platform( $cats );
		$this->assertSame( array( 'a', 'b', 'c' ), array_keys( $buckets['wordpress'] ) );
	}
}
```

- [ ] **Step 2: Confirm the admin class is loadable by the harness**

Run: `grep -n "EMCP_Tools_Admin" tests/bootstrap.php`
- If `EMCP_Tools_Admin` is NOT in the autoloader map, add `'EMCP_Tools_Admin' => 'includes/admin/class-admin.php'` to it (mirror existing entries). The class file must be loadable standalone for the static-method calls. (`__` is already stubbed; the static helpers use no other WP/Freemius calls.)

- [ ] **Step 3: Run — expect FAIL (undefined methods)**

Run: `/f/laragon/bin/php/php-8.4.15-nts-Win32-vs17-x64/php.exe vendor/bin/phpunit --configuration phpunit.xml.dist tests/unit/admin/ToolCatalogPlatformTest.php`
Expected: FAIL — `Call to undefined method ...::platform_tabs()` (or class-not-found until Step 2 is done).

- [ ] **Step 4: Add the two static helpers**

In `includes/admin/class-admin.php`, add these public static methods (place them just above `get_all_tools()` near line 1089):

```php
	/**
	 * The ordered platform sub-tabs for the Tools page. Keyed by the `platform`
	 * value a category carries; the value is the display label. A future page
	 * builder is added by giving its categories a new platform value and adding
	 * a matching entry here.
	 *
	 * @since 3.0.0
	 * @return array<string,string>
	 */
	public static function platform_tabs(): array {
		return array(
			'elementor' => __( 'Elementor', 'emcp-tools' ),
			'wordpress' => __( 'WordPress', 'emcp-tools' ),
		);
	}

	/**
	 * Group a tool-category map into one bucket per platform tab, preserving
	 * category order within each bucket. A category with a missing or unknown
	 * `platform` falls into the default ('elementor') bucket.
	 *
	 * @since 3.0.0
	 * @param array $categories Category map (id => category array) from get_all_tools().
	 * @return array<string,array> [ 'elementor' => [...], 'wordpress' => [...] ]
	 */
	public static function partition_by_platform( array $categories ): array {
		$buckets = array();
		foreach ( array_keys( self::platform_tabs() ) as $tab_id ) {
			$buckets[ $tab_id ] = array();
		}
		foreach ( $categories as $id => $cat ) {
			$platform = ( isset( $cat['platform'] ) && isset( $buckets[ $cat['platform'] ] ) ) ? $cat['platform'] : 'elementor';
			$buckets[ $platform ][ $id ] = $cat;
		}
		return $buckets;
	}
```

- [ ] **Step 5: Run — expect PASS (4 tests)**

Run: `/f/laragon/bin/php/php-8.4.15-nts-Win32-vs17-x64/php.exe vendor/bin/phpunit --configuration phpunit.xml.dist tests/unit/admin/ToolCatalogPlatformTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Add the Admin testsuite to phpunit.xml + commit**

In `phpunit.xml`, after the `<testsuite name="Users">` block, add (if `tests/unit/admin` isn't already covered):
```xml
        <testsuite name="Admin">
            <directory>tests/unit/admin</directory>
        </testsuite>
```
Run both configs to confirm green, then:
```bash
git add includes/admin/class-admin.php tests/unit/admin/ToolCatalogPlatformTest.php tests/bootstrap.php phpunit.xml
git commit -m "feat(admin): platform_tabs() + partition_by_platform() helpers for Tools-page tabs"
```

---

## Task 2: Tag categories with platform + split SEO/A11y

**Files:** Modify `includes/admin/class-admin.php`

- [ ] **Step 1: Tag the WordPress categories in `get_tool_catalog()`**

Add `'platform' => 'wordpress',` as the FIRST key inside each of these category arrays (immediately after the `'<id>' => array(` line, before `'label' =>`): `wp_content`, `wp_settings`, `wp_packages`, `wp_users`, `stock_images`.

Example (for `wp_content`):
```php
			'wp_content'       => array(
				'platform' => 'wordpress',
				'label' => __( 'WordPress Content', 'emcp-tools' ),
				'tools' => array(
					// … unchanged …
```

- [ ] **Step 2: Tag the Elementor categories in `get_tool_catalog()`**

Add `'platform' => 'elementor',` (first key) to each of: `query`, `page`, `layout`, `widgets`, `template`, `global`, `composite`, `svg_icons`, `custom_code`. (Explicit, for clarity — the helper defaults to elementor anyway, but tagging makes the catalog self-documenting.)

- [ ] **Step 3: Tag `php_snippets` (WordPress) in `get_all_tools()`**

In `get_all_tools()`, the `$tools['php_snippets'] = array( 'label' => …, 'tools' => … )` block: add `'platform' => 'wordpress',` as the first key:
```php
			$tools['php_snippets'] = array(
				'platform' => 'wordpress',
				'label' => __( 'PHP Snippets (Sandbox)', 'emcp-tools' ),
				'tools' => array(
					// … unchanged …
```

- [ ] **Step 4: Split `seo_a11y` into `seo` (WordPress) + `a11y` (Elementor)**

In `get_all_tools()`, REPLACE the single `$tools['seo_a11y'] = array( … );` block (inside the `if ( … can_use_premium_code() )` guard) with these two blocks:

```php
				$tools['seo'] = array(
					'platform' => 'wordpress',
					'label' => __( 'SEO', 'emcp-tools' ),
					'tools' => array(
						'emcp-tools/audit-page-seo'                => array(
							'label'       => __( 'Audit Page SEO', 'emcp-tools' ),
							'description' => __( 'Scored on-page SEO report (H1, title/meta, canonical, alts, links, word count).', 'emcp-tools' ),
							'badges'      => array( 'pro', 'read-only' ),
						),
						'emcp-tools/extract-keywords-from-content' => array(
							'label'       => __( 'Extract Keywords', 'emcp-tools' ),
							'description' => __( 'Frequency keyword + phrase extraction from page content.', 'emcp-tools' ),
							'badges'      => array( 'pro', 'read-only' ),
						),
						'emcp-tools/generate-meta-tags'            => array(
							'label'       => __( 'Generate Meta Tags', 'emcp-tools' ),
							'description' => __( 'Proposes (apply:true writes to Yoast/Rank Math) an SEO title and meta description. Dry-run by default.', 'emcp-tools' ),
							'badges'      => array( 'pro' ),
						),
						'emcp-tools/generate-schema-markup'        => array(
							'label'       => __( 'Generate Schema Markup', 'emcp-tools' ),
							'description' => __( 'Generates (apply:true injects) JSON-LD structured data (Article, LocalBusiness, FAQPage, etc.). Dry-run by default.', 'emcp-tools' ),
							'badges'      => array( 'pro' ),
						),
					),
				);

				$tools['a11y'] = array(
					'platform' => 'elementor',
					'label' => __( 'Accessibility', 'emcp-tools' ),
					'tools' => array(
						'emcp-tools/audit-page-a11y'           => array(
							'label'       => __( 'Audit Page Accessibility', 'emcp-tools' ),
							'description' => __( 'WCAG-oriented report: contrast, alts, heading order, link text, form labels.', 'emcp-tools' ),
							'badges'      => array( 'pro', 'read-only' ),
						),
						'emcp-tools/fix-color-contrast'        => array(
							'label'       => __( 'Fix Color Contrast', 'emcp-tools' ),
							'description' => __( 'Proposes (apply:true to write) adjusted text colors so failing pairs meet WCAG AA. Dry-run by default.', 'emcp-tools' ),
							'badges'      => array( 'pro', 'destructive' ),
						),
						'emcp-tools/add-alt-text-from-context' => array(
							'label'       => __( 'Add Alt Text from Context', 'emcp-tools' ),
							'description' => __( 'Proposes (apply:true to write) alt text for images lacking it, from filename/heading/title. Dry-run by default.', 'emcp-tools' ),
							'badges'      => array( 'pro', 'destructive' ),
						),
					),
				);
```

> The `widget_builder` block (right after, also inside the Pro guard) gets `'platform' => 'elementor',` added as its first key.
> The 7 slugs are identical to the old `seo_a11y` block — only the grouping changes. `seo_a11y_tool_slugs()` (the disabled-by-default seeding source) is NOT touched.

- [ ] **Step 5: Lint + run the drift/admin tests**

Run:
```
/f/laragon/bin/php/php-8.4.15-nts-Win32-vs17-x64/php.exe -l includes/admin/class-admin.php
/f/laragon/bin/php/php-8.4.15-nts-Win32-vs17-x64/php.exe vendor/bin/phpunit --configuration phpunit.xml.dist
```
Expected: no syntax errors; full suite green. The admin catalog↔registry drift test (`tests/unit/Security/F019F020AdminTest.php`) still passes — it checks for a non-static `get_all_tools()` and slug presence; the split keeps all slugs and the dynamic structure.

- [ ] **Step 6: Commit**

```bash
git add includes/admin/class-admin.php
git commit -m "feat(admin): tag tool categories with platform; split SEO/A11y group"
```

---

## Task 3: Render sub-tabs in the Tools view

**Files:** Modify `includes/admin/views/page-tools.php`

- [ ] **Step 1: Partition the categories + compute per-tab counts**

In `page-tools.php`, after the existing `$emcp_tools_essentials = …;` line (~line 22) and before the badge-labels array, add:

```php
$emcp_tools_tabs    = EMCP_Tools_Admin::platform_tabs();
$emcp_tools_buckets = EMCP_Tools_Admin::partition_by_platform( $emcp_tools_all_tools );

/**
 * Per-tab enabled/total counts, computed with the same effective-state logic
 * the category headers use (essentials in low-tools mode, else the stored set).
 */
$emcp_tools_tab_counts = array();
foreach ( $emcp_tools_buckets as $emcp_tools_tab_id => $emcp_tools_tab_cats ) {
	$emcp_tools_t_total   = 0;
	$emcp_tools_t_enabled = 0;
	foreach ( $emcp_tools_tab_cats as $emcp_tools_cat ) {
		foreach ( $emcp_tools_cat['tools'] as $emcp_tools_s => $emcp_tools_unused ) {
			$emcp_tools_t_total++;
			$emcp_tools_eff = $emcp_tools_low_mode
				? in_array( $emcp_tools_s, $emcp_tools_essentials, true )
				: ! in_array( $emcp_tools_s, $emcp_tools_disabled, true );
			if ( $emcp_tools_eff ) {
				$emcp_tools_t_enabled++;
			}
		}
	}
	$emcp_tools_tab_counts[ $emcp_tools_tab_id ] = array( 'enabled' => $emcp_tools_t_enabled, 'total' => $emcp_tools_t_total );
}
```

- [ ] **Step 2: Render the sub-tab nav (after the bulk-actions bar)**

Immediately AFTER the `<div class="elementor-mcp-bulk-actions"> … </div>` block (~line 93) and BEFORE the category `foreach`, insert the nav. The first tab is marked active server-side so the page works before JS:

```php
	<div class="elementor-mcp-subtabs" role="tablist" aria-label="<?php esc_attr_e( 'Tool platforms', 'emcp-tools' ); ?>">
		<?php $emcp_tools_first = true; ?>
		<?php foreach ( $emcp_tools_tabs as $emcp_tools_tab_id => $emcp_tools_tab_label ) : ?>
			<button
				type="button"
				class="elementor-mcp-subtab <?php echo $emcp_tools_first ? 'is-active' : ''; ?>"
				role="tab"
				data-tab="<?php echo esc_attr( $emcp_tools_tab_id ); ?>"
				aria-selected="<?php echo $emcp_tools_first ? 'true' : 'false'; ?>"
				aria-controls="emcp-tabpanel-<?php echo esc_attr( $emcp_tools_tab_id ); ?>"
			>
				<span class="elementor-mcp-subtab-label"><?php echo esc_html( $emcp_tools_tab_label ); ?></span>
				<span class="elementor-mcp-subtab-count">
					<?php
					printf(
						/* translators: %1$d: enabled, %2$d: total */
						esc_html__( '%1$d / %2$d', 'emcp-tools' ),
						(int) $emcp_tools_tab_counts[ $emcp_tools_tab_id ]['enabled'],
						(int) $emcp_tools_tab_counts[ $emcp_tools_tab_id ]['total']
					);
					?>
				</span>
			</button>
			<?php $emcp_tools_first = false; ?>
		<?php endforeach; ?>
	</div>
```

- [ ] **Step 3: Wrap the category loop in per-tab panels**

REPLACE the existing single category `foreach` (the block `<?php foreach ( $emcp_tools_all_tools as $emcp_tools_category_id => $emcp_tools_category ) : ?> … <?php endforeach; ?>`, ~lines 95-177) with a nested loop: outer over tabs (panels), inner over that tab's categories. The inner category markup is IDENTICAL to the current per-category markup — only the data source changes from `$emcp_tools_all_tools` to `$emcp_tools_tab_cats`, and it's wrapped in a panel div:

```php
	<?php $emcp_tools_first_panel = true; ?>
	<?php foreach ( $emcp_tools_buckets as $emcp_tools_tab_id => $emcp_tools_tab_cats ) : ?>
		<div
			class="elementor-mcp-tabpanel <?php echo $emcp_tools_first_panel ? 'is-active' : ''; ?>"
			id="emcp-tabpanel-<?php echo esc_attr( $emcp_tools_tab_id ); ?>"
			role="tabpanel"
			data-tab="<?php echo esc_attr( $emcp_tools_tab_id ); ?>"
		>
			<?php foreach ( $emcp_tools_tab_cats as $emcp_tools_category_id => $emcp_tools_category ) : ?>
				<div class="elementor-mcp-category" data-category="<?php echo esc_attr( $emcp_tools_category_id ); ?>">
					<?php
					$emcp_tools_cat_total   = count( $emcp_tools_category['tools'] );
					$emcp_tools_cat_enabled = 0;
					foreach ( $emcp_tools_category['tools'] as $emcp_tools_slug => $emcp_tools_tool ) {
						$emcp_tools_eff = $emcp_tools_low_mode
							? in_array( $emcp_tools_slug, $emcp_tools_essentials, true )
							: ! in_array( $emcp_tools_slug, $emcp_tools_disabled, true );
						if ( $emcp_tools_eff ) {
							$emcp_tools_cat_enabled++;
						}
					}
					$emcp_tools_grid_id = 'emcp-cat-' . $emcp_tools_category_id;
					?>
					<div class="elementor-mcp-category-header">
						<button
							type="button"
							class="elementor-mcp-category-toggle"
							aria-expanded="true"
							aria-controls="<?php echo esc_attr( $emcp_tools_grid_id ); ?>"
						>
							<span class="elementor-mcp-category-chevron" aria-hidden="true">
								<svg viewBox="0 0 20 20" width="14" height="14"><path d="M6 8l4 4 4-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
							</span>
							<span class="elementor-mcp-category-title"><?php echo esc_html( $emcp_tools_category['label'] ); ?></span>
							<span class="elementor-mcp-category-count">
								<?php
								printf(
									/* translators: %1$d: enabled, %2$d: total */
									esc_html__( '%1$d / %2$d', 'emcp-tools' ),
									(int) $emcp_tools_cat_enabled,
									(int) $emcp_tools_cat_total
								);
								?>
							</span>
						</button>
						<span class="elementor-mcp-cat-toggle-group" role="group" aria-label="<?php esc_attr_e( 'Toggle all tools in this section', 'emcp-tools' ); ?>">
							<button type="button" class="elementor-mcp-cat-btn elementor-mcp-cat-enable-all"><?php esc_html_e( 'All', 'emcp-tools' ); ?></button>
							<button type="button" class="elementor-mcp-cat-btn elementor-mcp-cat-disable-all"><?php esc_html_e( 'None', 'emcp-tools' ); ?></button>
						</span>
					</div>

					<div class="elementor-mcp-tools-grid" id="<?php echo esc_attr( $emcp_tools_grid_id ); ?>">
						<?php foreach ( $emcp_tools_category['tools'] as $emcp_tools_slug => $emcp_tools_tool ) : ?>
							<?php
							$emcp_tools_is_enabled = $emcp_tools_low_mode
								? in_array( $emcp_tools_slug, $emcp_tools_essentials, true )
								: ! in_array( $emcp_tools_slug, $emcp_tools_disabled, true );
							?>
							<label class="elementor-mcp-tool-card <?php echo esc_attr( $emcp_tools_is_enabled ? 'is-enabled' : 'is-disabled' ); ?>">
								<input
									type="checkbox"
									name="<?php echo esc_attr( EMCP_Tools_Admin::OPTION_DISABLED_TOOLS ); ?>[]"
									value="<?php echo esc_attr( $emcp_tools_slug ); ?>"
									data-essential="<?php echo in_array( $emcp_tools_slug, $emcp_tools_essentials, true ) ? '1' : '0'; ?>"
									data-stored-enabled="<?php echo in_array( $emcp_tools_slug, $emcp_tools_disabled, true ) ? '0' : '1'; ?>"
									<?php checked( $emcp_tools_is_enabled ); ?>
									<?php disabled( $emcp_tools_low_mode ); ?>
								/>
								<span class="elementor-mcp-toggle" aria-hidden="true">
									<span class="elementor-mcp-toggle-track"></span>
								</span>
								<span class="elementor-mcp-tool-info">
									<span class="elementor-mcp-tool-name">
										<?php echo esc_html( $emcp_tools_tool['label'] ); ?>
										<?php foreach ( $emcp_tools_tool['badges'] as $emcp_tools_badge ) : ?>
											<span class="elementor-mcp-badge elementor-mcp-badge--<?php echo esc_attr( $emcp_tools_badge ); ?>">
												<?php echo esc_html( $emcp_tools_badge_labels[ $emcp_tools_badge ] ?? $emcp_tools_badge ); ?>
											</span>
										<?php endforeach; ?>
									</span>
									<span class="elementor-mcp-tool-desc"><?php echo esc_html( $emcp_tools_tool['description'] ); ?></span>
									<code class="elementor-mcp-tool-slug"><?php echo esc_html( $emcp_tools_slug ); ?></code>
								</span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php $emcp_tools_first_panel = false; ?>
	<?php endforeach; ?>
```

- [ ] **Step 4: Lint the view**

Run: `/f/laragon/bin/php/php-8.4.15-nts-Win32-vs17-x64/php.exe -l includes/admin/views/page-tools.php`
Expected: No syntax errors.

- [ ] **Step 5: Commit**

```bash
git add includes/admin/views/page-tools.php
git commit -m "feat(admin): render Tools-page categories under Elementor/WordPress sub-tabs"
```

---

## Task 4: Sub-tab CSS

**Files:** Modify `assets/css/admin.css`

- [ ] **Step 1: Append the sub-tab styles**

Add to `assets/css/admin.css` (use the existing palette variables/colors in that file; flat fills only, no gradients):

```css
/* ── Tools page platform sub-tabs ─────────────────────────────── */
.elementor-mcp-subtabs {
	display: flex;
	gap: 4px;
	margin: 16px 0 18px;
	border-bottom: 1px solid #dcdcde;
}
.elementor-mcp-subtab {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 10px 18px;
	margin-bottom: -1px;
	background: transparent;
	border: 1px solid transparent;
	border-bottom: 2px solid transparent;
	border-radius: 6px 6px 0 0;
	font-size: 13px;
	font-weight: 600;
	color: #50575e;
	cursor: pointer;
}
.elementor-mcp-subtab:hover { color: #1d2327; background: #f0f0f1; }
.elementor-mcp-subtab.is-active {
	color: #1d2327;
	border-bottom-color: #2271b1;
}
.elementor-mcp-subtab-count {
	font-size: 11px;
	font-weight: 600;
	color: #646970;
	background: #f0f0f1;
	border-radius: 10px;
	padding: 1px 8px;
}
.elementor-mcp-subtab.is-active .elementor-mcp-subtab-count {
	color: #fff;
	background: #2271b1;
}
.elementor-mcp-tabpanel { display: none; }
.elementor-mcp-tabpanel.is-active { display: block; }
```

> If `admin.css` uses CSS custom properties for its brand colors, swap the hex values for those variables to match the rest of the file. Match the file's existing comment style.

- [ ] **Step 2: Commit**

```bash
git add assets/css/admin.css
git commit -m "style(admin): Tools-page sub-tab nav + panel styles (flat)"
```

---

## Task 5: Sub-tab switching JS + persistence

**Files:** Modify `assets/js/admin.js`

- [ ] **Step 1: Add the sub-tab controller**

In `assets/js/admin.js`, inside the same DOM-ready/init block that wires the Tools form (where `.elementor-mcp-enable-all` etc. are queried), add a self-contained sub-tab initializer. Place it near the other Tools-page wiring:

```javascript
	// Tools-page platform sub-tabs (Elementor / WordPress). Presentation only —
	// hidden panels keep their checkboxes in the form, so switching tabs never
	// affects what gets saved.
	( function initToolSubtabs() {
		var tabs = document.querySelectorAll( '.elementor-mcp-subtab' );
		var panels = document.querySelectorAll( '.elementor-mcp-tabpanel' );
		if ( ! tabs.length || ! panels.length ) {
			return;
		}
		var STORAGE_KEY = 'emcpToolsActiveTab';

		function activate( tabId ) {
			var matched = false;
			panels.forEach( function ( panel ) {
				var on = panel.getAttribute( 'data-tab' ) === tabId;
				panel.classList.toggle( 'is-active', on );
				if ( on ) { matched = true; }
			} );
			if ( ! matched ) {
				return; // unknown stored id (e.g. a removed tab) — leave server default.
			}
			tabs.forEach( function ( tab ) {
				var on = tab.getAttribute( 'data-tab' ) === tabId;
				tab.classList.toggle( 'is-active', on );
				tab.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			} );
			try { window.localStorage.setItem( STORAGE_KEY, tabId ); } catch ( e ) {}
		}

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				activate( tab.getAttribute( 'data-tab' ) );
			} );
		} );

		// Restore the last-used tab (falls back to the server-rendered active one).
		var stored = null;
		try { stored = window.localStorage.getItem( STORAGE_KEY ); } catch ( e ) {}
		if ( stored ) {
			activate( stored );
		}
	} )();
```

> Match the file's existing style (the file appears to use ES5/`var` + `addEventListener`). If `admin.js` wraps everything in a single IIFE/`DOMContentLoaded`, place `initToolSubtabs()` inside it so `document` is ready.

- [ ] **Step 2: Lint the JS (syntax) + commit**

Run: `node --check assets/js/admin.js` (if Node is available) — expect no output (valid). If Node isn't available, visually confirm balanced braces.
```bash
git add assets/js/admin.js
git commit -m "feat(admin): Tools-page sub-tab switching + localStorage persistence"
```

---

## Task 6: Docs + verification

**Files:** `CHANGELOG.md`, `readme.txt`; verification only otherwise.

- [ ] **Step 1: Docs (fold into v3.0.0)**

In `CHANGELOG.md`, inside the existing `## [3.0.0]` entry's `### Added` (or a new `### Changed` bullet under it), add:
```markdown
- **Tools admin page reorganized into Elementor / WordPress tabs.** The Tools screen now groups tool categories under two sub-tabs — Elementor (page-building tools) and WordPress (Content, Settings, Plugins & Themes, Users, Media, PHP Snippets, SEO) — making the growing tool set easier to manage. Accessibility tools sit under Elementor; SEO tools under WordPress. Presentation only — no change to which tools are enabled or how they're gated.
```
Add a matching one-line note to `readme.txt`'s `= 3.0.0 =` block. No version bump.

- [ ] **Step 2: Full PHPUnit, both configs**

```
/f/laragon/bin/php/php-8.4.15-nts-Win32-vs17-x64/php.exe vendor/bin/phpunit --configuration phpunit.xml.dist
/f/laragon/bin/php/php-8.4.15-nts-Win32-vs17-x64/php.exe vendor/bin/phpunit --configuration phpunit.xml
```
Expected: both green (incl. the 4 new platform tests).

- [ ] **Step 3: Browser verification (Playwright + injected auth cookie, ignoreHTTPSErrors)**

Load EMCP Tools → Tools. Confirm:
- Two sub-tabs render: "Elementor" and "WordPress", each with a `N/M` count.
- The **Elementor** panel is visible by default and shows Query/Page/Layout/Widgets/Templates/Global/Composite/SVG/Custom Code (and Accessibility + Widget Builder on Pro).
- Clicking **WordPress** hides the Elementor categories and shows WordPress Content/Settings/Plugins & Themes/Users/Stock & Media/PHP Snippets (and SEO on Pro).
- Toggle a tool on the **WordPress** tab, click Save → reload → the change persisted AND the WordPress tab is still active (localStorage). This proves hidden-panel checkboxes still submit.
- No PHP notices on the page and no JS console errors.

- [ ] **Step 4: Commit docs + report**

```bash
git add CHANGELOG.md readme.txt
git commit -m "docs: note Tools-page Elementor/WordPress tabs (v3.0.0)"
```
Report: PHPUnit counts (both configs), the browser observations (both tabs, switching, save-persists-from-hidden-tab, tab persistence), and any fix applied.
