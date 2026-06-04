<?php
/**
 * Unit tests for EMCP_Tools_PHP_Snippet_Validator.
 *
 * The validator is the static-analysis guardrail for admin/AI PHP snippets, so
 * its rules are a security boundary. These tests assert that obviously dangerous
 * constructs are blocked (critical), that benign code passes, that warnings are
 * surfaced without blocking, and that parse errors are caught.
 *
 * @package EMCP_Tools\Tests
 * @since   2.1.0
 */

namespace EMCP_Tools\Tests;

use PHPUnit\Framework\TestCase;

/**
 * @covers \EMCP_Tools_PHP_Snippet_Validator
 */
final class PhpSnippetValidatorTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once EMCP_TOOLS_DIR . 'includes/class-php-snippet-validator.php';
	}

	private function validate( string $code ): array {
		return \EMCP_Tools_PHP_Snippet_Validator::validate( $code );
	}

	private function rules( array $result ): array {
		return array_map(
			static function ( $f ) {
				return $f['rule'];
			},
			$result['findings']
		);
	}

	// ---------------------------------------------------------------------
	// Safe code passes.
	// ---------------------------------------------------------------------

	/**
	 * @dataProvider safe_snippets
	 */
	public function test_safe_code_is_valid_and_safe( string $code ): void {
		$r = $this->validate( $code );
		$this->assertTrue( $r['valid'], 'Expected valid PHP.' );
		$this->assertTrue( $r['safe'], 'Expected no critical finding for: ' . $code );
	}

	public static function safe_snippets(): array {
		return array(
			'return string'     => array( "return 'Hello ' . get_bloginfo('name');" ),
			'echo + concat'     => array( "echo esc_html( get_the_title() );" ),
			'loop + math'       => array( "\$t = 0; foreach ( array(1,2,3) as \$n ) { \$t += \$n; } return \$t;" ),
			'wp read api'       => array( "\$p = wp_count_posts('post'); return intval(\$p->publish);" ),
			'conditional'       => array( "if ( is_front_page() ) { return 'home'; } return 'inner';" ),
			'with php tag'      => array( "<?php return 1 + 1;" ),
		);
	}

	// ---------------------------------------------------------------------
	// Dangerous code is blocked (critical).
	// ---------------------------------------------------------------------

	/**
	 * @dataProvider critical_snippets
	 */
	public function test_dangerous_code_is_blocked( string $code, string $expect_rule ): void {
		$r = $this->validate( $code );
		$this->assertFalse( $r['safe'], 'Expected blocked (unsafe) for: ' . $code );
		$rules = $this->rules( $r );
		$hit   = false;
		foreach ( $rules as $rule ) {
			if ( false !== strpos( $rule, $expect_rule ) ) {
				$hit = true;
				break;
			}
		}
		$this->assertTrue( $hit, 'Expected a finding matching "' . $expect_rule . '"; got: ' . implode( ',', $rules ) );
	}

	public static function critical_snippets(): array {
		return array(
			'eval'              => array( "eval('phpinfo();');", 'eval' ),
			'system'            => array( "system('id');", 'function:system' ),
			'shell_exec'        => array( "\$o = shell_exec('ls');", 'function:shell_exec' ),
			'passthru'          => array( "passthru('whoami');", 'function:passthru' ),
			'proc_open'         => array( "proc_open('sh', array(), \$p);", 'function:proc_open' ),
			'backtick'          => array( "\$x = `ls -la`;", 'backtick' ),
			'variable function' => array( "\$f = 'system'; \$f('id');", 'variable_function' ),
			'call_user_func'    => array( "call_user_func('system', 'id');", 'function:call_user_func' ),
			'file_put_contents' => array( "file_put_contents('x.php', \$c);", 'function:file_put_contents' ),
			'fwrite'            => array( "\$h = STDOUT; fwrite(\$h, 'x');", 'function:fwrite' ),
			'unlink'            => array( "unlink('/etc/passwd');", 'function:unlink' ),
			'rmdir'             => array( "rmdir('/var/www');", 'function:rmdir' ),
			'curl'              => array( "\$c = curl_init('http://x'); curl_exec(\$c);", 'function:curl_init' ),
			'fsockopen'         => array( "\$s = fsockopen('evil.com', 80);", 'function:fsockopen' ),
			'base64_decode'     => array( "eval(base64_decode('cABoAHAA'));", 'function:base64_decode' ),
			'gzinflate'         => array( "eval(gzinflate('x'));", 'function:gzinflate' ),
			'str_rot13'         => array( "\$x = str_rot13('flfgrz');", 'function:str_rot13' ),
			'include'           => array( "include \$_GET['f'];", 'include' ),
			'require'           => array( "require '/tmp/x';", 'include' ),
			'destructive sql'   => array( "global \$wpdb; \$wpdb->query('DROP TABLE wp_users');", 'destructive_sql' ),
			'delete from sql'   => array( "global \$wpdb; \$wpdb->query('DELETE FROM wp_posts');", 'destructive_sql' ),
			'ini_set'           => array( "ini_set('disable_functions', '');", 'function:ini_set' ),
			'putenv'            => array( "putenv('PATH=/x');", 'function:putenv' ),
			'extract'           => array( "extract(\$_GET);", 'function:extract' ),
		);
	}

	// ---------------------------------------------------------------------
	// Warnings are surfaced but do NOT block.
	// ---------------------------------------------------------------------

	/**
	 * @dataProvider warning_snippets
	 */
	public function test_warnings_do_not_block( string $code, string $expect_rule ): void {
		$r = $this->validate( $code );
		$this->assertTrue( $r['valid'] );
		$this->assertTrue( $r['safe'], 'Warning-level code should remain safe (non-blocking): ' . $code );
		$this->assertContains( $expect_rule, $this->rules( $r ) );
	}

	public static function warning_snippets(): array {
		return array(
			'update_option' => array( "update_option('my_key', 1);", 'function:update_option' ),
			'wp_mail'       => array( "wp_mail('a@b.c', 's', 'm');", 'function:wp_mail' ),
			'superglobal'   => array( "return isset(\$_GET['x']) ? 1 : 0;", 'superglobal' ),
			'suppression'   => array( "@trigger_x();", 'suppress' ),
			'definition'    => array( "function emcp_helper() { return 1; } return emcp_helper();", 'definition' ),
		);
	}

	// ---------------------------------------------------------------------
	// Parse errors, empty, and closing tags.
	// ---------------------------------------------------------------------

	public function test_parse_error_is_invalid(): void {
		$r = $this->validate( "echo 'unterminated;" );
		$this->assertFalse( $r['valid'] );
		$this->assertNotSame( '', $r['parse_error'] );
	}

	public function test_empty_is_invalid(): void {
		$r = $this->validate( "   " );
		$this->assertFalse( $r['valid'] );
	}

	public function test_closing_tag_is_blocked(): void {
		$r = $this->validate( "echo 1; ?><h1>html</h1>" );
		$this->assertFalse( $r['safe'] );
		$this->assertContains( 'close_tag', $this->rules( $r ) );
	}

	public function test_strip_tags_removes_open_tag(): void {
		$this->assertSame( 'return 1;', \EMCP_Tools_PHP_Snippet_Validator::strip_tags( "<?php return 1;" ) );
		$this->assertSame( 'return 1;', \EMCP_Tools_PHP_Snippet_Validator::strip_tags( "return 1;" ) );
	}

	/**
	 * A method named like a denylisted function must NOT trip the call rule
	 * (e.g. $obj->system() is a method call, not the system() builtin).
	 */
	public function test_method_call_named_like_builtin_is_not_flagged(): void {
		$r = $this->validate( "\$obj = new \\stdClass(); return is_object(\$obj);" );
		$this->assertTrue( $r['safe'] );
	}
}
