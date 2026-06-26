<?php
/**
 * Site-context composition for MCP server instructions.
 * @package EMCP_Tools\Tests
 */
namespace EMCP_Tools\Tests;

use PHPUnit\Framework\TestCase;

class SiteContextTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_wp_options'] = array();
	}

	/** @test */
	public function compose_returns_base_when_disabled(): void {
		$this->assertSame( 'BASE', \EMCP_Tools_Site_Context::compose( 'BASE', 'my guidance', false ) );
	}

	/** @test */
	public function compose_returns_base_when_context_blank(): void {
		$this->assertSame( 'BASE', \EMCP_Tools_Site_Context::compose( 'BASE', '   ', true ) );
	}

	/** @test */
	public function compose_appends_context_under_the_delimiter(): void {
		$out = \EMCP_Tools_Site_Context::compose( 'BASE', 'Be concise.', true );
		$this->assertSame( "BASE\n\n## Site context\n\nBe concise.", $out );
	}

	/** @test */
	public function compose_trims_surrounding_whitespace_of_context(): void {
		$out = \EMCP_Tools_Site_Context::compose( 'BASE', "\n  Hello  \n", true );
		$this->assertSame( "BASE\n\n## Site context\n\nHello", $out );
	}

	/** @test */
	public function compose_caps_context_length(): void {
		$long = str_repeat( 'x', \EMCP_Tools_Site_Context::MAX_CHARS + 500 );
		$out  = \EMCP_Tools_Site_Context::compose( 'BASE', $long, true );
		// base + delimiter + exactly MAX_CHARS of context.
		$ctx_len = mb_strlen( $out ) - mb_strlen( 'BASE' . \EMCP_Tools_Site_Context::DELIMITER );
		$this->assertSame( \EMCP_Tools_Site_Context::MAX_CHARS, $ctx_len );
	}

	/** @test */
	public function is_enabled_defaults_to_true(): void {
		$this->assertTrue( \EMCP_Tools_Site_Context::is_enabled() );
	}

	/** @test */
	public function compose_instructions_reads_the_options(): void {
		$GLOBALS['_wp_options'][ \EMCP_Tools_Site_Context::OPTION_CONTEXT ] = 'Stored guidance.';
		$GLOBALS['_wp_options'][ \EMCP_Tools_Site_Context::OPTION_ENABLED ] = '1';
		$out = \EMCP_Tools_Site_Context::compose_instructions( 'BASE' );
		$this->assertSame( "BASE\n\n## Site context\n\nStored guidance.", $out );
	}

	/** @test */
	public function compose_instructions_respects_the_disabled_toggle(): void {
		$GLOBALS['_wp_options'][ \EMCP_Tools_Site_Context::OPTION_CONTEXT ] = 'Stored guidance.';
		$GLOBALS['_wp_options'][ \EMCP_Tools_Site_Context::OPTION_ENABLED ] = '0';
		$this->assertSame( 'BASE', \EMCP_Tools_Site_Context::compose_instructions( 'BASE' ) );
	}
}
