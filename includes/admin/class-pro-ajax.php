<?php
/**
 * Admin-ajax handlers for the Pro library admin pages (Prompts, Templates,
 * Brand Kits) — the "Sync Library", apply, import and restore actions.
 *
 * These run only in the admin context and only when Freemius is present; they
 * are wired by EMCP_Tools_Pro_Ajax::register() from the bootstrap.
 *
 * @package EMCP_Tools
 * @since   2.1.0 (extracted from the bootstrap file)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro library admin-ajax handlers.
 *
 * @since 2.1.0
 */
class EMCP_Tools_Pro_Ajax {

	/**
	 * Registers all wp_ajax_* handlers for the Pro library pages.
	 *
	 * @since 2.1.0
	 */
	public static function register(): void {
		add_action( 'wp_ajax_emcp_tools_sync_pro_prompts', array( __CLASS__, 'sync_prompts' ) );

		add_action( 'wp_ajax_emcp_tools_sync_pro_templates', array( __CLASS__, 'sync_templates' ) );
		add_action( 'wp_ajax_emcp_tools_apply_pro_template', array( __CLASS__, 'apply_template' ) );
		add_action( 'wp_ajax_emcp_tools_import_pro_template', array( __CLASS__, 'import_template' ) );

		add_action( 'wp_ajax_emcp_tools_sync_pro_brand_kits', array( __CLASS__, 'sync_brand_kits' ) );
		add_action( 'wp_ajax_emcp_tools_apply_pro_brand_kit', array( __CLASS__, 'apply_brand_kit' ) );
		add_action( 'wp_ajax_emcp_tools_restore_pro_brand_kit', array( __CLASS__, 'restore_brand_kit' ) );
	}

	/**
	 * "Sync Library" button on the Pro Prompts page.
	 *
	 * @since 1.7.0
	 */
	public static function sync_prompts(): void {
		check_ajax_referer( 'emcp_tools_sync_pro_prompts', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to sync prompts.', 'emcp-tools' ) ), 403 );
		}

		$bundle = EMCP_Tools_Pro_Prompts::get_bundle( true );
		if ( is_wp_error( $bundle ) ) {
			wp_send_json_error( array( 'message' => $bundle->get_error_message() ), 400 );
		}

		$total = 0;
		foreach ( $bundle['categories'] as $category ) {
			$total += isset( $category['prompts'] ) && is_array( $category['prompts'] ) ? count( $category['prompts'] ) : 0;
		}

		wp_send_json_success(
			array(
				'message'    => sprintf(
					/* translators: %1$d: total prompts, %2$d: total categories */
					__( 'Synced %1$d prompts across %2$d categories.', 'emcp-tools' ),
					$total,
					count( $bundle['categories'] )
				),
				'fetched_at' => $bundle['fetched_at'],
			)
		);
	}

	/**
	 * "Sync Library" button on the Pro Templates page.
	 *
	 * @since 1.7.1
	 */
	public static function sync_templates(): void {
		check_ajax_referer( 'emcp_tools_sync_pro_templates', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to sync templates.', 'emcp-tools' ) ), 403 );
		}

		$bundle = EMCP_Tools_Pro_Templates::get_bundle( true );
		if ( is_wp_error( $bundle ) ) {
			wp_send_json_error( array( 'message' => $bundle->get_error_message() ), 400 );
		}

		$total = 0;
		foreach ( $bundle['categories'] as $category ) {
			$total += isset( $category['templates'] ) && is_array( $category['templates'] ) ? count( $category['templates'] ) : 0;
		}

		wp_send_json_success(
			array(
				'message'    => sprintf(
					/* translators: %1$d: total templates, %2$d: total categories */
					__( 'Synced %1$d templates across %2$d categories.', 'emcp-tools' ),
					$total,
					count( $bundle['categories'] )
				),
				'fetched_at' => $bundle['fetched_at'],
			)
		);
	}

	/**
	 * Applies a template to a new (or existing) page.
	 *
	 * @since 1.7.1
	 */
	public static function apply_template(): void {
		check_ajax_referer( 'emcp_tools_apply_pro_template', 'nonce' );

		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create pages.', 'emcp-tools' ) ), 403 );
		}

		$category_slug  = isset( $_POST['category_slug'] ) ? sanitize_key( wp_unslash( $_POST['category_slug'] ) ) : '';
		$template_slug  = isset( $_POST['template_slug'] ) ? sanitize_key( wp_unslash( $_POST['template_slug'] ) ) : '';
		$target_post_id = isset( $_POST['target_post_id'] ) ? absint( wp_unslash( $_POST['target_post_id'] ) ) : 0;

		if ( '' === $category_slug || '' === $template_slug ) {
			wp_send_json_error( array( 'message' => __( 'Missing category or template slug.', 'emcp-tools' ) ), 400 );
		}

