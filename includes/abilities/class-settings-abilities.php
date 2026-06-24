<?php
/**
 * WordPress site-settings MCP abilities.
 *
 * Two tools — get-settings (read + discovery) and update-settings (batch write) —
 * over a CURATED, TYPED ALLOWLIST of core WordPress settings (the Settings →
 * General/Reading/Writing/Discussion/Media/Permalinks screens). Arbitrary
 * get_option/update_option over any key is deliberately NOT exposed: only keys
 * in self::allowlist() are ever read or written. siteurl/home (lock-out),
 * users_can_register/default_role (registration escalation) are absent;
 * admin_email is read-only. Both tools require manage_options.
 *
 * Naming: distinct from EMCP_Tools_Settings_Validator (which validates Elementor
 * widget settings) — unrelated class, no collision.
 *
 * @package EMCP_Tools
 * @since   3.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the WordPress settings abilities.
 *
 * @since 3.2.0
 */
class EMCP_Tools_Settings_Abilities {

	/**
	 * Names of the abilities actually registered by register().
	 *
	 * @since 3.2.0
	 * @var string[]
	 */
	private $ability_names = array();

	/**
	 * Returns the names of all abilities registered by this group.
	 *
	 * @since 3.2.0
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return $this->ability_names;
	}

	/**
	 * Registers this group's MCP abilities.
	 *
	 * @since 3.2.0
	 */
	public function register(): void {
		$this->register_get_settings();
		$this->register_update_settings();
	}

	/**
	 * Permission gate: both tools require manage_options.
	 *
	 * @since 3.2.0
	 * @return bool
	 */
	public function check_manage_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	// ---------------------------------------------------------------------
	// Allowlist (source of truth)
	// ---------------------------------------------------------------------

	/**
	 * The typed allowlist: option name => metadata.
	 *
	 * type: string|int|bool|enum. writable:false → read-only. options: enum
	 * members. min/max: int clamp range. group: the Settings screen it belongs to.
	 *
	 * @since 3.2.0
	 * @return array<string,array>
	 */
	private static function allowlist(): array {
		return array(
			// General.
			'blogname'                      => array( 'group' => 'general', 'label' => 'Site Title', 'type' => 'string', 'writable' => true ),
			'blogdescription'               => array( 'group' => 'general', 'label' => 'Tagline', 'type' => 'string', 'writable' => true ),
			'admin_email'                   => array( 'group' => 'general', 'label' => 'Administration Email', 'type' => 'string', 'writable' => false ),
			'timezone_string'               => array( 'group' => 'general', 'label' => 'Timezone', 'type' => 'string', 'writable' => true ),
			'gmt_offset'                    => array( 'group' => 'general', 'label' => 'GMT Offset', 'type' => 'string', 'writable' => true ),
			'date_format'                   => array( 'group' => 'general', 'label' => 'Date Format', 'type' => 'string', 'writable' => true ),
			'time_format'                   => array( 'group' => 'general', 'label' => 'Time Format', 'type' => 'string', 'writable' => true ),
			'start_of_week'                 => array( 'group' => 'general', 'label' => 'Week Starts On', 'type' => 'int', 'writable' => true, 'min' => 0, 'max' => 6 ),
			'WPLANG'                        => array( 'group' => 'general', 'label' => 'Site Language', 'type' => 'string', 'writable' => true ),
			// Reading.
			'show_on_front'                 => array( 'group' => 'reading', 'label' => 'Front Page Displays', 'type' => 'enum', 'writable' => true, 'options' => array( 'posts', 'page' ) ),
			'page_on_front'                 => array( 'group' => 'reading', 'label' => 'Front Page', 'type' => 'int', 'writable' => true, 'min' => 0 ),
			'page_for_posts'                => array( 'group' => 'reading', 'label' => 'Posts Page', 'type' => 'int', 'writable' => true, 'min' => 0 ),
			'posts_per_page'                => array( 'group' => 'reading', 'label' => 'Blog Pages Show At Most', 'type' => 'int', 'writable' => true, 'min' => 1, 'max' => 100 ),
			'posts_per_rss'                 => array( 'group' => 'reading', 'label' => 'Syndication Feeds Show', 'type' => 'int', 'writable' => true, 'min' => 1, 'max' => 100 ),
			'rss_use_excerpt'               => array( 'group' => 'reading', 'label' => 'Feed Shows Summary', 'type' => 'bool', 'writable' => true ),
			'blog_public'                   => array( 'group' => 'reading', 'label' => 'Search Engine Visibility', 'type' => 'bool', 'writable' => true ),
			// Writing.
			'default_category'              => array( 'group' => 'writing', 'label' => 'Default Post Category', 'type' => 'int', 'writable' => true, 'min' => 0 ),
			'default_post_format'           => array( 'group' => 'writing', 'label' => 'Default Post Format', 'type' => 'enum', 'writable' => true, 'options' => array( '0', 'aside', 'gallery', 'link', 'image', 'quote', 'status', 'video', 'audio', 'chat' ) ),
			// Discussion.
			'default_comment_status'        => array( 'group' => 'discussion', 'label' => 'Allow Comments By Default', 'type' => 'enum', 'writable' => true, 'options' => array( 'open', 'closed' ) ),
			'default_ping_status'           => array( 'group' => 'discussion', 'label' => 'Allow Pingbacks By Default', 'type' => 'enum', 'writable' => true, 'options' => array( 'open', 'closed' ) ),
			'comment_registration'          => array( 'group' => 'discussion', 'label' => 'Users Must Register To Comment', 'type' => 'bool', 'writable' => true ),
			'require_name_email'            => array( 'group' => 'discussion', 'label' => 'Comment Author Must Fill Name/Email', 'type' => 'bool', 'writable' => true ),
			'comment_moderation'            => array( 'group' => 'discussion', 'label' => 'Hold Comments For Moderation', 'type' => 'bool', 'writable' => true ),
			'comments_per_page'             => array( 'group' => 'discussion', 'label' => 'Comments Per Page', 'type' => 'int', 'writable' => true, 'min' => 1, 'max' => 200 ),
			'thread_comments'               => array( 'group' => 'discussion', 'label' => 'Enable Threaded Comments', 'type' => 'bool', 'writable' => true ),
			'close_comments_for_old_posts'  => array( 'group' => 'discussion', 'label' => 'Auto-Close Comments On Old Posts', 'type' => 'bool', 'writable' => true ),
			// Media.
			'thumbnail_size_w'              => array( 'group' => 'media', 'label' => 'Thumbnail Width', 'type' => 'int', 'writable' => true, 'min' => 0, 'max' => 9999 ),
			'thumbnail_size_h'              => array( 'group' => 'media', 'label' => 'Thumbnail Height', 'type' => 'int', 'writable' => true, 'min' => 0, 'max' => 9999 ),
			'medium_size_w'                 => array( 'group' => 'media', 'label' => 'Medium Width', 'type' => 'int', 'writable' => true, 'min' => 0, 'max' => 9999 ),
			'medium_size_h'                 => array( 'group' => 'media', 'label' => 'Medium Height', 'type' => 'int', 'writable' => true, 'min' => 0, 'max' => 9999 ),
			'large_size_w'                  => array( 'group' => 'media', 'label' => 'Large Width', 'type' => 'int', 'writable' => true, 'min' => 0, 'max' => 9999 ),
			'large_size_h'                  => array( 'group' => 'media', 'label' => 'Large Height', 'type' => 'int', 'writable' => true, 'min' => 0, 'max' => 9999 ),
			'uploads_use_yearmonth_folders' => array( 'group' => 'media', 'label' => 'Organize Uploads Into Month/Year Folders', 'type' => 'bool', 'writable' => true ),
			// Permalinks.
			'permalink_structure'           => array( 'group' => 'permalinks', 'label' => 'Permalink Structure', 'type' => 'string', 'writable' => true ),
			'category_base'                 => array( 'group' => 'permalinks', 'label' => 'Category Base', 'type' => 'string', 'writable' => true ),
			'tag_base'                      => array( 'group' => 'permalinks', 'label' => 'Tag Base', 'type' => 'string', 'writable' => true ),
		);
	}

