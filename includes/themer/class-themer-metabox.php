<?php
/**
 * Condition metabox for the Themer template edit screen.
 *
 * Renders a step-wise cascading condition builder (Relation → Group → Sub-type →
 * [Pro] Object) driven by a type-aware schema, mounted by themer-conditions.js and
 * serialized to a hidden JSON field. Free shows Include + broad leaves; the Pro
 * overlay adds the Exclude relation, specific-object search, and priority via the
 * schema filter. Search/404 templates need no conditions (a note is shown instead).
 * Saving parses the hidden JSON, validates selectors against the registered set,
 * and writes the conditions meta (the index rebuilds on save_post).
 *
 * @package EMCP_Tools
 * @since   3.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 3.2.0
 */
class EMCP_Tools_Themer_Metabox {

	const NONCE = 'emcp_themer_conditions_nonce';

	/** Wire admin hooks. */
	public function init(): void {
		add_action( 'add_meta_boxes_' . EMCP_Tools_Themer_CPT::POST_TYPE, array( $this, 'add' ) );
		add_action( 'save_post_' . EMCP_Tools_Themer_CPT::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/** Register the metabox. */
	public function add(): void {
		add_meta_box(
			'emcp-themer-conditions',
			__( 'EMCP Themer — Display Conditions', 'emcp-tools' ),
			array( $this, 'render' ),
			EMCP_Tools_Themer_CPT::POST_TYPE,
			'normal',
			'high'
		);
	}

	/** Whether the Pro condition layer is active (a granular selector is registered). */
	private function is_pro(): bool {
		return in_array( 'post', (array) apply_filters( 'emcp_themer_selectors', array() ), true );
	}

	/** The selector keys valid for saving (free broad set + any Pro-registered). */
	private function valid_selectors(): array {
		return (array) apply_filters(
			'emcp_themer_selectors',
			array( 'entire-site', 'all-singular', 'all-archives', 'front-page', 'post-type', 'post-type-archive', 'tax-archive' )
		);
	}

	/**
	 * Enqueue the builder assets on the template edit screen only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ): void {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || EMCP_Tools_Themer_CPT::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( 'emcp-themer-conditions', EMCP_TOOLS_URL . 'assets/css/themer-conditions.css', array(), EMCP_TOOLS_VERSION );
		wp_enqueue_script( 'emcp-themer-conditions', EMCP_TOOLS_URL . 'assets/js/themer-conditions.js', array( 'jquery' ), EMCP_TOOLS_VERSION, true );

		$schemas = array();
		foreach ( EMCP_Tools_Themer_CPT::TYPES as $t ) {
			if ( EMCP_Tools_Themer_Condition_Schema::type_uses_builder( $t ) ) {
				$schemas[ $t ] = EMCP_Tools_Themer_Condition_Schema::for_type( $t );
			}
		}

		wp_localize_script(
			'emcp-themer-conditions',
			'emcpThemerCond',
			array(
				'schemasByType' => $schemas,
				'isPro'         => $this->is_pro(),
				'ajax'          => array(
					'url'    => admin_url( 'admin-ajax.php' ),
					'action' => 'emcp_themer_object_search',
					'nonce'  => wp_create_nonce( 'emcp_themer_object_search' ),
				),
				'i18n'          => array(
					'include'      => __( 'Include', 'emcp-tools' ),
					'exclude'      => __( 'Exclude', 'emcp-tools' ),
					'addCondition' => __( 'Add condition', 'emcp-tools' ),
					'chooseGroup'  => __( 'Choose…', 'emcp-tools' ),
					'all'          => __( 'All', 'emcp-tools' ),
					'searchType'   => __( 'Type 1+ characters…', 'emcp-tools' ),
					'noBuilder'    => __( 'This template type applies automatically — no display conditions needed.', 'emcp-tools' ),
					'proHint'      => __( 'Upgrade to EMCP Pro for Exclude rules, per-page / per-category / per-author targeting, and priority.', 'emcp-tools' ),
					'remove'       => __( 'Remove', 'emcp-tools' ),
				),
			)
		);
	}

	/**
	 * Render the metabox: type select + the condition-builder mount + hidden JSON.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render( $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE );
		$type = (string) get_post_meta( $post->ID, '_emcp_themer_type', true );
		if ( '' === $type ) {
			// New template: seed from ?emcp_themer_type= if present, otherwise leave
			// UNSET so the user must consciously choose. Do NOT default to a real
			// type ('header') — that silently mistyped templates (e.g. a template
			// named "Single Page" saved as a header, which then renders in the
			// header slot instead of the body).
			$req  = isset( $_GET['emcp_themer_type'] ) ? sanitize_key( wp_unslash( $_GET['emcp_themer_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$type = in_array( $req, EMCP_Tools_Themer_CPT::TYPES, true ) ? $req : '';
		}
		$cond = get_post_meta( $post->ID, '_emcp_themer_conditions', true );
		$cond = is_array( $cond ) ? $cond : array( 'include' => array(), 'exclude' => array(), 'priority' => 0 );

		$type_labels = array(
			'header'  => __( 'Header', 'emcp-tools' ),
			'footer'  => __( 'Footer', 'emcp-tools' ),
			'single'  => __( 'Single (post/page)', 'emcp-tools' ),
			'archive' => __( 'Archive', 'emcp-tools' ),
			'search'  => __( 'Search results', 'emcp-tools' ),
			'404'     => __( '404 (not found)', 'emcp-tools' ),
		);

		echo '<p><label for="emcp-themer-type"><strong>' . esc_html__( 'Template type', 'emcp-tools' ) . '</strong> <span style="color:#d63638">*</span></label><br>';
		echo '<select id="emcp-themer-type" name="emcp_themer_type" class="emcp-themer-type-select" required>';
		printf( '<option value="" %s>%s</option>', selected( $type, '', false ), esc_html__( '— Choose a template type —', 'emcp-tools' ) );
		foreach ( EMCP_Tools_Themer_CPT::TYPES as $t ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $t ), selected( $type, $t, false ), esc_html( $type_labels[ $t ] ?? ucfirst( $t ) ) );
		}
		echo '</select>';
		echo '<br><span class="description">' . esc_html__( 'What this template replaces on the front end. A Single template renders in the content area (keeping your theme header/footer); a Header/Footer template replaces the theme\'s header/footer.', 'emcp-tools' ) . '</span></p>';

		// Mount point for the JS cascading builder + the serialized value it writes.
		echo '<div id="emcp-themer-conditions-app" class="emcp-themer-conditions"></div>';
		printf(
			'<input type="hidden" id="emcp-themer-conditions-json" name="emcp_themer_conditions_json" value="%s">',
			esc_attr( (string) wp_json_encode( $cond ) )
		);

		// Optional PHP-template override (only when the feature is enabled).
		if ( class_exists( 'EMCP_Tools_Themer_PHP' ) && EMCP_Tools_Themer_PHP::enabled() ) {
			$attached = (int) get_post_meta( $post->ID, '_emcp_themer_php_template', true );
			echo '<hr><p><label for="emcp-themer-php"><strong>' . esc_html__( 'Render with PHP template', 'emcp-tools' ) . '</strong></label><br>';
			if ( '' === $type ) {
				echo '<span class="description">' . esc_html__( 'Choose a template type first to list matching PHP templates.', 'emcp-tools' ) . '</span></p>';
			} else {
				echo '<select id="emcp-themer-php" name="emcp_themer_php_template">';
				printf( '<option value="0">%s</option>', esc_html__( '— None (use builder content) —', 'emcp-tools' ) );
				foreach ( self::eligible_templates( $type ) as $tpl ) {
					printf(
						'<option value="%1$d" %2$s>%3$s</option>',
						(int) $tpl['template_id'],
						selected( $attached, (int) $tpl['template_id'], false ),
						esc_html( $tpl['title'] . ' (' . $tpl['type'] . ')' )
					);
				}
				echo '</select>';
				echo '<br><span class="description">' . esc_html__( 'If selected, this PHP template renders this region instead of the builder content.', 'emcp-tools' ) . '</span></p>';
			}
		}

		if ( ! $this->is_pro() ) {
			echo '<p class="description emcp-themer-pro-hint">' . esc_html__( 'Free templates support Include rules with broad targeting. Upgrade to EMCP Pro for Exclude rules, per-page / per-category / per-author targeting, priority, and unlimited templates per type.', 'emcp-tools' ) . '</p>';
		}
	}

	/**
	 * Persist type + conditions from the hidden JSON field.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post.
	 */
	public function save( $post_id, $post ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['emcp_themer_type'] ) ) {
			$type = sanitize_text_field( wp_unslash( $_POST['emcp_themer_type'] ) );
			if ( in_array( $type, EMCP_Tools_Themer_CPT::TYPES, true ) ) {
				update_post_meta( $post_id, '_emcp_themer_type', $type );
			}
		}

		// PHP-template attachment (feature-gated; type-enforced server-side).
		if ( class_exists( 'EMCP_Tools_Themer_PHP' ) && EMCP_Tools_Themer_PHP::enabled() && isset( $_POST['emcp_themer_php_template'] ) ) {
			$prev   = (int) get_post_meta( $post_id, '_emcp_themer_php_template', true );
			$chosen = absint( wp_unslash( $_POST['emcp_themer_php_template'] ) );
			$ptype  = (string) get_post_meta( $post_id, '_emcp_themer_type', true );
			if ( $chosen > 0 ) {
				$ok = self::validate_attachment( $chosen, $ptype );
				if ( is_wp_error( $ok ) ) {
					set_transient( 'emcp_themer_php_notice_' . get_current_user_id(), $ok->get_error_message(), 60 );
					$chosen = $prev; // keep the previous state on rejection
				}
			}
			self::apply_attachment( $post_id, $chosen, $prev );
		}

		if ( ! isset( $_POST['emcp_themer_conditions_json'] ) ) {
			return;
		}
		$raw     = sanitize_textarea_field( wp_unslash( $_POST['emcp_themer_conditions_json'] ) );
		$decoded = json_decode( $raw, true );
		update_post_meta( $post_id, '_emcp_themer_conditions', $this->sanitize_conditions( is_array( $decoded ) ? $decoded : array() ) );
	}

