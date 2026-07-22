<?php
/**
 * Atomic element props helper.
 *
 * Wraps and unwraps Elementor 4.0 typed prop values ($$type system).
 * MCP tools accept simple flat values from AI agents; this class converts
 * them to/from the $$type format that Elementor's atomic engine requires.
 *
 * @package EMCP_Tools
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helpers for building and reading atomic prop values.
 *
 * @since 1.5.0
 */
class EMCP_Tools_Atomic_Props {

	/**
	 * Wraps a plain string into a typed prop.
	 *
	 * @param string $value The string value.
	 * @return array Typed prop: { $$type: "string", value: "..." }
	 */
	public static function string( string $value ): array {
		return array(
			'$$type' => 'string',
			'value'  => $value,
		);
	}

	/**
	 * Wraps a number into a typed prop.
	 *
	 * @param int|float $value The numeric value.
	 * @return array Typed prop: { $$type: "number", value: N }
	 */
	public static function number( $value ): array {
		return array(
			'$$type' => 'number',
			'value'  => $value,
		);
	}

	/**
	 * Wraps a boolean into a typed prop.
	 *
	 * @param bool $value The boolean value.
	 * @return array Typed prop: { $$type: "boolean", value: true|false }
	 */
	public static function boolean( bool $value ): array {
		return array(
			'$$type' => 'boolean',
			'value'  => $value,
		);
	}

	/**
	 * Wraps a size value (number + unit) into a typed prop.
	 *
	 * @param int|float $size The size number.
	 * @param string    $unit The CSS unit (px, em, rem, %, vw, vh).
	 * @return array Typed prop: { $$type: "size", value: { size, unit } }
	 */
	public static function size( $size, string $unit = 'px' ): array {
		return array(
			'$$type' => 'size',
			'value'  => array(
				'size' => $size,
				'unit' => $unit,
			),
		);
	}

	/**
	 * Wraps text content into an html-v3 typed prop.
	 *
	 * @param string $text Plain text content.
	 * @return array Typed prop with html-v3 structure.
	 */
	public static function html( string $text ): array {
		return array(
			'$$type' => 'html-v3',
			'value'  => array(
				'content'  => self::string( $text ),
				'children' => array(),
			),
		);
	}

	/**
	 * Wraps a URL into a typed prop.
	 *
	 * @param string $url The URL string.
	 * @return array Typed prop: { $$type: "url", value: "..." }
	 */
	public static function url( string $url ): array {
		return array(
			'$$type' => 'url',
			'value'  => $url,
		);
	}

	/**
	 * Builds a link prop from a URL string.
	 *
	 * @param string $url           The destination URL.
	 * @param bool   $target_blank  Whether to open in new tab.
	 * @return array Typed link prop.
	 */
	public static function link( string $url, bool $target_blank = false ): array {
		$link_value = array(
			'destination' => self::url( $url ),
			'tag'         => self::string( 'a' ),
		);

		if ( $target_blank ) {
			$link_value['isTargetBlank'] = self::boolean( true );
		}

		return array(
			'$$type' => 'link',
			'value'  => $link_value,
		);
	}

	/**
	 * Builds a classes prop from an array of class IDs.
	 *
	 * @param string[] $class_ids Array of class identifiers.
	 * @return array Typed classes prop.
	 */
	public static function classes( array $class_ids = array() ): array {
		return array(
			'$$type' => 'classes',
			'value'  => $class_ids,
		);
	}

	/**
	 * Wraps a WordPress media image reference for the atomic `e-image` widget.
	 *
	 * Elementor's Image_Src_Prop_Type enforces `id XOR url` — exactly one of the
	 * two may be set, the other MUST be null — and the id must be an
	 * `image-attachment-id`, not a plain `number`. Passing both (or a `number`
	 * id) makes Elementor reject the value with `image: invalid_value` (#74).
	 *
	 * @param int    $image_id  The attachment ID (0 to use a url instead).
	 * @param string $image_url The image URL (used only when $image_id is 0).
	 * @return array Typed image prop.
	 */
	public static function image( int $image_id, string $image_url = '' ): array {
		return array(
			'$$type' => 'image',
			'value'  => array(
				'src' => array(
					'$$type' => 'image-src',
					'value'  => self::image_src_value( $image_id, $image_url ),
				),
			),
		);
	}