	/** Valid group names (the Settings screens). */
	private static function groups(): array {
		return array( 'general', 'reading', 'writing', 'discussion', 'media', 'permalinks' );
	}

	/** Whether a key belongs to the permalinks group. */
	private function is_permalink_key( string $key ): bool {
		$map = self::allowlist();
		return isset( $map[ $key ] ) && 'permalinks' === $map[ $key ]['group'];
	}

	// ---------------------------------------------------------------------
	// get-settings
	// ---------------------------------------------------------------------

	private function register_get_settings(): void {
		$this->ability_names[] = 'emcp-tools/get-settings';
		emcp_tools_register_ability(
			'emcp-tools/get-settings',
			array(
				'label'               => __( 'Get Settings', 'emcp-tools' ),
				'description'         => __( 'Reads curated WordPress site settings (General, Reading, Writing, Discussion, Media, Permalinks). With no args returns every allowlisted setting; pass "group" to filter to one screen or "keys" for specific settings. Each row carries the value plus metadata (type, label, writable, enum options) so this doubles as discovery for update-settings.', 'emcp-tools' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_get_settings' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'group' => array( 'type' => 'string', 'enum' => array( 'general', 'reading', 'writing', 'discussion', 'media', 'permalinks' ), 'description' => __( 'Filter to one Settings screen.', 'emcp-tools' ) ),
						'keys'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => __( 'Return only these allowlisted keys.', 'emcp-tools' ) ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'settings' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	// ---------------------------------------------------------------------
	// update-settings
	// ---------------------------------------------------------------------

	private function register_update_settings(): void {
		$this->ability_names[] = 'emcp-tools/update-settings';
		emcp_tools_register_ability(
			'emcp-tools/update-settings',
			array(
				'label'               => __( 'Update Settings', 'emcp-tools' ),
				'description'         => __( 'Updates curated WordPress site settings from a map of key → value. Only allowlisted, writable keys are changed; non-allowlisted, read-only (admin_email), or invalid values are returned in "skipped" with a reason — one bad key never aborts the batch. Changing a permalink setting (permalink_structure, category_base, tag_base) flushes rewrite rules automatically.', 'emcp-tools' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_update_settings' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'settings' => array( 'type' => 'object', 'description' => __( 'Map of allowlisted setting key → new value.', 'emcp-tools' ) ),
					),
					'required'   => array( 'settings' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'updated'         => array( 'type' => 'object' ),
						'skipped'         => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
						'rewrite_flushed' => array( 'type' => 'boolean' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}
}
