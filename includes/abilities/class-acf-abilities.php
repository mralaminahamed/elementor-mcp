<?php
/**
 * Advanced Custom Fields (ACF / ACF PRO) MCP abilities.
 *
 * Seven tools — list-acf-field-groups, get-acf-field-group, list-acf-options-pages,
 * get-acf-fields (read), update-acf-fields, create-acf-field-group,
 * update-acf-field-group (write) — for reading/writing ACF field values on posts
 * and options pages, discovering field groups (the "schema discovery" step an
 * agent runs before writing), and authoring field groups programmatically.
 *
 * The whole group only registers when ACF (free or Pro) is active. Pro-only
 * capabilities (options pages; repeater / flexible content / gallery / clone
 * fields) degrade gracefully on free ACF. Writes ship disabled-by-default and
 * there is deliberately NO delete tool: field groups and values can be created
 * and edited but never removed via MCP, and field names/keys can never be
 * renamed (renames orphan postmeta).
 *
 * @package EMCP_Tools
 * @since   3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the ACF abilities.
 *
 * @since 3.3.0
 */
class EMCP_Tools_ACF_Abilities {

	/** Field types that require ACF PRO. @since 3.3.0 */
	const PRO_FIELD_TYPES = array( 'repeater', 'flexible_content', 'gallery', 'clone' );

	/** Max depth when normalizing values / validating field definitions. @since 3.3.0 */
	const MAX_DEPTH = 10;

	/** @since 3.3.0 @var string[] */
	private $ability_names = array();

	/**
	 * Per-request cache of resolved field indexes, keyed by target.
	 *
	 * @since 3.3.0
	 * @var array<string,array>
	 */
	private $field_index_cache = array();

	/** @since 3.3.0 @return string[] */
	public function get_ability_names(): array {
		return $this->ability_names;
	}

	/** @since 3.3.0 */
	public function register(): void {
		$this->register_list_field_groups();
		$this->register_get_field_group();
		$this->register_list_options_pages();
		$this->register_get_fields();
		$this->register_update_fields();
		$this->register_create_field_group();
		$this->register_update_field_group();
	}

	// -------------------------------------------------------------------
	// Environment detection
	// -------------------------------------------------------------------

	/**
	 * Whether ACF (free or Pro) is active and exposes the field-group API.
	 *
	 * @since 3.3.0
	 * @return bool
	 */
	public static function acf_active(): bool {
		return function_exists( 'acf_get_field_groups' );
	}

	/**
	 * Whether ACF PRO is active.
	 *
	 * @since 3.3.0
	 * @return bool
	 */
	public static function is_pro(): bool {
		if ( function_exists( 'acf_get_setting' ) ) {
			return (bool) acf_get_setting( 'pro' );
		}
		return defined( 'ACF_PRO' );
	}

	// -------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------

