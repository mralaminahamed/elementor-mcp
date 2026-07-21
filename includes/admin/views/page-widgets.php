<?php
/**
 * Sandbox tab view (custom widgets; extensible to other sandboxed code).
 *
 * Pro users: a table of AI-generated custom Elementor widgets with status,
 * last-error, view spec/PHP, activate/deactivate, and delete. The widgets are
 * created by AI agents through the MCP tools and live in an isolated uploads
 * sandbox — this screen is the human management / kill-switch surface.
 * Free users: upgrade CTA.
 *
 * @package EMCP_Tools
 * @since   1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$emcp_tools_wb_pro = class_exists( 'EMCP_Tools_Widget_Store' ) && EMCP_Tools_Widget_Store::user_has_access();
$emcp_tools_wb_url = function_exists( 'emcp_tools_upgrade_url' ) ? emcp_tools_upgrade_url() : '#';
?>

<div class="elementor-mcp-widget-builder">

	<div class="elementor-mcp-pro-prompts">
		<div class="elementor-mcp-pro-prompts-header">
			<div class="elementor-mcp-pro-prompts-heading">
				<h2>
					<?php esc_html_e( 'Sandbox', 'emcp-tools' ); ?>
					<span class="elementor-mcp-badge elementor-mcp-badge--pro">PRO</span>
				</h2>
				<p class="description">
					<?php esc_html_e( 'Code your AI agent generated through the MCP tools, starting with custom Elementor widgets. Everything lives in an isolated sandbox under wp-content/uploads, never in your theme, core, or other plugins. Active widgets appear in the Elementor panel under "Custom (EMCP)".', 'emcp-tools' ); ?>
				</p>
			</div>
		</div>

		<?php if ( ! $emcp_tools_wb_pro ) : ?>

			<div class="elementor-mcp-pro-cta">
				<p>
					<?php esc_html_e( 'The Sandbox is a Pro feature. Upgrade to let AI agents design and ship custom Elementor widgets in an isolated sandbox.', 'emcp-tools' ); ?>
				</p>
				<a class="button button-primary" href="<?php echo esc_url( $emcp_tools_wb_url ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'Upgrade to Pro', 'emcp-tools' ); ?>
				</a>
			</div>

		<?php else : ?>

			<?php $emcp_tools_wb_list = EMCP_Tools_Widget_Store::list_widgets( 'any' ); ?>

			<div class="notice notice-warning inline" style="margin: 12px 0;">
				<p>
					<strong><?php esc_html_e( 'Heads up:', 'emcp-tools' ); ?></strong>
					<?php esc_html_e( 'These widgets are PHP compiled by this plugin from an AI-supplied spec (the AI never writes raw PHP). Output is escaped by control type. You can deactivate or delete any widget here at any time.', 'emcp-tools' ); ?>
				</p>
			</div>

			<?php if ( empty( $emcp_tools_wb_list ) ) : ?>

				<p class="description" style="margin-top: 16px;">
					<?php esc_html_e( 'No custom widgets yet. Ask your AI agent to create one with the create-custom-widget tool.', 'emcp-tools' ); ?>
				</p>

			<?php else : ?>

				<table class="widefat striped elementor-mcp-widgets-table" data-nonce="<?php echo esc_attr( wp_create_nonce( 'emcp_tools_widgets' ) ); ?>" style="margin-top: 16px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Widget', 'emcp-tools' ); ?></th>
							<th><?php esc_html_e( 'Machine name', 'emcp-tools' ); ?></th>
							<th><?php esc_html_e( 'Status', 'emcp-tools' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'emcp-tools' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $emcp_tools_wb_list as $emcp_tools_w ) :
							$emcp_tools_wid    = (int) $emcp_tools_w['widget_id'];
							$emcp_tools_active = ( 'active' === $emcp_tools_w['status'] );
							?>
							<tr data-widget-id="<?php echo esc_attr( (string) $emcp_tools_wid ); ?>">
								<td>
									<strong><?php echo esc_html( $emcp_tools_w['title'] ); ?></strong>
									<?php if ( ! empty( $emcp_tools_w['last_error'] ) ) : ?>
										<br /><span style="color:#b32d2e;font-size:12px;">
											<?php
											printf(
												/* translators: %s: error message */
												esc_html__( 'Auto-deactivated after an error: %s', 'emcp-tools' ),
												esc_html( $emcp_tools_w['last_error'] )
											);
											?>
										</span>
									<?php endif; ?>
								</td>
								<td><code><?php echo esc_html( $emcp_tools_w['widget_name'] ); ?></code></td>
								<td>
									<span class="elementor-mcp-badge <?php echo esc_attr( $emcp_tools_active ? 'elementor-mcp-badge--pro' : '' ); ?>">
										<?php echo $emcp_tools_active ? esc_html__( 'Active', 'emcp-tools' ) : esc_html__( 'Inactive', 'emcp-tools' ); ?>
									</span>
								</td>
								<td>
									<button type="button" class="button elementor-mcp-wb-toggle" data-status="<?php echo esc_attr( $emcp_tools_active ? 'draft' : 'active' ); ?>">
										<?php echo $emcp_tools_active ? esc_html__( 'Deactivate', 'emcp-tools' ) : esc_html__( 'Activate', 'emcp-tools' ); ?>
									</button>
									<button type="button" class="button elementor-mcp-wb-delete">
										<?php esc_html_e( 'Delete', 'emcp-tools' ); ?>
									</button>
									<button
										type="button"
										class="button"
										data-emcp-code-view
										data-emcp-code-title="<?php echo esc_attr( $emcp_tools_w['title'] ); ?>"
										data-emcp-code-filename="<?php echo esc_attr( $emcp_tools_w['widget_name'] ); ?>.php"
									><?php esc_html_e( 'View code', 'emcp-tools' ); ?></button>
									<pre class="emcp-code-src" hidden><?php echo esc_html( EMCP_Tools_Widget_Store::get_php( $emcp_tools_wid ) ); ?></pre>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<script>
				( function () {
					var table = document.querySelector( '.elementor-mcp-widgets-table' );
					if ( ! table ) { return; }
					var nonce = table.getAttribute( 'data-nonce' ) || '';
					var ajaxUrl = window.ajaxurl || '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

					function post( action, body ) {
						body.append( 'action', action );
						body.append( 'nonce', nonce );
						return fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) { return r.json(); } );
					}

					table.addEventListener( 'click', function ( e ) {
						var row = e.target.closest( 'tr[data-widget-id]' );
						if ( ! row ) { return; }
						var id = row.getAttribute( 'data-widget-id' );

						if ( e.target.classList.contains( 'elementor-mcp-wb-toggle' ) ) {
							e.target.disabled = true;
							var b = new FormData();
							b.append( 'widget_id', id );
							b.append( 'status', e.target.getAttribute( 'data-status' ) );
							post( 'emcp_tools_toggle_widget', b ).then( function ( res ) {
								if ( res && res.success ) { window.location.reload(); }
								else { e.target.disabled = false; alert( ( res && res.data && res.data.message ) || 'Failed.' ); }
							} ).catch( function () { e.target.disabled = false; } );
						}

						if ( e.target.classList.contains( 'elementor-mcp-wb-delete' ) ) {
							/* global confirm */
							if ( ! confirm( '<?php echo esc_js( __( 'Delete this widget permanently? Pages using it will lose it.', 'emcp-tools' ) ); ?>' ) ) { return; }
							e.target.disabled = true;
							var d = new FormData();
							d.append( 'widget_id', id );
							post( 'emcp_tools_delete_widget', d ).then( function ( res ) {
								if ( res && res.success ) { row.parentNode.removeChild( row ); }
								else { e.target.disabled = false; alert( ( res && res.data && res.data.message ) || 'Failed.' ); }
							} ).catch( function () { e.target.disabled = false; } );
						}
					} );
				} )();
				</script>

			<?php endif; ?>

		<?php endif; ?>

	</div>

	<?php
	// ===== PHP Snippets (free, capability-gated) =====
	$emcp_tools_sn_can   = class_exists( 'EMCP_Tools_PHP_Snippet_Store' ) && EMCP_Tools_PHP_Snippet_Store::can_edit();
	$emcp_tools_sn_list  = class_exists( 'EMCP_Tools_PHP_Snippet_Store' ) ? EMCP_Tools_PHP_Snippet_Store::list_snippets( 'any' ) : array();
	$emcp_tools_sn_nonce = wp_create_nonce( 'emcp_tools_php_snippets' );
	?>
	<div class="elementor-mcp-pro-prompts elementor-mcp-php-snippets" data-nonce="<?php echo esc_attr( $emcp_tools_sn_nonce ); ?>" style="margin-top: 28px;">
		<div class="elementor-mcp-pro-prompts-header">
			<div class="elementor-mcp-pro-prompts-heading">
				<h2>
					<?php esc_html_e( 'PHP Snippets', 'emcp-tools' ); ?>
					<span class="elementor-mcp-badge elementor-mcp-badge--free"><?php esc_html_e( 'FREE', 'emcp-tools' ); ?></span>
				</h2>
				<p class="description">
					<?php esc_html_e( 'Run small pieces of PHP on your site, as a [emcp_snippet] shortcode or on a WordPress hook. An AI agent can draft snippets through the MCP tools, but they stay INACTIVE until you review and activate them here.', 'emcp-tools' ); ?>
				</p>
			</div>
		</div>

		<div class="notice notice-error inline" style="margin: 12px 0;">
			<p>
				<strong><?php esc_html_e( 'This runs real PHP on your site.', 'emcp-tools' ); ?></strong>
				<?php esc_html_e( 'The validator blocks obviously dangerous code (shell/eval/file writes/network/obfuscation), but static analysis is a guardrail, not a guarantee, only activate code you have read and trust. Activation is the approval step; AI can only create inactive drafts.', 'emcp-tools' ); ?>
			</p>
		</div>

		<?php if ( ! $emcp_tools_sn_can ) : ?>

			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'Managing PHP snippets requires the manage_options and unfiltered_html capabilities.', 'emcp-tools' ); ?></p>
			</div>

		<?php else : ?>

			<details class="elementor-mcp-sn-add" style="margin: 14px 0;">
				<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( '+ Add a snippet', 'emcp-tools' ); ?></summary>
				<form class="elementor-mcp-sn-form" style="margin-top: 12px; max-width: 760px;">
					<input type="hidden" name="snippet_id" value="0" />
					<p>
						<label><strong><?php esc_html_e( 'Title', 'emcp-tools' ); ?></strong><br />
							<input type="text" name="title" class="regular-text" placeholder="<?php esc_attr_e( 'My snippet', 'emcp-tools' ); ?>" />
						</label>
					</p>
					<p>
						<label><strong><?php esc_html_e( 'PHP code', 'emcp-tools' ); ?></strong> <span class="description"><?php esc_html_e( '(no <?php tag needed; use return or echo for shortcode output)', 'emcp-tools' ); ?></span><br />
							<textarea name="code" rows="8" spellcheck="false" style="width:100%;font-family:Menlo,Consolas,monospace;font-size:13px;" placeholder="return 'Hello, ' . get_bloginfo('name');"></textarea>
						</label>
					</p>
					<p style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;">
						<label><strong><?php esc_html_e( 'Runs as', 'emcp-tools' ); ?></strong><br />
							<select name="context">
								<option value="shortcode"><?php esc_html_e( 'Shortcode', 'emcp-tools' ); ?></option>
								<option value="hook"><?php esc_html_e( 'Hook', 'emcp-tools' ); ?></option>
								<option value="both"><?php esc_html_e( 'Both', 'emcp-tools' ); ?></option>
							</select>
						</label>
						<label class="elementor-mcp-sn-hookfield" style="display:none;"><strong><?php esc_html_e( 'Hook', 'emcp-tools' ); ?></strong><br />
							<input type="text" name="hook" class="regular-text" placeholder="wp_footer" />
						</label>
						<label class="elementor-mcp-sn-hookfield" style="display:none;"><strong><?php esc_html_e( 'Priority', 'emcp-tools' ); ?></strong><br />
							<input type="number" name="priority" value="10" style="width:80px;" />
						</label>
					</p>
					<p>
						<button type="submit" class="button button-primary elementor-mcp-sn-save"><?php esc_html_e( 'Save draft', 'emcp-tools' ); ?></button>
						<span class="elementor-mcp-sn-formmsg" style="margin-left:10px;"></span>
					</p>
					<div class="elementor-mcp-sn-findings"></div>
				</form>
			</details>

			<?php if ( empty( $emcp_tools_sn_list ) ) : ?>
				<p class="description"><?php esc_html_e( 'No snippets yet. Add one above, or ask your AI agent to draft one with the create-php-snippet tool.', 'emcp-tools' ); ?></p>
			<?php else : ?>
				<table class="widefat striped elementor-mcp-snippets-table" style="margin-top: 8px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Snippet', 'emcp-tools' ); ?></th>
							<th><?php esc_html_e( 'Runs', 'emcp-tools' ); ?></th>
							<th><?php esc_html_e( 'Status', 'emcp-tools' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'emcp-tools' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $emcp_tools_sn_list as $emcp_tools_s ) :
							$emcp_tools_sid    = (int) $emcp_tools_s['snippet_id'];
							$emcp_tools_sact   = ( 'active' === $emcp_tools_s['status'] );
							$emcp_tools_srec   = EMCP_Tools_PHP_Snippet_Store::get( $emcp_tools_sid );
							$emcp_tools_scode  = is_array( $emcp_tools_srec ) ? (string) $emcp_tools_srec['code'] : '';
							$emcp_tools_sval   = is_array( $emcp_tools_srec ) && isset( $emcp_tools_srec['validation'] ) ? $emcp_tools_srec['validation'] : array( 'findings' => array() );
							$emcp_tools_swarn  = 0;
							foreach ( ( $emcp_tools_sval['findings'] ?? array() ) as $emcp_tools_f ) {
								if ( 'warning' === ( $emcp_tools_f['severity'] ?? '' ) ) {
									$emcp_tools_swarn++;
								}
							}
							?>
							<tr
								data-snippet-id="<?php echo esc_attr( (string) $emcp_tools_sid ); ?>"
								data-title="<?php echo esc_attr( $emcp_tools_s['title'] ); ?>"
								data-context="<?php echo esc_attr( $emcp_tools_s['context'] ); ?>"
								data-hook="<?php echo esc_attr( $emcp_tools_s['hook'] ); ?>"
								data-priority="<?php echo esc_attr( (string) $emcp_tools_s['priority'] ); ?>"
							>
								<td>
									<strong><?php echo esc_html( $emcp_tools_s['title'] ); ?></strong>
									<br /><code
										class="emcp-copy-text"
										data-emcp-copy-text="<?php echo esc_attr( $emcp_tools_s['shortcode'] ); ?>"
										data-emcp-copied="<?php esc_attr_e( 'Copied!', 'emcp-tools' ); ?>"
										title="<?php esc_attr_e( 'Click to copy', 'emcp-tools' ); ?>"
										role="button"
										tabindex="0"
										style="font-size:11px;"
									><?php echo esc_html( $emcp_tools_s['shortcode'] ); ?></code>
									<?php if ( $emcp_tools_swarn > 0 ) : ?>
										<br /><span style="color:#996800;font-size:12px;">
											<?php
											printf(
												/* translators: %d: number of warnings */
												esc_html( _n( '%d validator warning, review the code', '%d validator warnings, review the code', $emcp_tools_swarn, 'emcp-tools' ) ),
												(int) $emcp_tools_swarn
											);
											?>
										</span>
									<?php endif; ?>
									<?php if ( ! empty( $emcp_tools_s['last_error'] ) ) : ?>
										<br /><span style="color:#b32d2e;font-size:12px;">
											<?php
											printf(
												/* translators: %s: error message */
												esc_html__( 'Auto-deactivated after an error: %s', 'emcp-tools' ),
												esc_html( $emcp_tools_s['last_error'] )
											);
											?>
										</span>
									<?php endif; ?>
								</td>
								<td>
									<?php echo esc_html( $emcp_tools_s['context'] ); ?>
									<?php if ( 'shortcode' !== $emcp_tools_s['context'] && '' !== $emcp_tools_s['hook'] ) : ?>
										<br /><code style="font-size:11px;"><?php echo esc_html( $emcp_tools_s['hook'] ); ?></code>
									<?php endif; ?>
								</td>
								<td>
									<span class="elementor-mcp-badge <?php echo esc_attr( $emcp_tools_sact ? 'elementor-mcp-badge--free' : '' ); ?>">
										<?php echo $emcp_tools_sact ? esc_html__( 'Active', 'emcp-tools' ) : esc_html__( 'Inactive', 'emcp-tools' ); ?>
									</span>
								</td>
								<td>
									<button type="button" class="button elementor-mcp-sn-toggle" data-status="<?php echo esc_attr( $emcp_tools_sact ? 'draft' : 'active' ); ?>">
										<?php echo $emcp_tools_sact ? esc_html__( 'Deactivate', 'emcp-tools' ) : esc_html__( 'Activate', 'emcp-tools' ); ?>
									</button>
									<button type="button" class="button elementor-mcp-sn-edit"><?php esc_html_e( 'Edit', 'emcp-tools' ); ?></button>
									<button type="button" class="button elementor-mcp-sn-delete"><?php esc_html_e( 'Delete', 'emcp-tools' ); ?></button>
									<button
										type="button"
										class="button"
										data-emcp-code-view
										data-emcp-code-title="<?php echo esc_attr( $emcp_tools_s['title'] ); ?>"
										data-emcp-code-filename="snippet-<?php echo (int) $emcp_tools_sid; ?>.php"
									><?php esc_html_e( 'View code', 'emcp-tools' ); ?></button>
									<pre class="emcp-code-src elementor-mcp-sn-code" hidden><?php echo esc_html( $emcp_tools_scode ); ?></pre>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<script>
			( function () {
				var root = document.querySelector( '.elementor-mcp-php-snippets' );
				if ( ! root ) { return; }
				var nonce = root.getAttribute( 'data-nonce' ) || '';
				var ajaxUrl = window.ajaxurl || '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

				function post( action, body ) {
					body.append( 'action', action );
					body.append( 'nonce', nonce );
					return fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) { return r.json(); } );
				}

				function renderFindings( box, validation ) {
					box.innerHTML = '';
					if ( ! validation || ! validation.findings || ! validation.findings.length ) { return; }
					var ul = document.createElement( 'ul' );
					ul.style.margin = '8px 0 0';
					validation.findings.forEach( function ( f ) {
						var li = document.createElement( 'li' );
						li.style.color = ( f.severity === 'critical' ) ? '#b32d2e' : '#996800';
						li.textContent = '[' + f.severity + '] ' + ( f.line ? 'line ' + f.line + ': ' : '' ) + f.message;
						ul.appendChild( li );
					} );
					box.appendChild( ul );
				}

				// Show/hide the hook fields based on context.
				var form = root.querySelector( '.elementor-mcp-sn-form' );
				function syncHookFields() {
					if ( ! form ) { return; }
					var ctx = form.querySelector( '[name="context"]' ).value;
					var show = ( ctx === 'hook' || ctx === 'both' );
					root.querySelectorAll( '.elementor-mcp-sn-hookfield' ).forEach( function ( el ) {
						el.style.display = show ? '' : 'none';
					} );
				}
				if ( form ) {
					form.querySelector( '[name="context"]' ).addEventListener( 'change', syncHookFields );
					syncHookFields();

					form.addEventListener( 'submit', function ( e ) {
						e.preventDefault();
						var btn = form.querySelector( '.elementor-mcp-sn-save' );
						var msg = form.querySelector( '.elementor-mcp-sn-formmsg' );
						var findings = form.querySelector( '.elementor-mcp-sn-findings' );
						findings.innerHTML = '';
						msg.textContent = '';
						btn.disabled = true;
						var b = new FormData( form );
						post( 'emcp_tools_save_php_snippet', b ).then( function ( res ) {
							btn.disabled = false;
							if ( res && res.success ) { window.location.reload(); return; }
							msg.style.color = '#b32d2e';
							msg.textContent = ( res && res.data && res.data.message ) || 'Failed.';
							if ( res && res.data && res.data.validation ) { renderFindings( findings, res.data.validation ); }
						} ).catch( function () { btn.disabled = false; msg.textContent = 'Request failed.'; } );
					} );
				}

				var table = root.querySelector( '.elementor-mcp-snippets-table' );
				if ( table ) {
					table.addEventListener( 'click', function ( e ) {
						var row = e.target.closest( 'tr[data-snippet-id]' );
						if ( ! row ) { return; }
						var id = row.getAttribute( 'data-snippet-id' );

						if ( e.target.classList.contains( 'elementor-mcp-sn-toggle' ) ) {
							var status = e.target.getAttribute( 'data-status' );
							if ( status === 'active' && ! confirm( '<?php echo esc_js( __( 'Activate this snippet? It will run real PHP on your site. Make sure you have read and trust the code.', 'emcp-tools' ) ); ?>' ) ) { return; }
							e.target.disabled = true;
							var tb = new FormData();
							tb.append( 'snippet_id', id );
							tb.append( 'status', status );
							post( 'emcp_tools_toggle_php_snippet', tb ).then( function ( res ) {
								if ( res && res.success ) { window.location.reload(); }
								else { e.target.disabled = false; alert( ( res && res.data && res.data.message ) || 'Failed.' ); }
							} ).catch( function () { e.target.disabled = false; } );
						}

						if ( e.target.classList.contains( 'elementor-mcp-sn-delete' ) ) {
							if ( ! confirm( '<?php echo esc_js( __( 'Delete this snippet permanently?', 'emcp-tools' ) ); ?>' ) ) { return; }
							e.target.disabled = true;
							var db = new FormData();
							db.append( 'snippet_id', id );
							post( 'emcp_tools_delete_php_snippet', db ).then( function ( res ) {
								if ( res && res.success ) { row.parentNode.removeChild( row ); }
								else { e.target.disabled = false; alert( ( res && res.data && res.data.message ) || 'Failed.' ); }
							} ).catch( function () { e.target.disabled = false; } );
						}

						if ( e.target.classList.contains( 'elementor-mcp-sn-edit' ) && form ) {
							var add = root.querySelector( '.elementor-mcp-sn-add' );
							if ( add ) { add.open = true; }
							form.querySelector( '[name="snippet_id"]' ).value = id;
							form.querySelector( '[name="title"]' ).value = row.getAttribute( 'data-title' ) || '';
							form.querySelector( '[name="context"]' ).value = row.getAttribute( 'data-context' ) || 'shortcode';
							form.querySelector( '[name="hook"]' ).value = row.getAttribute( 'data-hook' ) || '';
							form.querySelector( '[name="priority"]' ).value = row.getAttribute( 'data-priority' ) || '10';
							var pre = row.querySelector( '.elementor-mcp-sn-code' );
							form.querySelector( '[name="code"]' ).value = pre ? pre.textContent : '';
							syncHookFields();
							form.scrollIntoView( { behavior: 'smooth', block: 'center' } );
						}
					} );
				}
			} )();
			</script>

		<?php endif; ?>

	</div>
</div>
