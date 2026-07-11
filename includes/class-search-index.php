<?php
/**
 * Content search index — a materialized, searchable corpus of pages, templates,
 * widgets, and global styles, plus the pure document builders that feed it.
 *
 * v1 is lexical (see EMCP_Tools_Search_Ranker); the storage is a single custom
 * table. Document builders are pure/static so they can be unit-tested without a
 * database, and reuse the P1 page-snapshot helpers to normalize page text.
 *
 * @package EMCP_Tools
 * @since   3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds + stores + queries the content search index.
 *
 * @since 3.3.0
 */
class EMCP_Tools_Search_Index {

	/**
	 * Build a searchable document for an Elementor page from its element tree.
	 *
	 * @param array  $elements Elementor elements array.
	 * @param string $title    Post title.
	 * @return array{title:string,content:string}
	 */
	public static function page_document( array $elements, string $title ): array {
		$parts = array( $title );

		if ( class_exists( 'EMCP_Tools_Page_Snapshot' ) ) {
			$norm    = EMCP_Tools_Page_Snapshot::normalize_tree( $elements );
			$content = EMCP_Tools_Page_Snapshot::content_stats( $elements );
			$tokens  = EMCP_Tools_Page_Snapshot::extract_tokens( $elements );

			foreach ( ( $content['headings'] ?? array() ) as $h ) {
				$parts[] = (string) ( $h['text'] ?? '' );
			}
			foreach ( array_keys( $norm['counts']['by_widget_type'] ?? array() ) as $wt ) {
				$parts[] = str_replace( array( '-', '_' ), ' ', (string) $wt );
			}
			self::collect_labels( $norm['tree'] ?? array(), $parts );
			foreach ( array_keys( $tokens['global_colors'] ?? array() ) as $c ) {
				$parts[] = (string) $c;
			}
			foreach ( array_keys( $tokens['global_classes'] ?? array() ) as $c ) {
				$parts[] = (string) $c;
			}
		}

		$content = trim( implode( ' ', array_filter( array_map( 'strval', $parts ), static function ( $s ) {
			return '' !== trim( $s );
		} ) ) );

		return array(
			'title'   => $title,
			'content' => $content,
		);
	}

	/**
	 * Recursively collect element labels into $parts.
	 *
	 * @param array $tree  Normalized tree nodes.
	 * @param array $parts Accumulator (by reference).
	 */
	private static function collect_labels( array $tree, array &$parts ): void {
		foreach ( $tree as $node ) {
			if ( ! empty( $node['label'] ) ) {
				$parts[] = (string) $node['label'];
			}
			if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
				self::collect_labels( $node['children'], $parts );
			}
		}
	}

	/**
	 * Searchable documents for every cataloged widget.
	 *
	 * @return array<int,array{object_type:string,object_id:string,title:string,content:string,meta:array}>
	 */
	public static function widget_documents(): array {
		$docs = array();
		if ( ! class_exists( 'EMCP_Tools_Widget_Catalog' ) ) {
			return $docs;
		}
		foreach ( EMCP_Tools_Widget_Catalog::get() as $type => $e ) {
			$keywords = ( isset( $e['keywords'] ) && is_array( $e['keywords'] ) ) ? implode( ' ', $e['keywords'] ) : '';
			$params   = ( isset( $e['params'] ) && is_array( $e['params'] ) ) ? implode( ' ', array_keys( $e['params'] ) ) : '';
			$content  = trim( implode( ' ', array(
				(string) ( $e['title'] ?? $type ),
				(string) ( $e['use_case'] ?? '' ),
				$keywords,
				(string) ( $e['category'] ?? '' ),
				str_replace( array( '-', '_' ), ' ', (string) $type ),
				$params,
			) ) );
			$docs[]   = array(
				'object_type' => 'widget',
				'object_id'   => (string) $type,
				'title'       => (string) ( $e['title'] ?? $type ),
				'content'     => $content,
				'meta'        => array(
					'category' => (string) ( $e['category'] ?? '' ),
					'tier'     => (string) ( $e['tier'] ?? 'free' ),
				),
			);
		}
		return $docs;
	}
}
