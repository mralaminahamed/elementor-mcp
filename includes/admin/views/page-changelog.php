<?php
/**
 * Changelog tab view.
 *
 * Reads CHANGELOG.md and renders version entries as styled cards — matching the
 * website's changelog: a card per version with a LATEST badge, color-coded
 * Fixed/New/Improved tags, blockquote note callouts, nested sub-items, and
 * inline markdown (bold, `code`, and links) rendered to HTML.
 *
 * @package EMCP_Tools
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a single line of inline markdown (bold, code, links) to safe HTML.
 * The whole string is escaped first, then the markdown tokens are converted, so
 * nothing user-facing can inject markup.
 *
 * @param string $text Raw markdown text.
 * @return string Sanitized HTML.
 */
if ( ! function_exists( 'emcp_tools_changelog_inline_md' ) ) {
	function emcp_tools_changelog_inline_md( string $text ): string {
		$html = esc_html( $text );

		// Inline code: `code`.
		$html = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $html );

		// Links: [text](url).
		$html = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			static function ( $m ) {
				$url = esc_url( html_entity_decode( $m[2], ENT_QUOTES ) );
				if ( '' === $url ) {
					return $m[1];
				}
				return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
			},
			$html
		);

		// Bold: **text**.
		$html = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html );

		// Italic: *text*. Word-bounded so a lone '*' inside code (e.g.
		// `elementor_mcp_*`) can't open an italic span, and it never spans tags.
		$html = preg_replace( '/(?<![\w*])\*([^*<>]+)\*(?![\w*])/', '<em>$1</em>', $html );

		return wp_kses(
			$html,
			array(
				'strong' => array(),
				'em'     => array(),
				'code'   => array(),
				'a'      => array(
					'href'   => array(),
					'target' => array(),
					'rel'    => array(),
				),
			)
		);
	}
}

/**
 * Splits a leading "Fixed:/New:/Improved:/…" keyword off an item so it can be
 * rendered as a color-coded tag.
 *
 * @param string $text Item text.
 * @return array{tag:string,rest:string}
 */
if ( ! function_exists( 'emcp_tools_changelog_tag' ) ) {
	function emcp_tools_changelog_tag( string $text ): array {
		if ( preg_match( '/^(Fixed|New|Improved|Changed|Removed|Security|Deprecated|Note):\s*/i', $text, $m ) ) {
			return array(
				'tag'  => ucfirst( strtolower( $m[1] ) ),
				'rest' => substr( $text, strlen( $m[0] ) ),
			);
		}
		return array( 'tag' => '', 'rest' => $text );
	}
}

$emcp_tools_changelog_file = EMCP_TOOLS_DIR . 'CHANGELOG.md';

