<?php
/**
 * History tab — the AI-safe change ledger with one-click rollback.
 *
 * Surfaces EMCP_Tools_Change_Log entries (Elementor edits, filesystem writes,
 * database writes) to a human administrator, with a rollback action per
 * reversible entry. Included from EMCP_Tools_Admin::render_page().
 *
 * @package EMCP_Tools
 * @since   3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$emcp_entries = class_exists( 'EMCP_Tools_Change_Log' ) ? array_reverse( EMCP_Tools_Change_Log::all() ) : array();

// Domain filter.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view filter.
$emcp_domain = isset( $_GET['domain'] ) ? sanitize_key( wp_unslash( $_GET['domain'] ) ) : '';
if ( '' !== $emcp_domain ) {
	$emcp_entries = array_values( array_filter( $emcp_entries, static function ( $e ) use ( $emcp_domain ) {
		return ( $e['domain'] ?? '' ) === $emcp_domain;
	} ) );
}

$emcp_domain_labels = array(
	'elementor'  => __( 'Elementor', 'emcp-tools' ),
	'filesystem' => __( 'Filesystem', 'emcp-tools' ),
	'database'   => __( 'Database', 'emcp-tools' ),
);

// Result notice after a rollback.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice.
$emcp_rb = isset( $_GET['rollback'] ) ? sanitize_key( wp_unslash( $_GET['rollback'] ) ) : '';
// Result notices after a delete / clear-all.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice.
$emcp_del = isset( $_GET['deleted'] ) ? sanitize_key( wp_unslash( $_GET['deleted'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice.
$emcp_cleared = isset( $_GET['cleared'] ) ? absint( wp_unslash( $_GET['cleared'] ) ) : -1;
?>

<div class="emcp-history">
	<?php if ( 'ok' === $emcp_rb ) : ?>
		<div class="notice notice-success is-dismissible"><p><strong><?php esc_html_e( 'Change rolled back.', 'emcp-tools' ); ?></strong></p></div>
	<?php elseif ( 'error' === $emcp_rb ) : ?>
		<div class="notice notice-error is-dismissible"><p><strong><?php esc_html_e( 'Rollback failed.', 'emcp-tools' ); ?></strong>
		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only message text.
		echo isset( $_GET['msg'] ) ? ' ' . esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ) : '';
		?>
		</p></div>
	<?php endif; ?>

	<?php if ( '1' === $emcp_del ) : ?>
		<div class="notice notice-success is-dismissible"><p><strong><?php esc_html_e( 'History entry deleted.', 'emcp-tools' ); ?></strong></p></div>
	<?php elseif ( '0' === $emcp_del ) : ?>
		<div class="notice notice-error is-dismissible"><p><strong><?php esc_html_e( 'That history entry no longer exists.', 'emcp-tools' ); ?></strong></p></div>
	<?php endif; ?>

	<?php if ( $emcp_cleared > -1 ) : ?>
		<div class="notice notice-success is-dismissible"><p><strong>
			<?php
			printf(
				/* translators: %s: number of history entries removed */
				esc_html( _n( 'Cleared %s history entry.', 'Cleared %s history entries.', $emcp_cleared, 'emcp-tools' ) ),
				esc_html( number_format_i18n( $emcp_cleared ) )
			);
			?>
		</strong></p></div>
	<?php endif; ?>

	<div class="emcp-history__head">
		<div>
			<h2 class="emcp-history__title"><?php esc_html_e( 'Change history', 'emcp-tools' ); ?></h2>
			<p class="emcp-history__intro">
				<?php esc_html_e( 'Every AI-made change to Elementor pages, files, and the database is recorded here. Reversible changes can be rolled back with one click.', 'emcp-tools' ); ?>
			</p>
			<?php if ( ! empty( $emcp_entries ) ) : ?>
				<p class="emcp-history__clear-wrap">
					<a class="button button-link-delete emcp-history__clear"
						href="<?php echo esc_url( EMCP_Tools_Admin::clear_changes_url() ); ?>"
						onclick="return confirm('<?php echo esc_js( __( 'Clear the entire change history? This cannot be undone, and any entries that could still be rolled back will lose that ability.', 'emcp-tools' ) ); ?>');">
						<span class="dashicons dashicons-trash" aria-hidden="true"></span>
						<?php esc_html_e( 'Clear all', 'emcp-tools' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<ul class="emcp-history__filters">
			<li<?php echo '' === $emcp_domain ? ' class="is-active"' : ''; ?>><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . EMCP_Tools_Admin::PAGE_SLUG . '-history' ) ); ?>"><?php esc_html_e( 'All', 'emcp-tools' ); ?></a></li>
			<?php foreach ( $emcp_domain_labels as $emcp_dk => $emcp_dl ) : ?>
				<li<?php echo $emcp_domain === $emcp_dk ? ' class="is-active"' : ''; ?>><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . EMCP_Tools_Admin::PAGE_SLUG . '-history&domain=' . $emcp_dk ) ); ?>"><?php echo esc_html( $emcp_dl ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</div>

	<?php if ( empty( $emcp_entries ) ) : ?>
		<div class="emcp-history__empty">
			<span class="dashicons dashicons-undo" aria-hidden="true"></span>
			<p><?php esc_html_e( 'No changes recorded yet. As soon as a connected AI edits a page, writes a file, or changes the database, it will appear here, ready to roll back.', 'emcp-tools' ); ?></p>
		</div>
	<?php else : ?>
		<table class="widefat striped emcp-history__table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'emcp-tools' ); ?></th>
					<th><?php esc_html_e( 'Who', 'emcp-tools' ); ?></th>
					<th><?php esc_html_e( 'Type', 'emcp-tools' ); ?></th>
					<th><?php esc_html_e( 'Change', 'emcp-tools' ); ?></th>
					<th><?php esc_html_e( 'Target', 'emcp-tools' ); ?></th>
					<th class="emcp-history__actions-col"><?php esc_html_e( 'Action', 'emcp-tools' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $emcp_entries as $emcp_e ) : ?>
					<?php
					$emcp_id         = (string) ( $emcp_e['id'] ?? '' );
					$emcp_ts         = (int) ( $emcp_e['ts'] ?? 0 );
					$emcp_reversible = ! empty( $emcp_e['rollback'] ) && empty( $emcp_e['rolled_back'] );
					$emcp_dk         = (string) ( $emcp_e['domain'] ?? '' );
					?>
					<tr>
						<td>
							<?php echo esc_html( $emcp_ts ? date_i18n( 'Y-m-d H:i', $emcp_ts ) : ', ' ); ?>
							<?php if ( $emcp_ts ) : ?>
								<span class="emcp-history__ago"><?php echo esc_html( sprintf( /* translators: %s: human time diff */ __( '%s ago', 'emcp-tools' ), human_time_diff( $emcp_ts ) ) ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) ( $emcp_e['user_login'] ?? '' ) ); ?></td>
						<td><span class="emcp-history__badge emcp-history__badge--<?php echo esc_attr( $emcp_dk ); ?>"><?php echo esc_html( $emcp_domain_labels[ $emcp_dk ] ?? $emcp_dk ); ?></span></td>
						<td>
							<strong><?php echo esc_html( (string) ( $emcp_e['action'] ?? '' ) ); ?></strong><br />
							<span class="emcp-history__summary"><?php echo esc_html( (string) ( $emcp_e['summary'] ?? '' ) ); ?></span>
						</td>
						<td class="emcp-history__target"><?php echo esc_html( (string) ( $emcp_e['target'] ?? '' ) ); ?></td>
						<td class="emcp-history__actions-col">
							<?php if ( ! empty( $emcp_e['rolled_back'] ) ) : ?>
								<span class="emcp-history__state emcp-history__state--done"><?php esc_html_e( 'Rolled back', 'emcp-tools' ); ?></span>
							<?php elseif ( $emcp_reversible ) : ?>
								<a class="button button-secondary emcp-history__rollback"
									href="<?php echo esc_url( EMCP_Tools_Admin::rollback_change_url( $emcp_id ) ); ?>"
									onclick="return confirm('<?php echo esc_js( __( 'Roll this change back? This restores the previous state.', 'emcp-tools' ) ); ?>');">
									<span class="dashicons dashicons-undo" aria-hidden="true"></span>
									<?php esc_html_e( 'Roll back', 'emcp-tools' ); ?>
								</a>
							<?php else : ?>
								<span class="emcp-history__state">, </span>
							<?php endif; ?>
							<a class="emcp-history__delete"
								href="<?php echo esc_url( EMCP_Tools_Admin::delete_change_url( $emcp_id ) ); ?>"
								aria-label="<?php esc_attr_e( 'Delete this history entry', 'emcp-tools' ); ?>"
								onclick="return confirm('<?php echo esc_js(
									$emcp_reversible
										? __( 'Delete this entry? It can still be rolled back, deleting it discards that ability permanently.', 'emcp-tools' )
										: __( 'Delete this history entry? This cannot be undone.', 'emcp-tools' )
								); ?>');">
								<span class="dashicons dashicons-trash" aria-hidden="true"></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Delete', 'emcp-tools' ); ?></span>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="emcp-history__note"><?php esc_html_e( 'The ledger keeps the most recent changes (older entries age out). Rolling a change back records its own entry.', 'emcp-tools' ); ?></p>
	<?php endif; ?>
</div>
