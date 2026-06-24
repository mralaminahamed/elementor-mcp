<?php
/**
 * General WordPress Content MCP abilities.
 *
 * Eight tools for managing posts, pages, and any custom post type via MCP —
 * the plugin's first step beyond Elementor. Built on WP core functions, gated
 * by WordPress capabilities, and deliberately Elementor-agnostic: these tools
 * operate on post_content (classic HTML or block markup) and never touch
 * `_elementor_data`. To edit an Elementor-built page, use the Elementor tools.
 *
 * @package EMCP_Tools
 * @since   3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the WordPress content abilities.
 *
 * @since 3.1.0
 */
class EMCP_Tools_Content_Abilities {

	/**
	 * Names of the abilities actually registered by register().
	 *
	 * Populated at the top of each register_* method so get_ability_names()
	 * reports only the tools that exist this build — no phantom names leak to
	 * the MCP server's create_server() call.
	 *
	 * @since 3.1.0
	 * @var string[]
	 */
	private $ability_names = array();

	/**
	 * Returns the names of all abilities registered by this group.
	 *
	 * @since 3.1.0
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return $this->ability_names;
	}

	/**
	 * Registers this group's MCP abilities.
	 *
	 * @since 3.1.0
	 */
	public function register(): void {
		$this->register_list_post_types();
		$this->register_list_taxonomies();
	}

	// ---------------------------------------------------------------------
	// Permission callbacks
	// ---------------------------------------------------------------------

	/**
	 * Read/query permission: the user must be able to edit posts.
	 *
	 * @since 3.1.0
	 * @return bool
	 */
	public function check_read_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Create permission: creating a (draft) post only needs `edit_posts`.
	 *
	 * Mirrors WordPress core, where `edit_posts` is the meta-cap floor for
	 * authoring new posts; publishing is gated separately at save time. The
	 * read==create cap is therefore intentional, not an oversight.
	 *
	 * @since 3.1.0
	 * @return bool
	 */
	public function check_create_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Edit permission: `edit_posts` plus per-post ownership when a post_id is given.
	 *
	 * @since 3.1.0
	 * @param array|null $input Tool input; may carry a `post_id`.
	 * @return bool
	 */
	public function check_edit_permission( $input = null ): bool {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		$post_id = absint( $input['post_id'] ?? 0 );
		return ! $post_id || current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Delete permission: `delete_posts` plus per-post ownership when a post_id is given.
	 *
	 * @since 3.1.0
	 * @param array|null $input Tool input; may carry a `post_id`.
	 * @return bool
	 */
	public function check_delete_permission( $input = null ): bool {
		if ( ! current_user_can( 'delete_posts' ) ) {
			return false;
		}
		$post_id = absint( $input['post_id'] ?? 0 );
		return ! $post_id || current_user_can( 'delete_post', $post_id );
	}

	// ---------------------------------------------------------------------
	// list-post-types
	// ---------------------------------------------------------------------

	private function register_list_post_types(): void {
		$this->ability_names[] = 'emcp-tools/list-post-types';
		emcp_tools_register_ability(
			'emcp-tools/list-post-types',
			array(
				'label'               => __( 'List Post Types', 'emcp-tools' ),
				'description'         => __( 'Lists registered WordPress post types (posts, pages, and any custom post type) so you can target the right one with create-post / list-posts. Returns name, label, whether it is hierarchical, and its taxonomies.', 'emcp-tools' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_list_post_types' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'public_only' => array( 'type' => 'boolean', 'description' => __( 'Only public, non-internal types. Default: true.', 'emcp-tools' ) ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_types' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_list_post_types( $input ): array {
		$public_only = ! isset( $input['public_only'] ) || (bool) $input['public_only'];
		$args        = $public_only ? array( 'public' => true ) : array();
		$objects     = get_post_types( $args, 'objects' );

		$internal = array( 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' );
		$rows     = array();
		foreach ( $objects as $name => $obj ) {
			if ( $public_only && in_array( $name, $internal, true ) ) {
				continue;
			}
			$rows[] = array(
				'name'         => (string) $name,
				'label'        => (string) ( $obj->label ?? $name ),
				'hierarchical' => (bool) ( $obj->hierarchical ?? false ),
				'public'       => (bool) ( $obj->public ?? false ),
				'supports'     => function_exists( 'get_all_post_type_supports' ) ? array_keys( get_all_post_type_supports( $name ) ) : array(),
				'taxonomies'   => function_exists( 'get_object_taxonomies' ) ? array_values( get_object_taxonomies( $name ) ) : array(),
			);
		}
		return array( 'post_types' => $rows );
	}

	// ---------------------------------------------------------------------
	// list-taxonomies
	// ---------------------------------------------------------------------

	private function register_list_taxonomies(): void {
		$this->ability_names[] = 'emcp-tools/list-taxonomies';
		emcp_tools_register_ability(
			'emcp-tools/list-taxonomies',
			array(
				'label'               => __( 'List Taxonomies', 'emcp-tools' ),
				'description'         => __( 'Lists registered taxonomies (categories, tags, custom taxonomies) and optionally their terms, so you can categorize content with set-post-terms or the create-post "terms" param.', 'emcp-tools' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_list_taxonomies' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_type'     => array( 'type' => 'string', 'description' => __( 'Only taxonomies attached to this post type.', 'emcp-tools' ) ),
						'include_terms' => array( 'type' => 'boolean', 'description' => __( 'Embed each taxonomy\'s terms (capped). Default: false.', 'emcp-tools' ) ),
						'terms_limit'   => array( 'type' => 'integer', 'description' => __( 'Max terms per taxonomy when include_terms. Default: 100.', 'emcp-tools' ) ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'taxonomies' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_list_taxonomies( $input ): array {
		$post_type     = sanitize_key( $input['post_type'] ?? '' );
		$include_terms = ! empty( $input['include_terms'] );
		$limit         = max( 1, min( 500, absint( $input['terms_limit'] ?? 100 ) ) );

		$objects = $post_type
			? get_taxonomies( array( 'object_type' => array( $post_type ) ), 'objects' )
			: get_taxonomies( array(), 'objects' );

		$rows = array();
		foreach ( $objects as $name => $obj ) {
			$row = array(
				'name'         => (string) $name,
				'label'        => (string) ( $obj->label ?? $name ),
				'hierarchical' => (bool) ( $obj->hierarchical ?? false ),
				'object_types' => array_values( (array) ( $obj->object_type ?? array() ) ),
			);
			if ( $include_terms ) {
				$terms        = get_terms( array( 'taxonomy' => $name, 'hide_empty' => false, 'number' => $limit ) );
				$row['terms'] = array();
				foreach ( (array) $terms as $t ) {
					if ( is_object( $t ) ) {
						$row['terms'][] = array(
							'term_id' => (int) $t->term_id,
							'name'    => (string) $t->name,
							'slug'    => (string) $t->slug,
							'parent'  => (int) ( $t->parent ?? 0 ),
							'count'   => (int) ( $t->count ?? 0 ),
						);
					}
				}
			}
			$rows[] = $row;
		}
		return array( 'taxonomies' => $rows );
	}
}
