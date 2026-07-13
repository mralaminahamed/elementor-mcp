<?php
/**
 * Spectra (Ultimate Addons for Gutenberg) block catalog.
 *
 * The block LIST is dynamic — read from Spectra's own `UAGB_Block_Module::
 * get_blocks_info()` — so it is always complete and accurate. The curated
 * per-block schemas (key attributes + defaults + an optional innerBlocks
 * template) are DATA here, keyed by block slug, mirroring the Elementor widget
 * catalog. Spectra's JS-only blocks do not expose attributes server-side, so
 * their curated params are authored here; registered (server-rendered) blocks
 * can additionally expose their raw attributes via get-block-schema full:true.
 *
 * @package EMCP_Tools
 * @since   3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Spectra block catalog (list + curated schemas).
 */
class EMCP_Tools_Spectra_Catalog {

	const PLUGIN_FILE = 'ultimate-addons-for-gutenberg/ultimate-addons-for-gutenberg.php';

	/**
	 * Curated per-block schemas. Keyed by full block name (`uagb/<slug>`).
	 * Each: {
	 *   params:  [ { name, type, default?, desc } ]  // curated key attributes
	 *   inner?:  [ { name, attrs? } ]                // innerBlocks template
	 *   dynamic: bool                                 // server-rendered (self-closing markup)
	 * }
	 * Grows over time; a block absent here still lists and falls back to the live
	 * registry attributes (full:true) or its doc link.
	 *
	 * @var array<string,array>
	 */
	const CURATED = array(
		'uagb/advanced-heading' => array(
			'dynamic' => false,
			'params'  => array(
				array( 'name' => 'headingTitle', 'type' => 'string', 'default' => 'Heading', 'desc' => 'The heading text.' ),
				array( 'name' => 'headingDesc', 'type' => 'string', 'default' => '', 'desc' => 'Optional sub-heading / description.' ),
				array( 'name' => 'headingTag', 'type' => 'string', 'default' => 'h2', 'desc' => 'HTML tag: h1-h6, p, span, div.' ),
				array( 'name' => 'headingAlign', 'type' => 'string', 'default' => 'left', 'desc' => 'left | center | right.' ),
				array( 'name' => 'headingColor', 'type' => 'color', 'default' => '', 'desc' => 'Heading text color.' ),
			),
		),
		'uagb/info-box' => array(
			'dynamic' => false,
			'params'  => array(
				array( 'name' => 'headingTitle', 'type' => 'string', 'default' => 'Info Box Title', 'desc' => 'Title text.' ),
				array( 'name' => 'headingDesc', 'type' => 'string', 'default' => '', 'desc' => 'Description text.' ),
				array( 'name' => 'source_type', 'type' => 'string', 'default' => 'icon', 'desc' => 'icon | image.' ),
				array( 'name' => 'icon', 'type' => 'string', 'default' => 'info', 'desc' => 'Icon name when source_type is icon.' ),
				array( 'name' => 'ctaType', 'type' => 'string', 'default' => 'none', 'desc' => 'none | text | button | all.' ),
			),
		),
		'uagb/container' => array(
			'dynamic' => false,
			'params'  => array(
				array( 'name' => 'innerContentWidth', 'type' => 'string', 'default' => 'alignfull', 'desc' => 'alignfull | alignwide | boxed.' ),
				array( 'name' => 'directionDesktop', 'type' => 'string', 'default' => 'column', 'desc' => 'Flex direction: row | column.' ),
				array( 'name' => 'backgroundType', 'type' => 'string', 'default' => 'none', 'desc' => 'none | color | image | gradient.' ),
				array( 'name' => 'backgroundColor', 'type' => 'color', 'default' => '', 'desc' => 'Background color when backgroundType is color.' ),
			),
			'inner'   => array(),
		),
		'uagb/buttons' => array(
			'dynamic' => false,
			'params'  => array(
				array( 'name' => 'align', 'type' => 'string', 'default' => 'left', 'desc' => 'Button group alignment.' ),
			),
			'inner'   => array(
				array( 'name' => 'uagb/buttons-child', 'attrs' => array( 'label' => 'Click Here' ) ),
			),
		),
		'uagb/separator' => array(
			'dynamic' => false,
			'params'  => array(
				array( 'name' => 'separatorStyle', 'type' => 'string', 'default' => 'solid', 'desc' => 'solid | dashed | dotted | double.' ),
				array( 'name' => 'separatorColor', 'type' => 'color', 'default' => '#0000001a', 'desc' => 'Separator color.' ),
				array( 'name' => 'separatorWidth', 'type' => 'number', 'default' => 100, 'desc' => 'Width value.' ),
			),
		),
	);

