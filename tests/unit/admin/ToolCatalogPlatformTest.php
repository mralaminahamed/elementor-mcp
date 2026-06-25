<?php
/**
 * Platform partitioning for the Tools-page sub-tabs.
 * @group admin
 * @package EMCP_Tools\Tests\Admin
 */
namespace EMCP_Tools\Tests\Admin;

use PHPUnit\Framework\TestCase;

class ToolCatalogPlatformTest extends TestCase {

	/** @test */
	public function test_platform_tabs_order_and_labels(): void {
		$tabs = \EMCP_Tools_Admin::platform_tabs();
		$this->assertSame( array( 'elementor', 'wordpress' ), array_keys( $tabs ) );
		$this->assertSame( 'Elementor', $tabs['elementor'] );
		$this->assertSame( 'WordPress', $tabs['wordpress'] );
	}

	/** @test */
	public function test_partition_empty_input_returns_two_empty_buckets(): void {
		$buckets = \EMCP_Tools_Admin::partition_by_platform( array() );
		$this->assertSame( array( 'elementor', 'wordpress' ), array_keys( $buckets ) );
		$this->assertSame( array(), $buckets['elementor'] );
		$this->assertSame( array(), $buckets['wordpress'] );
	}

	/** @test */
	public function test_partition_groups_by_platform(): void {
		$cats = array(
			'query'      => array( 'label' => 'Query', 'platform' => 'elementor', 'tools' => array() ),
			'wp_content' => array( 'label' => 'Content', 'platform' => 'wordpress', 'tools' => array() ),
			'seo'        => array( 'label' => 'SEO', 'platform' => 'wordpress', 'tools' => array() ),
			'a11y'       => array( 'label' => 'A11y', 'platform' => 'elementor', 'tools' => array() ),
		);
		$buckets = \EMCP_Tools_Admin::partition_by_platform( $cats );
		$this->assertSame( array( 'elementor', 'wordpress' ), array_keys( $buckets ) );
		$this->assertSame( array( 'query', 'a11y' ), array_keys( $buckets['elementor'] ) );
		$this->assertSame( array( 'wp_content', 'seo' ), array_keys( $buckets['wordpress'] ) );
	}

	/** @test */
	public function test_partition_defaults_unknown_platform_to_elementor(): void {
		$cats = array(
			'no_platform'  => array( 'label' => 'X', 'tools' => array() ),
			'bad_platform' => array( 'label' => 'Y', 'platform' => 'martian', 'tools' => array() ),
			'wp'           => array( 'label' => 'Z', 'platform' => 'wordpress', 'tools' => array() ),
		);
		$buckets = \EMCP_Tools_Admin::partition_by_platform( $cats );
		$this->assertArrayHasKey( 'no_platform', $buckets['elementor'] );
		$this->assertArrayHasKey( 'bad_platform', $buckets['elementor'] );
		$this->assertArrayHasKey( 'wp', $buckets['wordpress'] );
		$this->assertCount( 2, $buckets['elementor'] );
		$this->assertCount( 1, $buckets['wordpress'] );
	}

	/** @test */
	public function test_partition_preserves_category_order_within_bucket(): void {
		$cats = array(
			'a' => array( 'platform' => 'wordpress', 'tools' => array() ),
			'b' => array( 'platform' => 'wordpress', 'tools' => array() ),
			'c' => array( 'platform' => 'wordpress', 'tools' => array() ),
		);
		$buckets = \EMCP_Tools_Admin::partition_by_platform( $cats );
		$this->assertSame( array( 'a', 'b', 'c' ), array_keys( $buckets['wordpress'] ) );
	}
}