	/**
	 * Sanitize + validate a decoded conditions payload. Drops rules with unknown
	 * (or Pro-only, when unlicensed) selectors; strips Exclude/priority on free.
	 *
	 * @param array $cond Decoded { include, exclude, priority }.
	 * @return array
	 */
	private function sanitize_conditions( array $cond ): array {
		$valid = $this->valid_selectors();
		$pro   = $this->is_pro();

		$clean_rules = static function ( $rules ) use ( $valid ) {
			$out = array();
			foreach ( (array) $rules as $rule ) {
				$object = is_array( $rule ) ? (string) ( $rule['object'] ?? '' ) : '';
				if ( '' === $object ) {
					continue;
				}
				$key = false === strpos( $object, ':' ) ? $object : substr( $object, 0, strpos( $object, ':' ) );
				if ( in_array( $key, $valid, true ) ) {
					$out[] = array( 'object' => sanitize_text_field( $object ) );
				}
			}
			return $out;
		};

		$include  = $clean_rules( $cond['include'] ?? array() );
		$exclude  = $pro ? $clean_rules( $cond['exclude'] ?? array() ) : array();
		$priority = ( $pro && isset( $cond['priority'] ) ) ? (int) $cond['priority'] : 0;

		return array( 'include' => $include, 'exclude' => $exclude, 'priority' => $priority );
	}