	/**
	 * Read/discovery permission: editors need to see field groups to fill values.
	 *
	 * @since 3.3.0
	 * @return bool
	 */
	public function check_read_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Field-value permission: `edit_post` on the target post, or `manage_options`
	 * for options-page targets (site-wide data, consistent with the Settings domain).
	 *
	 * @since 3.3.0
	 * @param array|null $input Tool input; carries `post_id` or `options_page`.
	 * @return bool
	 */
	public function check_fields_permission( $input = null ): bool {
		if ( isset( $input['options_page'] ) && '' !== (string) $input['options_page'] ) {
			return current_user_can( 'manage_options' );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		$post_id = absint( $input['post_id'] ?? 0 );
		return ! $post_id || current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Field-group authoring permission: field groups are site-wide definitions.
	 *
	 * @since 3.3.0
	 * @return bool
	 */
	public function check_manage_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	// -------------------------------------------------------------------
	// list-acf-field-groups
	// -------------------------------------------------------------------

	private function register_list_field_groups(): void {
		$this->ability_names[] = 'emcp-tools/list-acf-field-groups';
		emcp_tools_register_ability(
			'emcp-tools/list-acf-field-groups',
			array(
				'label'               => __( 'List ACF Field Groups', 'emcp-tools' ),
				'description'         => __( 'Lists ACF field groups: key, title, active state, field count, and a "local" flag (json/php = registered from code, not editable via MCP). Filter by search text, active state, or post_id (only groups whose location rules match that post). Use get-acf-field-group next to inspect a group\'s fields before reading/writing values.', 'emcp-tools' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_list_field_groups' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'search'      => array( 'type' => 'string', 'description' => __( 'Case-insensitive match on the group title.', 'emcp-tools' ) ),
						'active_only' => array( 'type' => 'boolean', 'description' => __( 'Only active groups. Default: true.', 'emcp-tools' ) ),
						'post_id'     => array( 'type' => 'integer', 'description' => __( 'Only groups whose location rules match this post.', 'emcp-tools' ) ),
					),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array(
					'groups' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					'total'  => array( 'type' => 'integer' ),
					'pro'    => array( 'type' => 'boolean' ),
				) ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_list_field_groups( $input ): array {
		$args = array();
		if ( ! empty( $input['post_id'] ) ) {
			$args['post_id'] = absint( $input['post_id'] );
		}
		$groups      = acf_get_field_groups( $args );
		$search      = isset( $input['search'] ) ? strtolower( sanitize_text_field( (string) $input['search'] ) ) : '';
		$active_only = ! isset( $input['active_only'] ) || (bool) $input['active_only'];

		$rows = array();
		foreach ( (array) $groups as $group ) {
			if ( $active_only && empty( $group['active'] ) ) {
				continue;
			}
			$title = (string) ( $group['title'] ?? '' );
			if ( '' !== $search && false === strpos( strtolower( $title ), $search ) ) {
				continue;
			}
			$fields = function_exists( 'acf_get_fields' ) ? (array) acf_get_fields( $group ) : array();
			$rows[] = array(
				'key'         => (string) ( $group['key'] ?? '' ),
				'id'          => (int) ( $group['ID'] ?? 0 ),
				'title'       => $title,
				'active'      => ! empty( $group['active'] ),
				'local'       => ! empty( $group['local'] ) ? (string) $group['local'] : null,
				'field_count' => count( $fields ),
			);
		}

		return array(
			'groups' => $rows,
			'total'  => count( $rows ),
			'pro'    => self::is_pro(),
		);
	}

	// -------------------------------------------------------------------
	// get-acf-field-group
	// -------------------------------------------------------------------

	private function register_get_field_group(): void {
		$this->ability_names[] = 'emcp-tools/get-acf-field-group';
		emcp_tools_register_ability(
			'emcp-tools/get-acf-field-group',
			array(
				'label'               => __( 'Get ACF Field Group', 'emcp-tools' ),
				'description'         => __( 'Returns one field group\'s full definition: location rules, settings, and the recursive field tree (key, name, label, type, required, choices, sub_fields for repeater/group, layouts for flexible content). This is the schema-discovery step: call it before update-acf-fields to learn each field\'s name, key, and expected value shape.', 'emcp-tools' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_get_field_group' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'key' => array( 'type' => 'string', 'description' => __( 'Field group key (group_xxx) or numeric post ID.', 'emcp-tools' ) ),
					),
					'required'   => array( 'key' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array(
					'key'      => array( 'type' => 'string' ),
					'id'       => array( 'type' => 'integer' ),
					'title'    => array( 'type' => 'string' ),
					'active'   => array( 'type' => 'boolean' ),
					'local'    => array( 'type' => array( 'string', 'null' ) ),
					'location' => array( 'type' => 'array', 'items' => array( 'type' => 'array' ) ),
					'fields'   => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				) ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_get_field_group( $input ) {
		$key = sanitize_text_field( (string) ( $input['key'] ?? '' ) );
		if ( '' === $key ) {
			return new \WP_Error( 'missing_params', __( 'A field group "key" is required.', 'emcp-tools' ) );
		}
		$group = acf_get_field_group( is_numeric( $key ) ? (int) $key : $key );
		if ( ! $group ) {
			return new \WP_Error( 'group_not_found', __( 'Field group not found.', 'emcp-tools' ) );
		}
		$fields = function_exists( 'acf_get_fields' ) ? (array) acf_get_fields( $group ) : array();

		return array(
			'key'      => (string) ( $group['key'] ?? '' ),
			'id'       => (int) ( $group['ID'] ?? 0 ),
			'title'    => (string) ( $group['title'] ?? '' ),
			'active'   => ! empty( $group['active'] ),
			'local'    => ! empty( $group['local'] ) ? (string) $group['local'] : null,
			'location' => (array) ( $group['location'] ?? array() ),
			'position' => (string) ( $group['position'] ?? '' ),
			'fields'   => array_map( array( $this, 'format_field' ), $fields ),
		);
	}

	/**
	 * Compact recursive view of one field definition (sub_fields / layouts included).
	 *
	 * @since 3.3.0
	 * @param array $field ACF field array.
	 * @param int   $depth Recursion depth guard.
	 * @return array
	 */
	private function format_field( $field, int $depth = 0 ): array {
		$field = (array) $field;
		$out   = array(
			'key'      => (string) ( $field['key'] ?? '' ),
			'name'     => (string) ( $field['name'] ?? '' ),
			'label'    => (string) ( $field['label'] ?? '' ),
			'type'     => (string) ( $field['type'] ?? '' ),
			'required' => ! empty( $field['required'] ),
		);
		foreach ( array( 'instructions', 'choices', 'default_value', 'return_format', 'min', 'max', 'multiple', 'allow_null', 'post_type', 'taxonomy' ) as $setting ) {
			if ( isset( $field[ $setting ] ) && '' !== $field[ $setting ] && array() !== $field[ $setting ] ) {
				$out[ $setting ] = $field[ $setting ];
			}
		}
		if ( $depth >= self::MAX_DEPTH ) {
			return $out;
		}
		if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
			$out['sub_fields'] = array();
			foreach ( $field['sub_fields'] as $sub ) {
				$out['sub_fields'][] = $this->format_field( $sub, $depth + 1 );
			}
		}
		if ( ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
			$out['layouts'] = array();
			foreach ( $field['layouts'] as $layout ) {
				$layout_row = array(
					'key'   => (string) ( $layout['key'] ?? '' ),
					'name'  => (string) ( $layout['name'] ?? '' ),
					'label' => (string) ( $layout['label'] ?? '' ),
				);
				if ( ! empty( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
					$layout_row['sub_fields'] = array();
					foreach ( $layout['sub_fields'] as $sub ) {
						$layout_row['sub_fields'][] = $this->format_field( $sub, $depth + 1 );
					}
				}
				$out['layouts'][] = $layout_row;
			}
		}
		return $out;
	}

	// -------------------------------------------------------------------
	// list-acf-options-pages
	// -------------------------------------------------------------------

	private function register_list_options_pages(): void {
		$this->ability_names[] = 'emcp-tools/list-acf-options-pages';
		emcp_tools_register_ability(
			'emcp-tools/list-acf-options-pages',
			array(
				'label'               => __( 'List ACF Options Pages', 'emcp-tools' ),
				'description'         => __( 'Lists registered ACF options pages (an ACF PRO feature). Returns menu_slug, page_title, and the post_id string to pass as options_page to get-acf-fields / update-acf-fields. On free ACF returns an empty list with pro:false.', 'emcp-tools' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_list_options_pages' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array( 'type' => 'object', 'properties' => (object) array() ),
				'output_schema'       => array( 'type' => 'object', 'properties' => array(
					'pro'   => array( 'type' => 'boolean' ),
					'pages' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				) ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_list_options_pages( $input ): array {
		if ( ! function_exists( 'acf_get_options_pages' ) ) {
			return array( 'pro' => self::is_pro(), 'pages' => array() );
		}
		$pages = acf_get_options_pages();
		$rows  = array();
		foreach ( (array) $pages as $page ) {
			$page   = (array) $page;
			$rows[] = array(
				'menu_slug'   => (string) ( $page['menu_slug'] ?? '' ),
				'page_title'  => (string) ( $page['page_title'] ?? '' ),
				'post_id'     => (string) ( $page['post_id'] ?? 'options' ),
				'parent_slug' => (string) ( $page['parent_slug'] ?? '' ),
			);
		}
		return array( 'pro' => true, 'pages' => $rows );
	}

	// -------------------------------------------------------------------
	// get-acf-fields
	// -------------------------------------------------------------------

	private function register_get_fields(): void {
		$this->ability_names[] = 'emcp-tools/get-acf-fields';
		emcp_tools_register_ability(
			'emcp-tools/get-acf-fields',
			array(
				'label'               => __( 'Get ACF Fields', 'emcp-tools' ),
				'description'         => __( 'Reads ACF field values from a post (post_id) or an options page (options_page — pass "options" or the post_id string from list-acf-options-pages). Pass exactly one target. Values are formatted: repeaters/flexible content come back as nested row arrays, images/posts/users as compact objects. Fields defined for a post but not yet saved are returned with a null/empty value. Optionally limit with fields[] (names or keys) or set include_field_objects for key/type/label per field.', 'emcp-tools' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_get_fields' ),
				'permission_callback' => array( $this, 'check_fields_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'               => array( 'type' => 'integer', 'description' => __( 'Target post/page/CPT ID.', 'emcp-tools' ) ),
						'options_page'          => array( 'type' => 'string', 'description' => __( 'Target options page: "options" or a custom post_id string. ACF PRO only.', 'emcp-tools' ) ),
						'fields'                => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => __( 'Limit to these field names or keys.', 'emcp-tools' ) ),
						'include_field_objects' => array( 'type' => 'boolean', 'description' => __( 'Return { key, type, label, value } per field instead of the bare value. Default: false.', 'emcp-tools' ) ),
					),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array(
					'target' => array( 'type' => 'string' ),
					'fields' => array( 'type' => 'object' ),
					'pro'    => array( 'type' => 'boolean' ),
				) ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_get_fields( $input ) {
		$target = $this->resolve_target( $input );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$index = $this->field_index( $target );
		$only  = array();
		if ( ! empty( $input['fields'] ) && is_array( $input['fields'] ) ) {
			$only = array_map( 'strval', $input['fields'] );
		}
		$with_objects = ! empty( $input['include_field_objects'] );

		$out = array();
		foreach ( $index['fields'] as $field ) {
			if ( $only && ! in_array( $field['name'], $only, true ) && ! in_array( $field['key'], $only, true ) ) {
				continue;
			}
			$value = function_exists( 'get_field' ) ? get_field( $field['key'], $target, true ) : null;
			$value = $this->normalize_value( $value );
			if ( $with_objects ) {
				$out[ $field['name'] ] = array(
					'key'   => $field['key'],
					'type'  => (string) ( $field['type'] ?? '' ),
					'label' => (string) ( $field['label'] ?? '' ),
					'value' => $value,
				);
			} else {
				$out[ $field['name'] ] = $value;
			}
		}

		return array(
			'target' => (string) $target,
			'fields' => $out,
			'pro'    => self::is_pro(),
		);
	}

	// -------------------------------------------------------------------
	// update-acf-fields
	// -------------------------------------------------------------------

	private function register_update_fields(): void {
		$this->ability_names[] = 'emcp-tools/update-acf-fields';
		emcp_tools_register_ability(
			'emcp-tools/update-acf-fields',
			array(
				'label'               => __( 'Update ACF Fields', 'emcp-tools' ),
				'description'         => __( 'Writes ACF field values on a post (post_id) or options page (options_page). Pass exactly one target and a fields map of field name (or key) to value. Value shapes: repeater = array of row objects keyed by sub-field name; flexible content = array of rows each with an "acf_fc_layout" set to the layout name; group = object; gallery = array of attachment IDs; image/file = attachment ID; relationship/post_object = post ID(s). Run get-acf-field-group first to learn the field names, types, and layout names. Unknown fields are skipped with a reason, never guessed.', 'emcp-tools' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_update_fields' ),
				'permission_callback' => array( $this, 'check_fields_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'      => array( 'type' => 'integer', 'description' => __( 'Target post/page/CPT ID.', 'emcp-tools' ) ),
						'options_page' => array( 'type' => 'string', 'description' => __( 'Target options page: "options" or a custom post_id string. ACF PRO only.', 'emcp-tools' ) ),
						'fields'       => array( 'type' => 'object', 'description' => __( 'Map of field name (or field key) to the new value.', 'emcp-tools' ) ),
					),
					'required'   => array( 'fields' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array(
					'target'  => array( 'type' => 'string' ),
					'updated' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'skipped' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					'values'  => array( 'type' => 'object' ),
				) ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_update_fields( $input ) {
		$target = $this->resolve_target( $input );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$fields = $input['fields'] ?? null;
		if ( ! is_array( $fields ) || array() === $fields ) {
			return new \WP_Error( 'missing_params', __( 'A non-empty "fields" map is required.', 'emcp-tools' ) );
		}

		$updated = array();
		$skipped = array();
		$values  = array();

		foreach ( $fields as $name_or_key => $value ) {
			$name_or_key = (string) $name_or_key;
			$field       = $this->resolve_field( $name_or_key, $target );
			if ( ! $field ) {
				$skipped[] = array( 'field' => $name_or_key, 'reason' => 'field_not_found' );
				continue;
			}
			$type = (string) ( $field['type'] ?? '' );
			if ( in_array( $type, self::PRO_FIELD_TYPES, true ) && ! self::is_pro() ) {
				$skipped[] = array( 'field' => $name_or_key, 'reason' => 'acf_pro_required' );
				continue;
			}
			if ( 'flexible_content' === $type ) {
				$layout_error = $this->validate_flexible_rows( $value, $field );
				if ( null !== $layout_error ) {
					$skipped[] = array( 'field' => $name_or_key, 'reason' => $layout_error );
					continue;
				}
			}

			// Always write by field KEY: update_field() by name silently fails on
			// targets that have no stored value for the field yet.
			update_field( $field['key'], $value, $target );

			// update_field() also returns false on a no-op (identical value), so
			// success is confirmed by re-reading instead of trusting the return.
			$name             = (string) ( $field['name'] ?? $name_or_key );
			$updated[]        = $name;
			$values[ $name ] = $this->normalize_value( function_exists( 'get_field' ) ? get_field( $field['key'], $target, true ) : null );
		}

		// The per-request field index predates these writes — drop it so a
		// later read in the same request sees fresh state.
		unset( $this->field_index_cache[ (string) $target ] );

		return array(
			'target'  => (string) $target,
			'updated' => $updated,
			'skipped' => $skipped,
			'values'  => $values,
		);
	}

	/**
	 * Validates flexible-content rows: each row must be an array carrying an
	 * `acf_fc_layout` that matches one of the field's layout names.
	 *
	 * @since 3.3.0
	 * @param mixed $rows  Incoming value.
	 * @param array $field Flexible content field definition.
	 * @return string|null Error reason, or null when valid.
	 */
	private function validate_flexible_rows( $rows, array $field ): ?string {
		if ( ! is_array( $rows ) ) {
			return 'invalid_flexible_value';
		}
		$layout_names = array();
		foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
			$layout = (array) $layout;
			if ( ! empty( $layout['name'] ) ) {
				$layout_names[] = (string) $layout['name'];
			}
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['acf_fc_layout'] ) ) {
				return 'missing_acf_fc_layout';
			}
			if ( ! in_array( (string) $row['acf_fc_layout'], $layout_names, true ) ) {
				return 'unknown_layout';
			}
		}
		return null;
	}

	// -------------------------------------------------------------------
	// create-acf-field-group
	// -------------------------------------------------------------------

	private function register_create_field_group(): void {
		$this->ability_names[] = 'emcp-tools/create-acf-field-group';
		emcp_tools_register_ability(
			'emcp-tools/create-acf-field-group',
			array(
				'label'               => __( 'Create ACF Field Group', 'emcp-tools' ),
				'description'         => __( 'Creates a new ACF field group with its fields and location rules, persisted to the database (visible under Custom Fields in wp-admin). Each field needs at least label, name, and type; repeater/group fields take sub_fields, flexible content takes layouts (each with name, label, sub_fields). Pro-only field types (repeater, flexible_content, gallery, clone) are rejected on free ACF. Location defaults to post_type == post.', 'emcp-tools' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_create_field_group' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'title'      => array( 'type' => 'string', 'description' => __( 'Field group title.', 'emcp-tools' ) ),
						'fields'     => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => __( 'Field definitions: { label, name, type, required?, instructions?, choices?, default_value?, sub_fields?, layouts?, ... }.', 'emcp-tools' ) ),
						'location'   => array( 'type' => 'array', 'items' => array( 'type' => 'array' ), 'description' => __( 'ACF location rules: OR-array of AND-arrays of { param, operator, value }. Default: [[{"param":"post_type","operator":"==","value":"post"}]].', 'emcp-tools' ) ),
						'position'   => array( 'type' => 'string', 'enum' => array( 'normal', 'acf_after_title', 'side' ) ),
						'active'     => array( 'type' => 'boolean', 'description' => __( 'Default: true.', 'emcp-tools' ) ),
						'menu_order' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'title', 'fields' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array(
					'key'       => array( 'type' => 'string' ),
					'id'        => array( 'type' => 'integer' ),
					'title'     => array( 'type' => 'string' ),
					'fields'    => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					'edit_link' => array( 'type' => 'string' ),
				) ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_create_field_group( $input ) {
		$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		if ( '' === $title ) {
			return new \WP_Error( 'missing_params', __( 'A field group "title" is required.', 'emcp-tools' ) );
		}
		if ( empty( $input['fields'] ) || ! is_array( $input['fields'] ) ) {
			return new \WP_Error( 'missing_params', __( 'A non-empty "fields" array is required.', 'emcp-tools' ) );
		}
		if ( ! function_exists( 'acf_import_field_group' ) ) {
			return new \WP_Error( 'acf_unavailable', __( 'This ACF version does not expose acf_import_field_group().', 'emcp-tools' ) );
		}

		$fields = $this->sanitize_field_defs( $input['fields'] );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$location = $this->sanitize_location( $input['location'] ?? null );
		if ( is_wp_error( $location ) ) {
			return $location;
		}

		$group = array(
			'key'        => uniqid( 'group_' ),
			'title'      => $title,
			'fields'     => $fields,
			'location'   => $location,
			'active'     => ! isset( $input['active'] ) || (bool) $input['active'],
			'menu_order' => absint( $input['menu_order'] ?? 0 ),
		);
		if ( isset( $input['position'] ) && in_array( $input['position'], array( 'normal', 'acf_after_title', 'side' ), true ) ) {
			$group['position'] = $input['position'];
		}

		// acf_import_field_group() persists the group AND its fields to the
		// acf-field-group CPT. acf_add_local_field_group() would be memory-only.
		$saved = acf_import_field_group( $group );
		if ( ! $saved || empty( $saved['ID'] ) ) {
			return new \WP_Error( 'group_create_failed', __( 'ACF did not save the field group.', 'emcp-tools' ) );
		}

		$this->field_index_cache = array();

		$summary = array();
		foreach ( $fields as $f ) {
			$summary[] = array( 'key' => $f['key'], 'name' => $f['name'], 'type' => $f['type'] );
		}
		return array(
			'key'       => (string) $saved['key'],
			'id'        => (int) $saved['ID'],
			'title'     => $title,
			'fields'    => $summary,
			'edit_link' => admin_url( 'post.php?post=' . (int) $saved['ID'] . '&action=edit' ),
		);
	}

	// -------------------------------------------------------------------
	// update-acf-field-group
	// -------------------------------------------------------------------

	private function register_update_field_group(): void {
		$this->ability_names[] = 'emcp-tools/update-acf-field-group';
		emcp_tools_register_ability(
			'emcp-tools/update-acf-field-group',
			array(
				'label'               => __( 'Update ACF Field Group', 'emcp-tools' ),
				'description'         => __( 'Edits an existing database-stored field group: change title/location/active/position, append new fields (add_fields), or adjust settings of existing fields by key (update_fields — label, instructions, required, choices, etc.). Deliberately conservative: fields can never be deleted and a field\'s name, key, or type can never change (renames orphan stored values). Groups registered from code (acf-json/PHP) are refused.', 'emcp-tools' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_update_field_group' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'key'           => array( 'type' => 'string', 'description' => __( 'Field group key (group_xxx).', 'emcp-tools' ) ),
						'title'         => array( 'type' => 'string' ),
						'location'      => array( 'type' => 'array', 'items' => array( 'type' => 'array' ) ),
						'active'        => array( 'type' => 'boolean' ),
						'position'      => array( 'type' => 'string', 'enum' => array( 'normal', 'acf_after_title', 'side' ) ),
						'add_fields'    => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => __( 'New field definitions to append.', 'emcp-tools' ) ),
						'update_fields' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => __( 'Settings changes per existing field: { key (required), label?, instructions?, required?, choices?, ... }. name/key/type cannot change.', 'emcp-tools' ) ),
					),
					'required'   => array( 'key' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array(
					'key'            => array( 'type' => 'string' ),
					'updated'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'fields_added'   => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					'fields_updated' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				) ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_update_field_group( $input ) {
		$key = sanitize_text_field( (string) ( $input['key'] ?? '' ) );
		if ( '' === $key ) {
			return new \WP_Error( 'missing_params', __( 'A field group "key" is required.', 'emcp-tools' ) );
		}
		$group = acf_get_field_group( $key );
		if ( ! $group ) {
			return new \WP_Error( 'group_not_found', __( 'Field group not found.', 'emcp-tools' ) );
		}
		if ( ! empty( $group['local'] ) ) {
			// Updating a local (acf-json / PHP-registered) group would create a DB
			// copy that shadows the code-registered definition.
			return new \WP_Error( 'acf_local_group', __( 'This field group is registered from code (acf-json/PHP) and cannot be edited via MCP.', 'emcp-tools' ) );
		}
		if ( empty( $group['ID'] ) ) {
			return new \WP_Error( 'acf_local_group', __( 'This field group is not stored in the database and cannot be edited via MCP.', 'emcp-tools' ) );
		}

		$updated = array();

		if ( isset( $input['title'] ) && '' !== trim( (string) $input['title'] ) ) {
			$group['title'] = sanitize_text_field( (string) $input['title'] );
			$updated[]      = 'title';
		}
		if ( isset( $input['location'] ) ) {
			$location = $this->sanitize_location( $input['location'] );
			if ( is_wp_error( $location ) ) {
				return $location;
			}
			$group['location'] = $location;
			$updated[]         = 'location';
		}
		if ( isset( $input['active'] ) ) {
			$group['active'] = (bool) $input['active'];
			$updated[]       = 'active';
		}
		if ( isset( $input['position'] ) && in_array( $input['position'], array( 'normal', 'acf_after_title', 'side' ), true ) ) {
			$group['position'] = $input['position'];
			$updated[]         = 'position';
		}
		if ( $updated ) {
			acf_update_field_group( $group );
		}

		$fields_added = array();
		if ( ! empty( $input['add_fields'] ) && is_array( $input['add_fields'] ) ) {
			if ( ! function_exists( 'acf_update_field' ) ) {
				return new \WP_Error( 'acf_unavailable', __( 'This ACF version does not expose acf_update_field().', 'emcp-tools' ) );
			}
			$defs = $this->sanitize_field_defs( $input['add_fields'] );
			if ( is_wp_error( $defs ) ) {
				return $defs;
			}
			foreach ( $defs as $def ) {
				$def['parent'] = (int) $group['ID'];
				$saved         = acf_update_field( $def );
				if ( $saved && ! empty( $saved['key'] ) ) {
					$fields_added[] = array( 'key' => (string) $saved['key'], 'name' => (string) ( $saved['name'] ?? '' ), 'type' => (string) ( $saved['type'] ?? '' ) );
				}
			}
		}

		$fields_updated = array();
		if ( ! empty( $input['update_fields'] ) && is_array( $input['update_fields'] ) ) {
			if ( ! function_exists( 'acf_get_field' ) || ! function_exists( 'acf_update_field' ) ) {
				return new \WP_Error( 'acf_unavailable', __( 'This ACF version does not expose the field update API.', 'emcp-tools' ) );
			}
			foreach ( $input['update_fields'] as $change ) {
				$change    = (array) $change;
				$field_key = sanitize_text_field( (string) ( $change['key'] ?? '' ) );
				if ( '' === $field_key ) {
					return new \WP_Error( 'missing_params', __( 'Each update_fields entry needs the field "key".', 'emcp-tools' ) );
				}
				foreach ( array( 'name', 'type' ) as $immutable ) {
					if ( isset( $change[ $immutable ] ) ) {
						return new \WP_Error(
							'immutable_field_setting',
							sprintf( /* translators: 1: setting, 2: field key */ __( 'The "%1$s" of an existing field cannot change via MCP (field %2$s). Add a new field instead.', 'emcp-tools' ), $immutable, $field_key )
						);
					}
				}
				$field = acf_get_field( $field_key );
				if ( ! $field ) {
					return new \WP_Error( 'field_not_found', sprintf( /* translators: %s: field key */ __( 'Field "%s" not found.', 'emcp-tools' ), $field_key ) );
				}
				foreach ( $this->mutable_field_settings() as $setting ) {
					if ( array_key_exists( $setting, $change ) ) {
						$field[ $setting ] = $this->sanitize_field_setting( $setting, $change[ $setting ] );
					}
				}
				acf_update_field( $field );
				$fields_updated[] = $field_key;
			}
		}

		$this->field_index_cache = array();

		return array(
			'key'            => (string) $group['key'],
			'updated'        => $updated,
			'fields_added'   => $fields_added,
			'fields_updated' => $fields_updated,
		);
	}

	// -------------------------------------------------------------------
	// Target + field resolution
	// -------------------------------------------------------------------

	/**
	 * Resolves the tool target: exactly one of post_id / options_page.
	 *
	 * @since 3.3.0
	 * @param array $input Tool input.
	 * @return int|string|\WP_Error Post ID, ACF options post_id string, or error.
	 */
	private function resolve_target( $input ) {
		$post_id = absint( $input['post_id'] ?? 0 );
		$options = isset( $input['options_page'] ) ? sanitize_text_field( (string) $input['options_page'] ) : '';

		if ( $post_id && '' !== $options ) {
			return new \WP_Error( 'invalid_target', __( 'Pass either "post_id" or "options_page", not both.', 'emcp-tools' ) );
		}
		if ( $post_id ) {
			if ( ! get_post( $post_id ) ) {
				return new \WP_Error( 'post_not_found', __( 'Post not found.', 'emcp-tools' ) );
			}
			return $post_id;
		}
		if ( '' !== $options ) {
			if ( ! self::is_pro() ) {
				return new \WP_Error( 'acf_pro_required', __( 'Options pages require ACF PRO.', 'emcp-tools' ) );
			}
			return 'option' === $options ? 'options' : $options;
		}
		return new \WP_Error( 'invalid_target', __( 'A target is required: pass "post_id" or "options_page".', 'emcp-tools' ) );
	}

	/**
	 * Builds (and caches per request) the field index for a target: every
	 * top-level field of every field group that applies to it.
	 *
	 * For post targets the applicable groups come from ACF's own location
	 * matching; for options targets ACF has no reverse lookup, so the index
	 * falls back to the fields that already carry a stored value.
	 *
	 * @since 3.3.0
	 * @param int|string $target
	 * @return array{fields: array<int,array>, by_name: array<string,array>, by_key: array<string,array>}
	 */
	private function field_index( $target ): array {
		$cache_key = (string) $target;
		if ( isset( $this->field_index_cache[ $cache_key ] ) ) {
			return $this->field_index_cache[ $cache_key ];
		}

		$fields = array();
		if ( is_int( $target ) ) {
			$groups = acf_get_field_groups( array( 'post_id' => $target ) );
			foreach ( (array) $groups as $group ) {
				foreach ( (array) acf_get_fields( $group ) as $field ) {
					$fields[] = (array) $field;
				}
			}
		} else {
			// ACF cannot reverse-match an options target to its groups, so take
			// every group with an options_page location rule (covers first writes
			// on fields with no stored value yet) plus the stored field objects.
			foreach ( (array) acf_get_field_groups() as $group ) {
				if ( $this->group_targets_options( (array) $group ) ) {
					foreach ( (array) acf_get_fields( $group ) as $field ) {
						$fields[] = (array) $field;
					}
				}
			}
			if ( function_exists( 'get_field_objects' ) ) {
				$objects = get_field_objects( $target, false );
				foreach ( (array) $objects as $field ) {
					$fields[] = (array) $field;
				}
			}
		}

		$index = array( 'fields' => array(), 'by_name' => array(), 'by_key' => array() );
		foreach ( $fields as $field ) {
			if ( empty( $field['key'] ) || empty( $field['name'] ) || isset( $index['by_key'][ $field['key'] ] ) ) {
				continue;
			}
			$index['fields'][]                    = $field;
			$index['by_name'][ $field['name'] ]   = $field;
			$index['by_key'][ $field['key'] ]     = $field;
		}

		$this->field_index_cache[ $cache_key ] = $index;
		return $index;
	}

	/**
	 * Whether a field group has any options_page location rule.
	 *
	 * @since 3.3.0
	 * @param array $group Field group array.
	 * @return bool
	 */
	private function group_targets_options( array $group ): bool {
		foreach ( (array) ( $group['location'] ?? array() ) as $rule_group ) {
			foreach ( (array) $rule_group as $rule ) {
				if ( 'options_page' === ( $rule['param'] ?? '' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Resolves a field name or key to its full definition for a target.
	 *
	 * @since 3.3.0
	 * @param string     $name_or_key Field name or field_xxx key.
	 * @param int|string $target
	 * @return array|null
	 */
	private function resolve_field( string $name_or_key, $target ): ?array {
		if ( 0 === strpos( $name_or_key, 'field_' ) && function_exists( 'acf_get_field' ) ) {
			$field = acf_get_field( $name_or_key );
			if ( $field ) {
				return (array) $field;
			}
		}
		$index = $this->field_index( $target );
		if ( isset( $index['by_name'][ $name_or_key ] ) ) {
			return $index['by_name'][ $name_or_key ];
		}
		if ( isset( $index['by_key'][ $name_or_key ] ) ) {
			return $index['by_key'][ $name_or_key ];
		}
		// Last resort (covers options targets with no stored value yet): a
		// stored field object lookup by name via ACF itself.
		if ( function_exists( 'get_field_object' ) ) {
			$field = get_field_object( $name_or_key, $target, false, false );
			if ( is_array( $field ) && ! empty( $field['key'] ) && 0 === strpos( (string) $field['key'], 'field_' ) ) {
				return $field;
			}
		}
		return null;
	}

	// -------------------------------------------------------------------
	// Field definition sanitization (create / add fields)
	// -------------------------------------------------------------------

	/**
	 * Field settings an agent may set on create and change on update.
	 *
	 * @since 3.3.0
	 * @return string[]
	 */
	private function mutable_field_settings(): array {
		return array(
			'label', 'instructions', 'required', 'choices', 'default_value',
			'placeholder', 'min', 'max', 'step', 'return_format', 'allow_null',
			'multiple', 'ui', 'post_type', 'taxonomy', 'mime_types', 'layout',
			'button_label', 'preview_size', 'display_format', 'new_lines',
		);
	}

	/**
	 * Sanitizes one field setting value by shape (scalar vs array passthrough).
	 *
	 * @since 3.3.0
	 * @param string $setting
	 * @param mixed  $value
	 * @return mixed
	 */
	private function sanitize_field_setting( string $setting, $value ) {
		if ( is_array( $value ) ) {
			// choices / post_type / taxonomy etc. — sanitize leaves only.
			array_walk_recursive(
				$value,
				static function ( &$leaf ) {
					if ( is_string( $leaf ) ) {
						$leaf = sanitize_text_field( $leaf );
					}
				}
			);
			return $value;
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		return sanitize_text_field( (string) $value );
	}

	/**
	 * Validates + sanitizes an array of incoming field definitions, assigning
	 * fresh field_ keys recursively (sub_fields and flexible layouts included).
	 *
	 * @since 3.3.0
	 * @param array $defs  Raw definitions from the tool input.
	 * @param int   $depth Recursion depth guard.
	 * @return array|\WP_Error
	 */
	private function sanitize_field_defs( array $defs, int $depth = 0 ) {
		if ( $depth > self::MAX_DEPTH ) {
			return new \WP_Error( 'invalid_field', __( 'Field definitions are nested too deeply.', 'emcp-tools' ) );
		}
		$out = array();
		foreach ( $defs as $def ) {
			$def   = (array) $def;
			$name  = sanitize_key( (string) ( $def['name'] ?? '' ) );
			$type  = sanitize_key( (string) ( $def['type'] ?? '' ) );
			$label = sanitize_text_field( (string) ( $def['label'] ?? '' ) );
			if ( '' === $name || '' === $type || '' === $label ) {
				return new \WP_Error( 'invalid_field', __( 'Every field definition needs "label", "name", and "type".', 'emcp-tools' ) );
			}
			if ( in_array( $type, self::PRO_FIELD_TYPES, true ) && ! self::is_pro() ) {
				return new \WP_Error( 'acf_pro_required', sprintf( /* translators: %s: field type */ __( 'The "%s" field type requires ACF PRO.', 'emcp-tools' ), $type ) );
			}
			if ( function_exists( 'acf_get_field_type' ) && ! acf_get_field_type( $type ) ) {
				return new \WP_Error( 'invalid_field', sprintf( /* translators: %s: field type */ __( 'Unknown ACF field type "%s".', 'emcp-tools' ), $type ) );
			}

			$field = array(
				'key'      => uniqid( 'field_' ),
				'label'    => $label,
				'name'     => $name,
				'type'     => $type,
				'required' => ! empty( $def['required'] ) ? 1 : 0,
			);
			foreach ( $this->mutable_field_settings() as $setting ) {
				if ( array_key_exists( $setting, $def ) && ! in_array( $setting, array( 'label', 'required' ), true ) ) {
					$field[ $setting ] = $this->sanitize_field_setting( $setting, $def[ $setting ] );
				}
			}

			if ( ! empty( $def['sub_fields'] ) && is_array( $def['sub_fields'] ) ) {
				$subs = $this->sanitize_field_defs( $def['sub_fields'], $depth + 1 );
				if ( is_wp_error( $subs ) ) {
					return $subs;
				}
				$field['sub_fields'] = $subs;
			}
			if ( ! empty( $def['layouts'] ) && is_array( $def['layouts'] ) ) {
				$layouts = array();
				foreach ( $def['layouts'] as $layout ) {
					$layout      = (array) $layout;
					$layout_name = sanitize_key( (string) ( $layout['name'] ?? '' ) );
					if ( '' === $layout_name ) {
						return new \WP_Error( 'invalid_field', __( 'Every flexible content layout needs a "name".', 'emcp-tools' ) );
					}
					$layout_row = array(
						'key'     => uniqid( 'layout_' ),
						'name'    => $layout_name,
						'label'   => sanitize_text_field( (string) ( $layout['label'] ?? $layout_name ) ),
						'display' => 'block',
					);
					if ( ! empty( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
						$subs = $this->sanitize_field_defs( $layout['sub_fields'], $depth + 1 );
						if ( is_wp_error( $subs ) ) {
							return $subs;
						}
						$layout_row['sub_fields'] = $subs;
					}
					$layouts[ $layout_row['key'] ] = $layout_row;
				}
				$field['layouts'] = $layouts;
			}

			$out[] = $field;
		}
		return $out;
	}

	/**
	 * Validates ACF location rules: OR-array of AND-arrays of {param, operator, value}.
	 *
	 * @since 3.3.0
	 * @param mixed $location Raw location input (null = default post_type==post).
	 * @return array|\WP_Error
	 */
	private function sanitize_location( $location ) {
		if ( null === $location || array() === $location ) {
			return array(
				array(
					array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ),
				),
			);
		}
		if ( ! is_array( $location ) ) {
			return new \WP_Error( 'invalid_location', __( 'Location must be an array of rule groups.', 'emcp-tools' ) );
		}
		$out = array();
		foreach ( $location as $rule_group ) {
			if ( ! is_array( $rule_group ) ) {
				return new \WP_Error( 'invalid_location', __( 'Each location rule group must be an array of rules.', 'emcp-tools' ) );
			}
			$group_out = array();
			foreach ( $rule_group as $rule ) {
				$rule = (array) $rule;
				if ( empty( $rule['param'] ) || empty( $rule['operator'] ) || ! isset( $rule['value'] ) ) {
					return new \WP_Error( 'invalid_location', __( 'Each location rule needs "param", "operator", and "value".', 'emcp-tools' ) );
				}
				if ( ! in_array( (string) $rule['operator'], array( '==', '!=' ), true ) ) {
					return new \WP_Error( 'invalid_location', __( 'Location rule operator must be "==" or "!=".', 'emcp-tools' ) );
				}
				$group_out[] = array(
					'param'    => sanitize_key( (string) $rule['param'] ),
					'operator' => (string) $rule['operator'],
					'value'    => sanitize_text_field( (string) $rule['value'] ),
				);
			}
			if ( $group_out ) {
				$out[] = $group_out;
			}
		}
		if ( array() === $out ) {
			return new \WP_Error( 'invalid_location', __( 'At least one location rule is required.', 'emcp-tools' ) );
		}
		return $out;
	}

	// -------------------------------------------------------------------
	// Output value normalization
	// -------------------------------------------------------------------

	/**
	 * Makes an ACF value JSON-friendly and compact regardless of each field's
	 * return_format: WP_Post/WP_User objects and attachment arrays become small
	 * summaries; nesting is depth-capped.
	 *
	 * @since 3.3.0
	 * @param mixed $value
	 * @param int   $depth Recursion depth guard.
	 * @return mixed
	 */
	private function normalize_value( $value, int $depth = 0 ) {
		if ( $depth >= self::MAX_DEPTH ) {
			return is_scalar( $value ) || null === $value ? $value : '[max depth reached]';
		}
		if ( $value instanceof \WP_Post ) {
			return array(
				'id'        => (int) $value->ID,
				'title'     => (string) $value->post_title,
				'post_type' => (string) $value->post_type,
				'url'       => function_exists( 'get_permalink' ) ? (string) get_permalink( $value ) : '',
			);
		}
		if ( class_exists( '\WP_User' ) && $value instanceof \WP_User ) {
			return array(
				'id'           => (int) $value->ID,
				'display_name' => (string) $value->display_name,
			);
		}
		if ( class_exists( '\WP_Term' ) && $value instanceof \WP_Term ) {
			return array(
				'id'       => (int) $value->term_id,
				'name'     => (string) $value->name,
				'taxonomy' => (string) $value->taxonomy,
			);
		}
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			// ACF image/file arrays (return_format=array) are huge; keep a summary.
			if ( isset( $value['ID'], $value['url'], $value['mime_type'] ) ) {
				return array(
					'id'   => (int) $value['ID'],
					'url'  => (string) $value['url'],
					'alt'  => (string) ( $value['alt'] ?? '' ),
					'mime' => (string) $value['mime_type'],
				);
			}
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = $this->normalize_value( $v, $depth + 1 );
			}
			return $out;
		}
		return $value;
	}
}
