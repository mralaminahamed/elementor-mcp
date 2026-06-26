<?php
/**
 * Site-wide context: admin-authored guidance injected into the MCP server
 * `instructions` (the initialize handshake) so connected AI agents apply it
 * automatically. Loaded unconditionally — the MCP server is registered on
 * non-admin requests.
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
class EMCP_Tools_Site_Context {

	/** Option holding the admin's markdown context. */
	const OPTION_CONTEXT = 'emcp_tools_site_context';

	/** Option holding the on/off toggle ('1' or '0'). Default on. */
	const OPTION_ENABLED = 'emcp_tools_site_context_enabled';

	/** Delimiter that separates the base description from the site context. */
	const DELIMITER = "\n\n## Site context\n\n";

	/** Hard cap on the stored/delivered context, in characters. */
	const MAX_CHARS = 20000;

	/**
	 * The base MCP server description (the tool-overview text). Single source
	 * of truth, reused by register_mcp_server() and the admin preview.
	 *
	 * @return string
	 */
	public static function default_base(): string {
		return __( 'Exposes Elementor data and design tools as MCP tools for AI agents.', 'emcp-tools' );
	}

	/**
	 * The admin's raw context markdown.
	 *
	 * @return string
	 */
	public static function get_context(): string {
		return (string) get_option( self::OPTION_CONTEXT, '' );
	}

	/**
	 * Whether context delivery is enabled (default on).
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return '1' === (string) get_option( self::OPTION_ENABLED, '1' );
	}

	/**
	 * Pure: build the instructions string from a base + raw context + toggle.
	 * Returns $base unchanged when disabled or the context is blank; otherwise
	 * appends the trimmed, capped context under the delimiter.
	 *
	 * @param string $base
	 * @param string $context
	 * @param bool   $enabled
	 * @return string
	 */
	public static function compose( string $base, string $context, bool $enabled ): string {
		$ctx = trim( $context );
		if ( ! $enabled || '' === $ctx ) {
			return $base;
		}
		return $base . self::DELIMITER . mb_substr( $ctx, 0, self::MAX_CHARS );
	}

	/**
	 * Build the live instructions string from the stored options.
	 *
	 * @param string $base
	 * @return string
	 */
	public static function compose_instructions( string $base ): string {
		return self::compose( $base, self::get_context(), self::is_enabled() );
	}
}