	// -------------------------------------------------------------------------
	// PHP-template attachment (feature-gated; the human selection is the gate)
	// -------------------------------------------------------------------------

	/**
	 * Templates attachable to a Themer post of $themer_type (matching type + any).
	 *
	 * @param string $themer_type Themer template type.
	 * @return array<int,array>
	 */
	public static function eligible_templates( string $themer_type ): array {
		if ( ! class_exists( 'EMCP_Tools_Themer_PHP_Store' ) ) {
			return array();
		}
		$out = array();
		foreach ( EMCP_Tools_Themer_PHP_Store::list_templates() as $tpl ) {
			if ( $tpl['type'] === $themer_type || 'any' === $tpl['type'] ) {
				$out[] = $tpl;
			}
		}
		return $out;
	}

	/**
	 * Validate an attach: template exists + type matches (or any).
	 *
	 * @param int    $php_id      PHP-template id.
	 * @param string $themer_type Themer template type.
	 * @return true|WP_Error
	 */
	public static function validate_attachment( int $php_id, string $themer_type ) {
		$summary = EMCP_Tools_Themer_PHP_Store::summary( $php_id );
		if ( is_wp_error( $summary ) ) {
			return $summary;
		}
		if ( $summary['type'] !== $themer_type && 'any' !== $summary['type'] ) {
			return new WP_Error( 'type_mismatch', __( 'That PHP template is for a different template type.', 'emcp-tools' ) );
		}
		return true;
	}

	/**
	 * Persist/clear the attachment meta, then reconcile the compiled file for both ids.
	 *
	 * @param int $themer_id   Themer template id.
	 * @param int $new_php_id  Newly-attached PHP-template id (0 = detach).
	 * @param int $prev_php_id Previously-attached PHP-template id (0 = none).
	 */
	public static function apply_attachment( int $themer_id, int $new_php_id, int $prev_php_id ): void {
		if ( $new_php_id > 0 ) {
			update_post_meta( $themer_id, '_emcp_themer_php_template', $new_php_id );
		} else {
			delete_post_meta( $themer_id, '_emcp_themer_php_template' );
		}
		// Reconcile after the meta write so reference_count() reflects the new state.
		if ( $prev_php_id > 0 && $prev_php_id !== $new_php_id ) {
			EMCP_Tools_Themer_PHP_Store::sync_reference( $prev_php_id );
		}
		if ( $new_php_id > 0 ) {
			EMCP_Tools_Themer_PHP_Store::sync_reference( $new_php_id );
		}
	}
}
