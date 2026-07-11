<?php
/**
 * Page Snapshot builder — one normalized page digest for MCP agents.
 *
 * Assembles a compact, normalized view of a page (structure tree + counts,
 * global tokens actually in use, per-device responsive overrides, content
 * outline, SEO-lite) so an AI agent can reason about a page from a single
 * call instead of chaining get-page-structure / get-global-settings /
 * list-global-classes and reassembling. Heavy audit summaries are opt-in and
 * resolved through the `emcp_tools_page_snapshot_sections` filter seam.
 *
 * @package EMCP_Tools
 * @since   3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds normalized page snapshots.
 *
 * @since 3.3.0
 */
class EMCP_Tools_Page_Snapshot {

	/**
	 * The data access layer.
	 *
	 * @var EMCP_Tools_Data
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param EMCP_Tools_Data $data The data access layer.
	 */
	public function __construct( EMCP_Tools_Data $data ) {
		$this->data = $data;
	}

	/**
	 * Recursively normalize an Elementor elements array into a compact tree + counts.
	 *
	 * @param array $elements Elementor elements array.
	 * @param int   $depth    Current depth (0 at top).
	 * @return array{tree:array,counts:array}
	 */
	public static function normalize_tree( array $elements, int $depth = 0 ): array {
		$tree   = array();
		$counts = array(
			'containers'     => 0,
			'widgets'        => 0,
			'by_widget_type' => array(),
			'max_depth'      => $depth,
			'total_elements' => 0,
		);

		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$el_type   = isset( $el['elType'] ) ? (string) $el['elType'] : '';
			$is_widget = ( 'widget' === $el_type );

			$node = array(
				'id'    => isset( $el['id'] ) ? (string) $el['id'] : '',
				'kind'  => $is_widget ? 'widget' : 'container',
				'depth' => $depth,
			);

			if ( $is_widget ) {
				$wt                  = isset( $el['widgetType'] ) ? (string) $el['widgetType'] : '';
				$node['widget_type'] = $wt;
				++$counts['widgets'];
				if ( '' !== $wt ) {
					$counts['by_widget_type'][ $wt ] = ( $counts['by_widget_type'][ $wt ] ?? 0 ) + 1;
				}
			} else {
				++$counts['containers'];
			}
			++$counts['total_elements'];

			$label = self::element_label( $el );
			if ( '' !== $label ) {
				$node['label'] = $label;
			}

			$children = ( isset( $el['elements'] ) && is_array( $el['elements'] ) ) ? $el['elements'] : array();
			if ( $children ) {
				$sub                       = self::normalize_tree( $children, $depth + 1 );
				$node['children']          = $sub['tree'];
				$counts['containers']     += $sub['counts']['containers'];
				$counts['widgets']        += $sub['counts']['widgets'];
				$counts['total_elements'] += $sub['counts']['total_elements'];
				$counts['max_depth']       = max( $counts['max_depth'], $sub['counts']['max_depth'] );
				foreach ( $sub['counts']['by_widget_type'] as $k => $v ) {
					$counts['by_widget_type'][ $k ] = ( $counts['by_widget_type'][ $k ] ?? 0 ) + $v;
				}
			} else {
				$node['children'] = array();
			}

			$tree[] = $node;
		}

		return array(
			'tree'   => $tree,
			'counts' => $counts,
		);
	}

	/**
	 * Derive a short human label for an element from its settings.
	 *
	 * @param array $el Element array.
	 * @return string Short label, or '' when none derivable.
	 */
	public static function element_label( array $el ): string {
		$s = ( isset( $el['settings'] ) && is_array( $el['settings'] ) ) ? $el['settings'] : array();
		foreach ( array( '_title', 'title', 'text', 'editor', 'heading_title' ) as $k ) {
			if ( ! empty( $s[ $k ] ) && is_string( $s[ $k ] ) ) {
				$plain = trim( (string) preg_replace( '/<[^>]*>/', '', $s[ $k ] ) );
				if ( '' !== $plain ) {
					return self::snippet( $plain, 60 );
				}
			}
		}
		return '';
	}

	/**
	 * Truncate to a max length with an ellipsis.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max length.
	 * @return string
	 */
	public static function snippet( string $text, int $max ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		if ( $len <= $max ) {
			return $text;
		}
		$cut = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max - 1 ) : substr( $text, 0, $max - 1 );
		return $cut . '…';
	}
}
