<?php
/**
 * Builds a Claude Desktop .mcpb bundle (a zip containing manifest.json) that
 * installs the EMCP MCP server via the npx proxy, with credentials baked in.
 *
 * @package EMCP_Tools
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 3.0.0
 */
class EMCP_Tools_Mcpb_Builder {

	const MANIFEST_VERSION = '0.3';

	/**
	 * Relative path (inside the bundle) of the entry-point launcher. MCPB
	 * requires server.entry_point even when execution is driven by mcp_config;
	 * we ship a tiny launcher at this path so the manifest validates.
	 */
	const ENTRY_POINT = 'server/index.js';

	/**
	 * Build the MCPB manifest array. Pure — no I/O.
	 *
	 * @param string $site_url     home_url() of the WordPress site.
	 * @param string $username     WordPress login.
	 * @param string $app_password Application Password (baked into env).
	 * @return array
	 */
	public static function build_manifest( string $site_url, string $username, string $app_password ): array {
		$host    = (string) wp_parse_url( $site_url, PHP_URL_HOST );
		$version = defined( 'EMCP_TOOLS_VERSION' ) ? EMCP_TOOLS_VERSION : '0.0.0';

		return array(
			'manifest_version' => self::MANIFEST_VERSION,
			'name'             => 'emcp-tools',
			'display_name'     => sprintf( 'EMCP Tools — %s', $host ),
			'version'          => $version,
			'description'      => sprintf( 'Connect Claude Desktop to %s for Elementor and WordPress management via MCP.', $host ),
			'author'           => array( 'name' => 'MSR Builds' ),
			'server'           => array(
				'type'        => 'node',
				'entry_point' => self::ENTRY_POINT,
				'mcp_config'  => array(
					'command' => 'npx',
					'args'    => array( '-y', '@msrbuilds/emcp-proxy@latest' ),
					'env'     => array(
						'WP_URL'               => $site_url,
						'WP_USERNAME'          => $username,
						'WP_APP_PASSWORD'      => $app_password,
						'MCP_PROTOCOL_VERSION' => '2024-11-05',
					),
				),
			),
		);
	}

	/**
	 * Write the manifest into a temp .mcpb (zip) file and return its path, or
	 * WP_Error on failure.
	 *
	 * @param array $manifest
	 * @return string|\WP_Error Temp file path.
	 */
	public static function build_zip( array $manifest ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'no_zip', __( 'The ZipArchive PHP extension is required to build the bundle.', 'emcp-tools' ) );
		}
		$tmp = wp_tempnam( 'emcp-tools.mcpb' );
		if ( ! $tmp ) {
			return new \WP_Error( 'no_tmp', __( 'Could not create a temporary file for the bundle.', 'emcp-tools' ) );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $tmp, \ZipArchive::OVERWRITE | \ZipArchive::CREATE ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new \WP_Error( 'no_open', __( 'Could not open the bundle archive for writing.', 'emcp-tools' ) );
		}
		$zip->addFromString( 'manifest.json', (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		// Launcher that also works when Claude Desktop runs entry_point directly
		// without injecting mcp_config.env — credentials are embedded so the proxy
		// always gets them regardless of which path the host takes.
		$env = $manifest['server']['mcp_config']['env'] ?? array();
		$zip->addFromString( self::ENTRY_POINT, self::launcher_js( $env ) );
		$zip->close();
		return $tmp;
	}

	/**
	 * The bundled entry-point launcher (Node). Spawns the npx proxy with
	 * credentials embedded directly so the proxy starts correctly even when
	 * Claude Desktop runs entry_point without injecting mcp_config.env.
	 *
	 * @param array $env Key→value env vars from the manifest's mcp_config.env.
	 * @return string
	 */
	private static function launcher_js( array $env = array() ): string {
		// Build a JS object literal of the env vars to merge into process.env.
		$entries = array();
		foreach ( $env as $key => $value ) {
			$entries[] = sprintf(
				'  %s: %s',
				wp_json_encode( (string) $key ),
				wp_json_encode( (string) $value )
			);
		}
		$env_object = "{\n" . implode( ",\n", $entries ) . "\n}";

		return "#!/usr/bin/env node\n"
			. "'use strict';\n"
			. "// EMCP Tools MCPB launcher — credentials embedded so the proxy\n"
			. "// starts correctly even when mcp_config.env is not injected.\n"
			. "const { spawn } = require('child_process');\n"
			. "const injected = " . $env_object . ";\n"
			. "const env = Object.assign({}, process.env, injected);\n"
			. "const child = spawn('npx', ['-y', '@msrbuilds/emcp-proxy@latest'], {\n"
			. "  stdio: 'inherit', env: env, shell: process.platform === 'win32',\n"
			. "});\n"
			. "child.on('exit', (code) => process.exit(code == null ? 0 : code));\n";
	}
}