		$result = EMCP_Tools_Pro_Templates::apply_template( $category_slug, $template_slug, $target_post_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Imports a template into Elementor's Saved Templates library.
	 *
	 * @since 1.7.1
	 */
	public static function import_template(): void {
		check_ajax_referer( 'emcp_tools_import_pro_template', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import templates.', 'emcp-tools' ) ), 403 );
		}

		$category_slug = isset( $_POST['category_slug'] ) ? sanitize_key( wp_unslash( $_POST['category_slug'] ) ) : '';
		$template_slug = isset( $_POST['template_slug'] ) ? sanitize_key( wp_unslash( $_POST['template_slug'] ) ) : '';

		if ( '' === $category_slug || '' === $template_slug ) {
			wp_send_json_error( array( 'message' => __( 'Missing category or template slug.', 'emcp-tools' ) ), 400 );
		}

		$result = EMCP_Tools_Pro_Templates::import_to_library( $category_slug, $template_slug );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * "Sync Library" button on the Brand Kits page.
	 *
	 * @since 1.8.0
	 */
	public static function sync_brand_kits(): void {
		check_ajax_referer( 'emcp_tools_sync_pro_brand_kits', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) || ! EMCP_Tools_Pro_Brand_Kits::user_has_access() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to sync brand kits.', 'emcp-tools' ) ), 403 );
		}

		$bundle = EMCP_Tools_Pro_Brand_Kits::get_bundle( true );
		if ( is_wp_error( $bundle ) ) {
			wp_send_json_error( array( 'message' => $bundle->get_error_message() ), 400 );
		}

		$total = 0;
		foreach ( $bundle['categories'] as $category ) {
			$total += isset( $category['kits'] ) && is_array( $category['kits'] ) ? count( $category['kits'] ) : 0;
		}

		wp_send_json_success(
			array(
				'message'    => sprintf(
					/* translators: %1$d: total kits, %2$d: total categories */
					__( 'Synced %1$d brand kits across %2$d categories.', 'emcp-tools' ),
					$total,
					count( $bundle['categories'] )
				),
				'fetched_at' => $bundle['fetched_at'],
			)
		);
	}

	/**
	 * Applies a brand kit from the admin page.
	 *
	 * @since 1.8.0
	 */
	public static function apply_brand_kit(): void {
		check_ajax_referer( 'emcp_tools_apply_pro_brand_kit', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to apply brand kits.', 'emcp-tools' ) ), 403 );
		}

		$kit_slug      = isset( $_POST['kit_slug'] ) ? sanitize_key( wp_unslash( $_POST['kit_slug'] ) ) : '';
		$category_slug = isset( $_POST['category_slug'] ) ? sanitize_key( wp_unslash( $_POST['category_slug'] ) ) : '';
		$do_backup     = ! isset( $_POST['backup'] ) || '0' !== (string) wp_unslash( $_POST['backup'] );

		if ( '' === $kit_slug ) {
			wp_send_json_error( array( 'message' => __( 'Missing kit slug.', 'emcp-tools' ) ), 400 );
		}

		// Resolve the kit from the Pro remote library when the site has it, falling
		// back to the 10 bundled free kits otherwise. Applying is a free feature;
		// the Pro library is just a larger pool of kits to apply from.
		$kit = null;
		if ( class_exists( 'EMCP_Tools_Pro_Brand_Kits' ) && EMCP_Tools_Pro_Brand_Kits::user_has_access() ) {
			$kit = EMCP_Tools_Pro_Brand_Kits::find_kit( $kit_slug, $category_slug );
		}
		if ( null === $kit && class_exists( 'EMCP_Tools_Free_Brand_Kits' ) ) {
			$kit = EMCP_Tools_Free_Brand_Kits::find_kit( $kit_slug, $category_slug );
		}
		if ( null === $kit ) {
			wp_send_json_error( array( 'message' => __( 'Brand kit not found. Try syncing the library first.', 'emcp-tools' ) ), 404 );
		}

		$backup_id = null;
		if ( $do_backup ) {
			$backup = EMCP_Tools_Kit_Backup_Store::create( isset( $kit['title'] ) ? (string) $kit['title'] : $kit_slug );
			if ( ! is_wp_error( $backup ) ) {
				$backup_id = (int) $backup;
			}
		}

		$result = EMCP_Tools_Pro_Brand_Kits::apply_kit( $kit );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$result['backup_id'] = $backup_id;
		$result['view_url']  = self::recent_elementor_page_url();
		wp_send_json_success( $result );
	}

	/**
	 * Restores a brand kit backup from the admin page.
	 *
	 * @since 1.8.0
	 */
	public static function restore_brand_kit(): void {
		check_ajax_referer( 'emcp_tools_restore_pro_brand_kit', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to restore brand kits.', 'emcp-tools' ) ), 403 );
		}

		$backup_id    = isset( $_POST['backup_id'] ) ? absint( wp_unslash( $_POST['backup_id'] ) ) : 0;
		$full_clobber = isset( $_POST['full_clobber'] ) && '1' === (string) wp_unslash( $_POST['full_clobber'] );

		if ( $backup_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Missing or invalid backup.', 'emcp-tools' ) ), 400 );
		}

		$result = EMCP_Tools_Kit_Backup_Store::restore( $backup_id, $full_clobber );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Brand restored from backup.', 'emcp-tools' ),
				'view_url' => self::recent_elementor_page_url(),
			)
		);
	}

	/**
	 * URL of the most-recently-modified Elementor page (builder mode), or the
	 * site homepage as a fallback. Used by the apply/restore toasts so the user
	 * lands somewhere that actually showcases the change.
	 *
	 * @since 1.8.0
	 *
	 * @return string
	 */
	public static function recent_elementor_page_url(): string {
		$query = new WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_elementor_edit_mode',
						'value' => 'builder',
					),
				),
			)
		);

		if ( ! empty( $query->posts ) ) {
			$permalink = get_permalink( $query->posts[0] );
			if ( $permalink ) {
				return $permalink;
			}
		}

		return home_url( '/' );
	}
}
