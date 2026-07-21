# Contributing to EMCP Tools

Thanks for being here. Bug reports, docs fixes, prompts, and new tools are all genuinely useful, and you don't need to write PHP to help.

- **Found a bug?** [Open a bug report](https://github.com/msrbuilds/elementor-mcp/issues/new?template=bug_report.yml)
- **Want a tool that doesn't exist?** [Request a feature](https://github.com/msrbuilds/elementor-mcp/issues/new?template=feature_request.yml)
- **Want a plugin supported?** [Request an integration](https://github.com/msrbuilds/elementor-mcp/issues/new?template=integration_request.yml)
- **Just an idea or a question?** [Start a discussion](https://github.com/msrbuilds/elementor-mcp/issues/new?template=idea.yml)

User documentation lives at **[emcptools.com/docs](https://emcptools.com/docs/)**. This file is about working on the plugin itself.

## Ways to contribute

| | What it involves |
|---|---|
| **Report a bug** | The most valuable thing you can do. Include your MCP client, the tool you called, and what came back. |
| **Improve the docs** | The docs live in the website repo, but if something in this repo is wrong or stale, a PR fixing it is very welcome. |
| **Contribute a prompt** | A landing-page blueprint in [`prompts/`](prompts/). No PHP needed, see [Contributing prompts](#contributing-prompts). |
| **Add a tool** | A new MCP ability in an existing domain. See [Adding a tool](#adding-a-tool). |
| **Add an integration** | Support for a plugin we don't cover yet. This is the highest-leverage code contribution, see [Adding an integration](#adding-an-integration). |

## Development setup

**Requirements:** WordPress 6.9+, PHP 8.1+, Composer, and WP-CLI (strongly recommended). Elementor is **optional**: most domains work without it, install it if you're touching the page-building tools. The MCP Adapter and the Abilities API are bundled, so there's nothing extra to install.

```bash
cd /path/to/wordpress/wp-content/plugins
git clone https://github.com/msrbuilds/elementor-mcp.git emcp-tools
cd emcp-tools
composer install
```

Activate the plugin, then confirm the server registered:

```bash
wp mcp-adapter list --path=/path/to/wordpress
```

To poke at tools interactively:

```bash
npx @modelcontextprotocol/inspector wp mcp-adapter serve \
  --server=emcp-tools-server --user=admin --path=/path/to/wordpress
```

> **Note on the `pro/` directory.** The Pro tier lives in a separate private repository, mounted as a git submodule at `pro/`. A normal clone simply won't have it, and the plugin runs fine without it, every Pro unit is guarded. If you see references to `pro/` in the code, that's why.

## Repository layout

```
emcp-tools/
├── emcp-tools.php                  # Bootstrap: header, constants, requires, init
├── includes/
│   ├── class-bootstrap.php         # Loads every class, wires hooks
│   ├── class-plugin.php            # Singleton orchestrator
│   ├── class-elementor-data.php    # Elementor document read/write layer
│   ├── class-element-factory.php   # Builds valid Elementor JSON
│   ├── abilities/                  # MCP tools, grouped by domain
│   │   ├── class-ability-registrar.php   # Registers every group
│   │   ├── class-query-abilities.php     # Discovery (Elementor)
│   │   ├── class-content-abilities.php   # WordPress content
│   │   ├── forms/                        # Form-plugin integrations
│   │   ├── seo/                          # SEO-plugin integrations
│   │   └── addons/                       # Elementor addon packs (Pro)
│   ├── modules/                    # Toggleable features (Modules tab)
│   ├── themer/                     # EMCP Themer (theme builder)
│   ├── security/ · performance/    # Scanners
│   ├── schemas/ · validators/      # Control-to-JSON-Schema, input validation
│   └── admin/                      # Admin screens
├── prompts/                        # Landing-page blueprints
└── tests/                          # Public test suite
```

**Key ideas:**

- **Abilities are the tools.** Each one is registered with the WordPress Abilities API and surfaced over MCP.
- **Never write `_elementor_data` directly.** Go through the data layer, it triggers CSS regeneration and cache busting. Raw meta writes cause bugs that only appear on the front end.
- **Schemas are generated,** not hand-written, from Elementor's own widget controls.

## Adding a tool

Add it to the ability class for its domain, or create a new class if it genuinely doesn't fit.

**1. Register it.** Use the `emcp_tools_register_ability()` wrapper, not `wp_register_ability()` directly:

```php
emcp_tools_register_ability(
    'emcp-tools/my-new-tool',
    array(
        'label'               => __( 'My New Tool', 'emcp-tools' ),
        'description'         => __( 'What it does, written for an AI agent deciding whether to call it.', 'emcp-tools' ),
        'category'            => 'emcp-tools',
        'input_schema'        => array(
            'type'       => 'object',
            'properties' => array(
                'post_id' => array(
                    'type'        => 'integer',
                    'description' => __( 'The page/post ID.', 'emcp-tools' ),
                ),
            ),
            'required'   => array( 'post_id' ),
        ),
        'permission_callback' => array( $this, 'check_edit_permission' ),
        'execute_callback'    => array( $this, 'execute_my_new_tool' ),
    )
);
```

> **`category` is required.** Leave it out and `wp_register_ability()` drops the ability silently, with no error. The tool simply never appears. This has bitten us more than once.

**2. Implement it.** Return an array on success, a `WP_Error` on failure. Never return a bare `false`.

**3. Register the group** in `class-ability-registrar.php`, and add the tool to `get_all_tools()` in `includes/admin/class-admin.php` so it appears on the Tools tab.

**4. If it writes, deletes, or affects the whole site,** ship it disabled by default: bump `DEFAULTS_VERSION` in the admin class and seed your slug. Destructive tools should also require `confirm: true`.

**Descriptions are a UX surface.** An agent picks tools by reading them, so write for that reader: say what it does and when to use it, not just what it's called.

## Adding an integration

Support for a third-party plugin is the most useful code contribution, and it follows a fixed shape: **two dispatcher tools**, `<plugin>-read` and `<plugin>-write`, each taking `{ operation, arguments }`. That keeps the tool list small no matter how many plugins we support.

Look at `includes/abilities/forms/` or `includes/abilities/seo/` for working examples. Both have an abstract base class doing the dispatching, so a new adapter is mostly a map of operations.

Rules that matter:

- **Register only when the plugin is active.** Never assume.
- **Use the plugin's public API,** not its database tables. Tables change without notice; APIs are contracts.
- **Reads on by default, writes off.** Anything destructive needs `confirm: true`.
- **Never guess a field or control name.** Read it from the plugin. Many systems (Elementor and Spectra especially) accept any key you send without complaining, so a wrong name looks like it worked and silently does nothing.

Please open an [integration request](https://github.com/msrbuilds/elementor-mcp/issues/new?template=integration_request.yml) before starting a large one, so we can agree the operation list first.

## Contributing prompts

A prompt is a complete landing-page blueprint in `prompts/`. Include a design system (colours, typography, spacing), the full page structure, image search keywords, and the execution order. Use one of the existing files as your template, and name it `INDUSTRY_NAME.md` in caps with underscores.

Test it end to end against a real site before submitting: paste it into your AI client and confirm the page actually builds.

## Coding standards

WordPress coding standards, strictly.

- **Naming:** `snake_case` functions and variables, `Upper_Snake_Case` classes, `UPPER_SNAKE` constants.
- **Prefixes:** `EMCP_Tools_` for classes, `emcp_tools_` for functions, hooks, and options.
- **Text domain:** `emcp-tools`. Every user-facing string goes through `__()` / `esc_html__()`.
- **Security is not optional.** Sanitize input, escape output, `$wpdb->prepare()` for SQL, verify nonces, check capabilities before anything privileged.
- **PHP 8.1+.** Typed properties, union types, and named arguments are all fine.

## Testing

The public suite needs no private submodule:

```bash
composer install
vendor/bin/phpunit -c tests/phpunit.xml
```

Put new tests in `tests/`, named `SomethingTest.php`. Test the pure logic, validators, schema mapping, dispatcher routing, permission delegation, rather than trying to boot WordPress.

**Test the trap, not just the happy path.** The most valuable tests we have pin down behaviour that silently breaks: a permission that must not escalate, a signature that must not appear verbatim on disk, a confirm gate that must refuse. If you fixed a bug, add the test that would have caught it.

## Submitting a pull request

1. Fork, then branch from `main` (`git checkout -b feature/my-tool`).
2. Make the change, and run `php -l` on every file you touched.
3. Run the public test suite.
4. Add a `CHANGELOG.md` entry describing the change from a user's point of view.
5. Open the PR against `main`.

**Guidelines:**

- One feature or fix per PR. Small PRs get reviewed quickly; large mixed ones stall.
- Say what problem it solves, not just what it changes.
- If you found something surprising while building it, put that in the PR. It's often the most useful part.
- Don't update tool counts by hand, they're generated.

## Reporting bugs

Use the [bug report template](https://github.com/msrbuilds/elementor-mcp/issues/new?template=bug_report.yml). The details that actually speed up a fix:

- Plugin, WordPress, and PHP versions (and Elementor, if relevant)
- Which **MCP client** you're using
- The **tool you called** and the arguments
- What you expected versus what happened
- Anything from `wp-content/debug.log`

For anything security-sensitive, please **don't** open a public issue. Email **hello@msrbuilds.com** instead.

---

Every contribution helps, including the ones that just tell us something is broken. Thank you.