	/**
	 * Wraps a WordPress media SVG reference for the atomic `e-svg` widget.
	 *
	 * The `e-svg` widget's `svg` prop is a distinct `svg-src` type — NOT the
	 * `image`/`image-src` type used by `e-image` — so it must not be built with
	 * image() (#74). Its shape mirrors image-src (id/url) with at least one set.
	 *
	 * @param int    $svg_id  The attachment ID (0 to use a url instead).
	 * @param string $svg_url The SVG URL (used only when $svg_id is 0).
	 * @return array Typed svg-src prop.
	 */
	public static function svg( int $svg_id, string $svg_url = '' ): array {
		return array(
			'$$type' => 'svg-src',
			'value'  => self::image_src_value( $svg_id, $svg_url ),
		);
	}

	/**
	 * Builds the inner id/url value shared by image-src and svg-src: an
	 * `image-attachment-id` when an id is given (url null), otherwise a `url`
	 * (id null).
	 *
	 * @param int    $id  The attachment ID (0 to use a url).
	 * @param string $url The URL (used only when $id is 0).
	 * @return array{id:?array,url:?array}
	 */
	private static function image_src_value( int $id, string $url ): array {
		if ( $id > 0 ) {
			return array(
				'id'  => array(
					'$$type' => 'image-attachment-id',
					'value'  => $id,
				),
				'url' => null,
			);
		}

		return array(
			'id'  => null,
			'url' => '' === $url ? null : self::url( $url ),
		);
	}