	/**
	 * Whether Spectra is active.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return function_exists( 'is_plugin_active' )
			? is_plugin_active( self::PLUGIN_FILE )
			: class_exists( 'UAGB_Block_Module' );
	}

	/**
	 * Compact block index from Spectra's own registry. Excludes child blocks,
	 * extensions, and deprecated entries.
	 *
	 * @param string $category Optional admin category filter.
	 * @param string $search   Optional case-insensitive title/description/slug filter.
	 * @return array<int,array{name:string,title:string,description:string,category:string,doc:string,dynamic:bool}>
	 */
	public static function blocks_index( string $category = '', string $search = '' ): array {
		$info   = class_exists( 'UAGB_Block_Module' ) ? (array) UAGB_Block_Module::get_blocks_info() : array();
		$search = strtolower( trim( $search ) );
		$out    = array();

		foreach ( $info as $name => $b ) {
			$name = (string) $name;
			if ( ! empty( $b['extension'] ) || ! empty( $b['deprecated'] ) || false !== strpos( $name, '-child' ) ) {
				continue;
			}
			$cats = isset( $b['admin_categories'] ) ? (array) $b['admin_categories'] : array();
			$cat  = $cats[0] ?? '';
			if ( '' !== $category && ! in_array( $category, $cats, true ) ) {
				continue;
			}
			$title = (string) ( $b['title'] ?? $name );
			$desc  = (string) ( $b['description'] ?? '' );
			if ( '' !== $search
				&& false === strpos( strtolower( $title ), $search )
				&& false === strpos( strtolower( $desc ), $search )
				&& false === strpos( strtolower( $name ), $search ) ) {
				continue;
			}
			$out[] = array(
				'name'        => $name,
				'title'       => $title,
				'description' => $desc,
				'category'    => $cat,
				'doc'         => (string) ( $b['doc'] ?? '' ),
				'dynamic'     => ! empty( $b['dynamic_assets'] ),
				'curated'     => isset( self::CURATED[ $name ] ),
			);
		}
		return $out;
	}

	/**
	 * The raw get_blocks_info() entry for a block (or null).
	 *
	 * @param string $name Full block name (`uagb/<slug>`).
	 * @return array|null
	 */
	public static function block_meta( string $name ): ?array {
		$info = class_exists( 'UAGB_Block_Module' ) ? (array) UAGB_Block_Module::get_blocks_info() : array();
		return isset( $info[ $name ] ) ? (array) $info[ $name ] : null;
	}

	/**
	 * The curated schema for a block, or null if it is not curated.
	 *
	 * @param string $name Full block name.
	 * @return array|null
	 */
	public static function curated( string $name ): ?array {
		return self::CURATED[ $name ] ?? null;
	}

	/**
	 * The documentation URL for a block from its doc slug.
	 *
	 * @param string $name Full block name.
	 * @return string
	 */
	public static function doc_url( string $name ): string {
		$meta = self::block_meta( $name );
		$doc  = $meta['doc'] ?? '';
		return '' !== $doc ? ( 'https://wpspectra.com/docs/' . $doc . '/' ) : '';
	}
}
