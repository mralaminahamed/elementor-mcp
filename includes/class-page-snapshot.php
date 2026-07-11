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
	 * Walk elements collecting which global colors/typography, g- classes, and raw
	 * fonts are actually referenced, with usage counts.
	 *
	 * @param array $elements Elementor elements array.
	 * @return array{global_colors:array<string,int>,global_typography:array<string,int>,global_classes:array<string,int>,fonts_in_use:string[],colors_in_use:string[]}
	 */
	public static function extract_tokens( array $elements ): array {
		$acc = array(
			'global_colors'     => array(),
			'global_typography' => array(),
			'global_classes'    => array(),
			'fonts_in_use'      => array(),
			'colors_in_use'     => array(),
		);
		self::walk_tokens( $elements, $acc );
		$acc['fonts_in_use']  = array_values( array_unique( $acc['fonts_in_use'] ) );
		$acc['colors_in_use'] = array_values( array_unique( $acc['colors_in_use'] ) );
		return $acc;
	}

	/**
	 * Recursive token collector.
	 *
	 * @param array $elements Elements.
	 * @param array $acc      Accumulator (by reference).
	 */
	private static function walk_tokens( array $elements, array &$acc ): void {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$s = ( isset( $el['settings'] ) && is_array( $el['settings'] ) ) ? $el['settings'] : array();

			// Global color/typography refs live in __globals__: "globals/colors?id=primary".
			if ( ! empty( $s['__globals__'] ) && is_array( $s['__globals__'] ) ) {
				foreach ( $s['__globals__'] as $ref ) {
					if ( ! is_string( $ref ) ) {
						continue;
					}
					if ( preg_match( '#globals/colors\?id=([\w-]+)#', $ref, $m ) ) {
						$acc['global_colors'][ $m[1] ] = ( $acc['global_colors'][ $m[1] ] ?? 0 ) + 1;
					} elseif ( preg_match( '#globals/typography\?id=([\w-]+)#', $ref, $m ) ) {
						$acc['global_typography'][ $m[1] ] = ( $acc['global_typography'][ $m[1] ] ?? 0 ) + 1;
					}
				}
			}

			// g- global classes appear in _css_classes / classes (string), or atomic classes.value (array).
			foreach ( array( '_css_classes', 'classes' ) as $ck ) {
				if ( ! empty( $s[ $ck ] ) && is_string( $s[ $ck ] ) ) {
					foreach ( preg_split( '/\s+/', $s[ $ck ] ) as $cls ) {
						if ( '' !== $cls && 0 === strpos( $cls, 'g-' ) ) {
							$acc['global_classes'][ $cls ] = ( $acc['global_classes'][ $cls ] ?? 0 ) + 1;
						}
					}
				}
			}
			if ( isset( $s['classes']['value'] ) && is_array( $s['classes']['value'] ) ) {
				foreach ( $s['classes']['value'] as $cls ) {
					if ( is_string( $cls ) && 0 === strpos( $cls, 'g-' ) ) {
						$acc['global_classes'][ $cls ] = ( $acc['global_classes'][ $cls ] ?? 0 ) + 1;
					}
				}
			}

			// Raw fonts / hex colors in use.
			foreach ( $s as $key => $val ) {
				if ( is_string( $val ) && '' !== $val ) {
					if ( false !== strpos( (string) $key, 'font_family' ) ) {
						$acc['fonts_in_use'][] = $val;
					} elseif ( ( false !== strpos( (string) $key, 'color' ) ) && preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $val ) ) {
						$acc['colors_in_use'][] = strtolower( $val );
					}
				}
			}

			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::walk_tokens( $el['elements'], $acc );
			}
		}
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
