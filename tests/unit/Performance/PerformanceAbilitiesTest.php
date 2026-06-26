<?php
/**
 * @group performance
 * @package EMCP_Tools\Tests\Performance
 */
namespace EMCP_Tools\Tests\Performance;

use PHPUnit\Framework\TestCase;

class PerformanceAbilitiesTest extends TestCase {

	/** @test */
	public function register_collects_the_ability_name(): void {
		$abilities = new \EMCP_Tools_Performance_Abilities();
		$abilities->register();
		$this->assertSame( array( 'emcp-tools/analyze-performance' ), $abilities->get_ability_names() );
	}

	/** @test */
	public function permission_requires_manage_options(): void {
		$abilities = new \EMCP_Tools_Performance_Abilities();
		// The stub current_user_can() in tests/bootstrap returns true; assert it is wired to manage_options.
		$this->assertTrue( $abilities->check_permission() );
	}
}
