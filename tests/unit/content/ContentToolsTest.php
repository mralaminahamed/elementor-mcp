<?php
/**
 * Execute-path + validation tests for the WordPress Content tools.
 * @group content
 * @package EMCP_Tools\Tests\Content
 */
namespace EMCP_Tools\Tests\Content;

require_once dirname( __DIR__ ) . '/class-ability-test-case.php';

use EMCP_Tools\Tests\Ability_Test_Case;

class ContentToolsTest extends Ability_Test_Case {
	private \EMCP_Tools_Content_Abilities $ability;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_trashed_posts']     = array();
		$GLOBALS['_wp_term_calls']        = array();
		$GLOBALS['_wp_thumbnail_calls']   = array();
		$GLOBALS['_wp_current_user_id']   = 1;
		$GLOBALS['_wp_post_type_objects'] = array(
			'post' => (object) array( 'name' => 'post', 'label' => 'Posts', 'hierarchical' => false, 'public' => true, '_builtin' => true ),
			'page' => (object) array( 'name' => 'page', 'label' => 'Pages', 'hierarchical' => true, 'public' => true, '_builtin' => true ),
		);
		$GLOBALS['_wp_taxonomy_objects'] = array(
			'category' => (object) array( 'name' => 'category', 'label' => 'Categories', 'hierarchical' => true, 'object_type' => array( 'post' ) ),
			'post_tag' => (object) array( 'name' => 'post_tag', 'label' => 'Tags', 'hierarchical' => false, 'object_type' => array( 'post' ) ),
		);
		$this->ability = new \EMCP_Tools_Content_Abilities();
		$this->ability->register();
	}

	/** @test */
	public function test_registers_discovery_tools(): void {
		$names = $this->ability->get_ability_names();
		$this->assertContains( 'emcp-tools/list-post-types', $names );
		$this->assertContains( 'emcp-tools/list-taxonomies', $names );
		$this->assertNotContains( 'emcp-tools/create-post', $names, 'not registered until Task 2' );
	}

	/** @test */
	public function test_list_post_types_returns_public_types(): void {
		$out = $this->ability->execute_list_post_types( array() );
		$this->assertResultHasKey( $out, 'post_types' );
		$names = array_column( $out['post_types'], 'name' );
		$this->assertContains( 'post', $names );
		$this->assertContains( 'page', $names );
	}

	/** @test */
	public function test_list_taxonomies_returns_category(): void {
		$out = $this->ability->execute_list_taxonomies( array() );
		$this->assertResultHasKey( $out, 'taxonomies' );
		$this->assertContains( 'category', array_column( $out['taxonomies'], 'name' ) );
	}
}