	/**
	 * Recursively unwraps $$type values back to plain values.
	 *
	 * Returns an atomic widget's prop schema, or an empty array when Elementor
	 * or the widget type isn't available.
	 *
	 * @since 3.6.1
	 *
	 * @param string $widget_type Atomic widget type, e.g. 'e-heading'.
	 * @return array<string, object>
	 */
	public static function props_schema( string $widget_type ): array {
		if ( '' === $widget_type || ! class_exists( '\Elementor\Plugin' ) ) {
			return array();
		}

		$manager = \Elementor\Plugin::$instance->widgets_manager ?? null;
		if ( ! $manager || ! method_exists( $manager, 'get_widget_types' ) ) {
			return array();
		}

		try {
			$widget = $manager->get_widget_types( $widget_type );
		} catch ( \Throwable $e ) {
			return array();
		}

		if ( ! $widget || ! method_exists( $widget, 'get_props_schema' ) ) {
			return array();
		}

		try {
			return (array) $widget::get_props_schema();
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * Coerces atomic settings into the `$$type` envelopes Elementor expects.
	 *
	 * Atomic props are typed: `tag` wants `{$$type:'string'}`, `title` wants
	 * `{$$type:'html-v3'}`, and so on. A raw value like `'Hello'` is rejected.
	 * The trouble is that raw values were still *written* to `_elementor_data`,
	 * where they do lasting damage: Elementor falls back to the prop default, so
	 * the element renders placeholder text, and every later save of that page
	 * throws `Settings validation failed`. The page becomes uneditable through
	 * both the API and the editor (issue #101).
	 *
	 * Passing a plain string is the obvious thing for an agent to do, so accept
	 * it and wrap it rather than corrupting the page. Because this runs on the
	 * MERGED settings, it also repairs values a previous version already wrote.
	 *
	 * Elementor's own prop types are the oracle: candidate envelopes are offered
	 * to `validate()` and the first accepted one wins. Nothing here hardcodes
	 * which type a prop wants, so it keeps working when Elementor revises them
	 * (`html` -> `html-v2` -> `html-v3` already happened).
	 *
	 * @since 3.6.1
	 *
	 * @param string $widget_type Atomic widget type, e.g. 'e-heading'.
	 * @param array  $settings    Settings to coerce.
	 * @return array
	 */
	public static function coerce_settings( string $widget_type, array $settings ): array {
		return self::coerce_with_schema( self::props_schema( $widget_type ), $settings );
	}

	/**
	 * The coercion itself, against a supplied prop schema.
	 *
	 * Split out from coerce_settings() so it can be exercised without a live
	 * Elementor: it needs only objects exposing `validate()`.
	 *
	 * @since 3.6.1
	 *
	 * @param array $schema   Map of prop name => Elementor prop type.
	 * @param array $settings Settings to coerce.
	 * @return array
	 */
	public static function coerce_with_schema( array $schema, array $settings ): array {
		if ( empty( $schema ) ) {
			return $settings;
		}

		foreach ( $settings as $key => $value ) {
			$prop = $schema[ $key ] ?? null;
			if ( ! is_object( $prop ) || ! method_exists( $prop, 'validate' ) ) {
				continue;
			}

			if ( self::prop_accepts( $prop, $value ) ) {
				continue;
			}

			foreach ( self::envelope_candidates( $value ) as $candidate ) {
				if ( self::prop_accepts( $prop, $candidate ) ) {
					$settings[ $key ] = $candidate;
					break;
				}
			}
		}

		return $settings;
	}

	/**
	 * Whether a prop type accepts a value. Prop types can throw on odd input,
	 * which counts as "no".
	 *
	 * @since 3.6.1
	 *
	 * @param object $prop  An Elementor prop type.
	 * @param mixed  $value Candidate value.
	 * @return bool
	 */
	protected static function prop_accepts( $prop, $value ): bool {
		try {
			return (bool) $prop->validate( $value );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Envelopes to try for a raw value, cheapest and most specific first.
	 *
	 * @since 3.6.1
	 *
	 * @param mixed $value The raw value.
	 * @return array<int, array>
	 */
	protected static function envelope_candidates( $value ): array {
		$candidates = array();

		if ( is_array( $value ) && ! isset( $value['$$type'] ) ) {
			// A rich-text body handed over without its envelope. Wrap the inner
			// content too when it arrived as a bare string.
			$inner = $value;
			if ( isset( $inner['content'] ) && is_string( $inner['content'] ) ) {
				$inner['content'] = self::string( $inner['content'] );
			}
			if ( ! isset( $inner['children'] ) || ! is_array( $inner['children'] ) ) {
				$inner['children'] = array();
			}
			$candidates[] = array(
				'$$type' => 'html-v3',
				'value'  => $inner,
			);
			return $candidates;
		}

		if ( is_bool( $value ) ) {
			$candidates[] = self::boolean( $value );
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			$candidates[] = self::number( $value );
		}
		if ( is_scalar( $value ) ) {
			$text         = (string) $value;
			$candidates[] = self::string( $text );
			$candidates[] = self::html( $text );
		}

		return $candidates;
	}

	/**
	 * Unwraps a $$type-wrapped prop into a plain PHP value.
	 *
	 * Used for returning AI-friendly data from get-element-settings.
	 *
	 * @param mixed $prop The prop value (may or may not be $$type-wrapped).
	 * @return mixed The unwrapped plain value.
	 */
	public static function unwrap( $prop ) {
		if ( ! is_array( $prop ) ) {
			return $prop;
		}

		if ( isset( $prop['$$type'] ) ) {
			$type  = $prop['$$type'];
			$value = $prop['value'] ?? null;

			switch ( $type ) {
				case 'string':
				case 'number':
				case 'boolean':
				case 'url':
					return $value;

				case 'size':
					return is_array( $value )
						? ( $value['size'] ?? 0 ) . ( $value['unit'] ?? 'px' )
						: $value;

				case 'html-v3':
					if ( is_array( $value ) && isset( $value['content'] ) ) {
						return self::unwrap( $value['content'] );
					}
					return $value;

				case 'link':
					if ( is_array( $value ) && isset( $value['destination'] ) ) {
						return self::unwrap( $value['destination'] );
					}
					return $value;

				case 'classes':
					return is_array( $value ) ? $value : array();

				case 'image':
					if ( is_array( $value ) && isset( $value['src'] ) && is_array( $value['src'] ) ) {
						// src is wrapped as {$$type:image-src, value:{id,url}}; an
						// older/bare {id,url} shape is tolerated for round-trips.
						$src = isset( $value['src']['$$type'], $value['src']['value'] ) && is_array( $value['src']['value'] )
							? $value['src']['value']
							: $value['src'];
						return array(
							'id'  => self::unwrap( $src['id'] ?? 0 ),
							'url' => self::unwrap( $src['url'] ?? '' ),
						);
					}
					return $value;

				case 'svg-src':
					if ( is_array( $value ) ) {
						return array(
							'id'  => self::unwrap( $value['id'] ?? 0 ),
							'url' => self::unwrap( $value['url'] ?? '' ),
						);
					}
					return $value;

				case 'image-attachment-id':
					return $value;

				default:
					return is_array( $value ) ? self::unwrap_array( $value ) : $value;
			}
		}

		return self::unwrap_array( $prop );
	}

	/**
	 * Unwraps all values in an array recursively.
	 *
	 * @param array $arr The array to unwrap.
	 * @return array Unwrapped array.
	 */
	private static function unwrap_array( array $arr ): array {
		$result = array();
		foreach ( $arr as $key => $value ) {
			$result[ $key ] = self::unwrap( $value );
		}
		return $result;
	}

	/**
	 * Checks whether Elementor atomic (V4) elements are available **and will
	 * persist**.
	 *
	 * Detection is not version-number based. Elementor ships atomic/V4 as opt-in
	 * experiments while the core `ELEMENTOR_VERSION` constant still reports a 3.x
	 * value, so `version_compare( ELEMENTOR_VERSION, '4.0.0', '>=' )` is false on
	 * exactly the sites running atomic.
	 *
	 * Crucially, we gate on whether the atomic **element types are registered**
	 * (`e-flexbox` / `e-div-block`), not merely whether the V4 *page* editor is
	 * opted in. Those are separate experiments: a site can have `e_opt_in_v4_page`
	 * active while `e_atomic_elements` is OFF — atomic tools would register, but
	 * `Elementor\Document::save()` then silently sanitizes the unknown elements
	 * away (the write returns success yet `_elementor_data` stays empty). Keying
	 * on element-type registration means the atomic tools appear only when an
	 * atomic write will actually persist. Verified live on Elementor 3.31.5.
	 *
	 * @return bool True if atomic element types are registered/available.
	 */
	public static function is_atomic_supported(): bool {
		if ( class_exists( '\Elementor\Plugin' ) && method_exists( '\Elementor\Plugin', 'instance' ) ) {
			$elementor = \Elementor\Plugin::instance();

			// Primary, authoritative signal: the atomic element types are
			// registered server-side, so Document::save() will keep them.
			if ( isset( $elementor->elements_manager ) && is_object( $elementor->elements_manager )
				&& method_exists( $elementor->elements_manager, 'get_element_types' ) ) {
				$types = $elementor->elements_manager->get_element_types();
				if ( is_array( $types ) && ( isset( $types['e-flexbox'] ) || isset( $types['e-div-block'] ) ) ) {
					return true;
				}
			}

			// Secondary: the experiments that register the atomic element types.
			// (Deliberately NOT e_opt_in_v4_page / editor_v4 — those opt the page
			// editor into V4 without guaranteeing element registration, which is
			// the silent-no-op trap above.) method_exists-guarded so we never
			// fatal on builds/stubs without the experiments API.
			if ( isset( $elementor->experiments ) && is_object( $elementor->experiments )
				&& method_exists( $elementor->experiments, 'is_feature_active' ) ) {
				foreach ( array( 'e_atomic_elements', 'atomic_widgets' ) as $feature ) {
					if ( $elementor->experiments->is_feature_active( $feature ) ) {
						return true;
					}
				}
			}
		}

		// NB: do NOT use class_exists( '\Elementor\Modules\AtomicWidgets\Module' )
		// as a signal — that class is autoloaded even when the atomic experiment
		// is OFF and no atomic element types are registered, which would make the
		// tools register while writes silently get dropped on save.

		// Genuine 4.0+ core (kept as a forward-compatible fallback).
		return defined( 'ELEMENTOR_VERSION' ) && version_compare( ELEMENTOR_VERSION, '4.0.0', '>=' );
	}
}