if ( ! file_exists( $emcp_tools_changelog_file ) ) {
	echo '<p>' . esc_html__( 'Changelog file not found.', 'emcp-tools' ) . '</p>';
	return;
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin file.
$emcp_tools_changelog_raw = (string) file_get_contents( $emcp_tools_changelog_file );

// Parse markdown into version blocks: each has notes[] (blockquotes) and
// items[] (each item = text + nested children for sub-bullets).
$emcp_tools_versions = array();
$emcp_tools_current  = null;

foreach ( explode( "\n", $emcp_tools_changelog_raw ) as $emcp_tools_line ) {
	$emcp_tools_line = rtrim( $emcp_tools_line );

	// Version header: ## [x.y.z]
	if ( preg_match( '/^##\s+\[([^\]]+)\]/', $emcp_tools_line, $m ) ) {
		if ( null !== $emcp_tools_current ) {
			$emcp_tools_versions[] = $emcp_tools_current;
		}
		$emcp_tools_current = array(
			'version' => $m[1],
			'notes'   => array(),
			'items'   => array(),
		);
		continue;
	}

	if ( null === $emcp_tools_current ) {
		continue;
	}

	// Blockquote note: > text
	if ( preg_match( '/^>\s?(.*)/', $emcp_tools_line, $m ) ) {
		if ( '' !== trim( $m[1] ) ) {
			$emcp_tools_current['notes'][] = $m[1];
		}
		continue;
	}

	// Sub-item (indented bullet): "  - text" or tab-indented.
	if ( preg_match( '/^(?:\t|\s{2,})[-*]\s+(.+)/', $emcp_tools_line, $m ) ) {
		$emcp_tools_last = count( $emcp_tools_current['items'] ) - 1;
		if ( $emcp_tools_last >= 0 ) {
			$emcp_tools_current['items'][ $emcp_tools_last ]['children'][] = $m[1];
		}
		continue;
	}

	// Top-level bullet: "- text"
	if ( preg_match( '/^[-*]\s+(.+)/', $emcp_tools_line, $m ) ) {
		$emcp_tools_current['items'][] = array(
			'text'     => $m[1],
			'children' => array(),
		);
		continue;
	}
}

if ( null !== $emcp_tools_current ) {
	$emcp_tools_versions[] = $emcp_tools_current;
}

$emcp_tools_latest_version = isset( $emcp_tools_versions[0]['version'] ) ? $emcp_tools_versions[0]['version'] : '';
?>

<div class="elementor-mcp-changelog">

	<div class="elementor-mcp-changelog-intro">
		<h2><?php esc_html_e( 'Changelog', 'emcp-tools' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'What changed in each release of EMCP Tools.', 'emcp-tools' ); ?>
		</p>
	</div>

	<div class="elementor-mcp-changelog-list">
		<?php foreach ( $emcp_tools_versions as $emcp_tools_entry ) : ?>
			<?php $emcp_tools_is_latest = ( $emcp_tools_entry['version'] === $emcp_tools_latest_version ); ?>
			<div class="elementor-mcp-changelog-version <?php echo esc_attr( $emcp_tools_is_latest ? 'is-latest' : '' ); ?>">
				<div class="elementor-mcp-changelog-version-header">
					<h3>
						<?php
						/* translators: %s: version number */
						printf( esc_html__( 'Version %s', 'emcp-tools' ), esc_html( $emcp_tools_entry['version'] ) );
						?>
					</h3>
					<?php if ( $emcp_tools_is_latest ) : ?>
						<span class="elementor-mcp-changelog-badge"><?php esc_html_e( 'Latest', 'emcp-tools' ); ?></span>
					<?php endif; ?>
				</div>

				<?php foreach ( $emcp_tools_entry['notes'] as $emcp_tools_note ) : ?>
					<div class="elementor-mcp-changelog-note">
						<?php echo emcp_tools_changelog_inline_md( $emcp_tools_note ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized via wp_kses inside the renderer. ?>
					</div>
				<?php endforeach; ?>

				<?php if ( ! empty( $emcp_tools_entry['items'] ) ) : ?>
					<ul class="elementor-mcp-changelog-items">
						<?php
						foreach ( $emcp_tools_entry['items'] as $emcp_tools_item ) :
							$emcp_tools_parts = emcp_tools_changelog_tag( $emcp_tools_item['text'] );
							?>
							<li>
								<?php if ( '' !== $emcp_tools_parts['tag'] ) : ?>
									<span class="elementor-mcp-cl-tag elementor-mcp-cl-tag--<?php echo esc_attr( strtolower( $emcp_tools_parts['tag'] ) ); ?>"><?php echo esc_html( $emcp_tools_parts['tag'] ); ?></span>
								<?php endif; ?>
								<?php echo emcp_tools_changelog_inline_md( $emcp_tools_parts['rest'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized via wp_kses inside the renderer. ?>
								<?php if ( ! empty( $emcp_tools_item['children'] ) ) : ?>
									<ul class="elementor-mcp-changelog-subitems">
										<?php foreach ( $emcp_tools_item['children'] as $emcp_tools_child ) : ?>
											<li><?php echo emcp_tools_changelog_inline_md( $emcp_tools_child ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized via wp_kses inside the renderer. ?></li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>

</div>
