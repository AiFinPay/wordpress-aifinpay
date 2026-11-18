<?php

declare(strict_types=1);

use AiFinPay\Core\Request;
use AiFinPay\Core\Resolver;
use AiFinPay\Core\Unproven;
use AiFinPay\Tests\TestCase;
use AiFinPay\WordPress\Admin;
use AiFinPay\WordPress\Maintenance;
use AiFinPay\WordPress\PaidContent;
use AiFinPay\WordPress\ReaderKeys;
use AiFinPay\WordPress\SearchBots;
use AiFinPay\WordPress\Settings;

require_once __DIR__ . '/TestCase.php';

/** "Who reads for free" in a real WordPress; the HTTP of a whole page is in tests/e2e/unproven.mjs. */
final class UnprovenAdapterTest extends TestCase {

	private const MARK   = 'PAID TEXT MARKER';
	private const TS_SECRET = 'tsk_test_secret_value';
	private const CHROME = 'Mozilla/5.0 (Macintosh) Chrome/131.0.0.0 Safari/537.36';

	private array $posts = [];

	protected function setUp(): void {
		parent::setUp();
		delete_option( ReaderKeys::OPTION );
		PaidContent::reset();
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_' . SearchBots::PREFIX ) . '%' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_' . SearchBots::PREFIX ) . '%' ) );
	}

	protected function tearDown(): void {
		foreach ( $this->posts as $id ) {
			wp_delete_post( $id, true );
		}
		PaidContent::reset();
		remove_all_filters( 'aifinpay_dns_resolver' );
		remove_all_filters( 'aifinpay_client_ip' );
		$GLOBALS['post'] = null;
		parent::tearDown();
	}

	private function post( string $slug, string $content, string $type = 'page' ): WP_Post {
		$id            = wp_insert_post( [ 'post_type' => $type, 'post_name' => $slug, 'post_title' => ucfirst( $slug ), 'post_status' => 'publish', 'post_content' => $content, 'post_excerpt' => '' ] );
		$this->posts[] = $id;
		return get_post( $id );
	}

	private function proven( array $patch = [] ): array {
		update_option( ReaderKeys::OPTION, [ 'reader_key' => base64_encode( str_repeat( 'r', 32 ) ), 'turnstile_secret' => self::TS_SECRET ], false );
		return $this->settings( $patch + [ 'mode' => 'enforce', 'protected_paths' => [ '/paid' ], 'free_readers' => 'proven', 'turnstile_sitekey' => '0x4AAAAAAAtest' ] );
	}

	public function testAnInstalledSiteKeepsTodaysBehaviour(): void {
		update_option( Settings::OPTION, [ 'mode' => 'enforce', 'protected_paths' => [ '/paid' ] ] );
		$s = Settings::get();
		self::assertSame( 'named', $s['free_readers'] );
		self::assertSame( 'named', Settings::readers( $s ) );
		self::assertNull( PaidContent::readers( $s ), 'no hold, no buffer, no check' );
		self::assertSame( 'named', $this->settings( [] )['free_readers'], 'a fresh site too' );
		foreach ( [ 'PROVEN', 'free', 1, true, null, [] ] as $bad ) {
			update_option( Settings::OPTION, [ 'free_readers' => $bad ] );
			self::assertSame( 'named', Settings::get()['free_readers'], var_export( $bad, true ) );
		}
	}

	public function testOnlyEnforceChangesWhoReadsFree(): void {
		$s = $this->proven();
		self::assertSame( 'proven', PaidContent::readers( $s ) );
		self::assertNull( PaidContent::readers( $this->settings( [ 'mode' => 'shadow' ] + $s ) ) );
		self::assertSame( 'everyone', PaidContent::readers( $this->settings( [ 'free_readers' => 'everyone' ] + $s ) ) );
	}

	public function testProvenWithoutItsKeysIsEveryoneAndTheSettingsPageSaysWhy(): void {
		$s = $this->settings( [ 'mode' => 'enforce', 'protected_paths' => [ '/paid' ], 'free_readers' => 'proven', 'turnstile_sitekey' => '0x4AAAAAAAtest' ] );
		self::assertSame( 'everyone', Settings::readers( $s ), 'no secret, no reader key' );
		ReaderKeys::ensureReaderKey();
		self::assertSame( 'everyone', Settings::readers( $s ), 'no secret' );
		ReaderKeys::saveTurnstileSecret( self::TS_SECRET );
		self::assertSame( 'proven', Settings::readers( $s ) );
		self::assertSame( 'everyone', Settings::readers( $this->settings( [ 'turnstile_sitekey' => '' ] + $s ) ), 'no site key' );

		$s = $this->settings( [ 'turnstile_sitekey' => '' ] + $s );
		self::assertMatchesRegularExpression( '/needs the Turnstile site key and secret key.*everyone pays/', (string) Admin::readersFallback( $s ) );
		self::assertSame( Admin::readersFallback( $s ), Admin::statusRows( $s )['Who reads for free'] );
		wp_set_current_user( 1 );
		ob_start();
		try {
			Admin::render();
		} finally {
			$html = (string) ob_get_clean();
			wp_set_current_user( 0 );
		}
		self::assertStringContainsString( 'id="aifinpay-readers-fallback"', $html );
		self::assertNull( Admin::readersFallback( $this->proven() ) );
	}

	public function testTheSecretsAreKeptLikeTheApiSecret(): void {
		$s = $this->settings( [ 'free_readers' => 'proven' ] );
		ReaderKeys::ensureReaderKey();
		$key = ReaderKeys::readerKey();
		self::assertSame( 32, strlen( (string) $key ) );
		ReaderKeys::ensureReaderKey();
		self::assertSame( $key, ReaderKeys::readerKey(), 'kept: issued cookies stay valid' );
		ReaderKeys::saveTurnstileSecret( self::TS_SECRET );
		self::assertSame( $key, ReaderKeys::readerKey() );

		wp_load_alloptions( true );
		self::assertArrayNotHasKey( ReaderKeys::OPTION, wp_load_alloptions(), 'not autoloaded' );
		$stored = (string) wp_json_encode( get_option( Settings::OPTION ) );
		self::assertStringNotContainsString( self::TS_SECRET, $stored, 'not among the settings' );
		self::assertStringNotContainsString( base64_encode( (string) $key ), $stored );
		Settings::save( $s + [ 'turnstile_secret' => 'x', 'reader_key' => 'y' ] );
		self::assertArrayNotHasKey( 'turnstile_secret', get_option( Settings::OPTION ) );

		wp_set_current_user( 1 );
		ob_start();
		try {
			Admin::render();
		} finally {
			$html = (string) ob_get_clean();
			wp_set_current_user( 0 );
		}
		self::assertStringNotContainsString( self::TS_SECRET, $html );
		self::assertStringNotContainsString( base64_encode( (string) $key ), $html );
		self::assertStringContainsString( 'name="turnstile_secret"', $html );
		self::assertStringNotContainsString( self::TS_SECRET, (string) wp_json_encode( Admin::statusRows( $s ) ) );

		self::assertContains( ReaderKeys::OPTION, Maintenance::OPTIONS );
		Maintenance::removeSiteData();
		self::assertFalse( get_option( ReaderKeys::OPTION ) );
		\AiFinPay\WordPress\Schema::install();
	}

	public function testPaidTextIsHeldFromAnUnprovenClient(): void {
		$this->proven();
		$paid = $this->post( 'paid', '<p>' . self::MARK . ' page</p>' );
		$free = $this->post( 'free', '<p>FREE TEXT</p>' );
		self::assertTrue( PaidContent::isPaid( $paid ) );
		self::assertFalse( PaidContent::isPaid( $free ) );

		$GLOBALS['post'] = $paid;
		$held            = PaidContent::filterContent( '<p>' . self::MARK . ' page</p>' );
		self::assertStringNotContainsString( self::MARK, $held );
		self::assertStringContainsString( 'data-aifp-paid', $held );
		self::assertSame( '', PaidContent::filterExcerpt( self::MARK . ' excerpt', $paid ) );
		self::assertStringNotContainsString( self::MARK, PaidContent::filterFeed( self::MARK ) );
		$GLOBALS['post'] = $free;
		self::assertSame( '<p>FREE TEXT</p>', PaidContent::filterContent( '<p>FREE TEXT</p>' ) );
		self::assertSame( 'FREE', PaidContent::filterExcerpt( 'FREE', $free ) );

		$rest = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/pages/' . $paid->ID ) );
		$out  = PaidContent::filterRest( $rest, $paid );
		self::assertStringNotContainsString( self::MARK, (string) wp_json_encode( $out->get_data() ) );
		self::assertTrue( $out->get_data()['content']['protected'] );
		$open = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/pages/' . $free->ID ) );
		self::assertStringContainsString( 'FREE TEXT', (string) wp_json_encode( PaidContent::filterRest( $open, $free )->get_data() ) );
	}

	public function testAProvenReaderGetsTheTextMarkedForTheFill(): void {
		$this->proven();
		$paid            = $this->post( 'paid', '<p>' . self::MARK . '</p>' );
		$GLOBALS['post'] = $paid;
		PaidContent::serve();
		self::assertSame( '<div data-aifp-content><p>' . self::MARK . '</p></div>', PaidContent::filterContent( '<p>' . self::MARK . '</p>' ) );
	}

	public function testOnAProtectedPathEveryPostShownIsThePaidPart(): void {
		$this->proven();
		$free = $this->post( 'free', '<p>FREE TEXT</p>' );
		PaidContent::onProtected();
		self::assertTrue( PaidContent::isPaid( $free ) );
	}

	public function testAnExcludedPathIsNotPaid(): void {
		$this->proven( [ 'protected_paths' => [ '/docs/*' ], 'excluded_paths' => [ '/docs/open' ] ] );
		$docs = $this->post( 'docs', 'x' );
		$open = wp_insert_post( [ 'post_type' => 'page', 'post_name' => 'open', 'post_parent' => $docs->ID, 'post_status' => 'publish', 'post_content' => 'y' ] );
		$shut = wp_insert_post( [ 'post_type' => 'page', 'post_name' => 'shut', 'post_parent' => $docs->ID, 'post_status' => 'publish', 'post_content' => 'z' ] );
		array_push( $this->posts, $open, $shut );
		self::assertTrue( PaidContent::isPaid( $docs ) );
		self::assertFalse( PaidContent::isPaid( get_post( $open ) ) );
		self::assertTrue( PaidContent::isPaid( get_post( $shut ) ) );
	}

	public function testTheGuardSendsTheBarePageWhenPaidTextIsStillThere(): void {
		$this->proven();
		$paid            = $this->post( 'paid', '<p>' . self::MARK . '</p>' );
		$GLOBALS['post'] = $paid;
		PaidContent::filterContent( '' );
		self::assertSame( '', PaidContent::guard( '<html><p>' . self::MARK, PHP_OUTPUT_HANDLER_START ), 'nothing leaves before the end' );
		$out = PaidContent::guard( '</p></html>', PHP_OUTPUT_HANDLER_FINAL );
		self::assertStringNotContainsString( self::MARK, $out );
		self::assertStringContainsString( 'id="aifp-402"', $out );
		self::assertStringContainsString( '<meta name="robots" content="noindex">', $out );
		self::assertSame( '<html><p>no paid text</p></html>', PaidContent::guard( '<html><p>no paid text</p></html>', PHP_OUTPUT_HANDLER_FINAL ) );
	}

	public function testAHeldPageWithoutItsHolderOrTermsIsSentBare(): void {
		$this->proven();
		PaidContent::hold( [ 'error' => 'AIFP-402', 'resource' => '/paid' ] );
		$theme = '<html><body><h1>Paid</h1><div data-aifp-paid></div>';
		self::assertSame( $theme . '<script type="application/json" id="aifp-402">{}</script></body></html>', PaidContent::guard( $theme . '<script type="application/json" id="aifp-402">{}</script></body></html>', PHP_OUTPUT_HANDLER_FINAL ) );
		self::assertStringStartsWith( '<!doctype html>', PaidContent::guard( $theme . '</body></html>', PHP_OUTPUT_HANDLER_FINAL ), 'no wp_footer: no terms' );
		self::assertStringStartsWith( '<!doctype html>', PaidContent::guard( '<html><h1>Paid</h1><script type="application/json" id="aifp-402">{}</script></html>', PHP_OUTPUT_HANDLER_FINAL ), 'the theme never called the_content' );
		$bare = PaidContent::guard( '', PHP_OUTPUT_HANDLER_FINAL );
		self::assertStringContainsString( '"resource":"/paid"', $bare, 'the terms of this path' );
	}

	public function testTheCheckEndpointSetsTheCookieOnlyForThisHost(): void {
		$this->proven();
		add_filter( 'aifinpay_client_ip', static fn () => '203.0.113.9' );
		$host            = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$verdict         = [ 'success' => true, 'hostname' => $host ];
		$this->responder = static function ( string $method, string $url ) use ( &$verdict ) {
			return 'https://challenges.cloudflare.com/turnstile/v0/siteverify' === $url ? [ 200, $verdict ] : 500;
		};
		$request = static fn ( string $method = 'POST' ): Request => new Request( $method, $host, '/__aifp/human', [ 'user-agent' => self::CHROME ] );

		$ok = PaidContent::check( $request(), '{"token":"tok"}' );
		self::assertSame( 204, $ok['status'] );
		self::assertMatchesRegularExpression( '/^__aifp_human=\d+\.[A-Za-z0-9_-]{43}; Path=\/; Max-Age=2592000; HttpOnly; Secure; SameSite=Lax$/D', $ok['headers']['Set-Cookie'] );
		$value = explode( ';', substr( $ok['headers']['Set-Cookie'], 13 ) )[0];
		self::assertTrue( ReaderKeys::proof()->verify( '__aifp_human=' . $value, self::CHROME, time() ) );
		self::assertSame( [ 'secret' => self::TS_SECRET, 'response' => 'tok', 'remoteip' => '203.0.113.9' ], $this->calls[0]['body'] );
		self::assertSame( 'POST', $this->calls[0]['method'] );

		$verdict = [ 'success' => true, 'hostname' => 'elsewhere.test' ];
		self::assertSame( 403, PaidContent::check( $request(), '{"token":"tok"}' )['status'] );
		$verdict = [ 'success' => false, 'hostname' => $host ];
		self::assertSame( 403, PaidContent::check( $request(), '{"token":"tok"}' )['status'] );
		$this->responder = static fn () => new WP_Error( 'down', 'down' );
		self::assertSame( 403, PaidContent::check( $request(), '{"token":"tok"}' )['status'] );
		self::assertSame( 405, PaidContent::check( $request( 'GET' ), '' )['status'] );
		self::assertSame( 400, PaidContent::check( $request(), 'nope' )['status'] );
		delete_option( ReaderKeys::OPTION );
		self::assertSame( 403, PaidContent::check( $request(), '{"token":"tok"}' )['status'], 'no keys, no cookie' );
	}

	public function testASearchBotIsAskedOnceAndRemembered(): void {
		$dns = new class() implements Resolver {
			public int $asked = 0;
			public bool $fail = false;
			public function reverse( string $ip ): array {
				++$this->asked;
				if ( $this->fail ) {
					throw new RuntimeException( 'down' );
				}
				return [ 'crawl.googlebot.com' ];
			}
			public function forward( string $name, int $family ): array {
				return [ '66.249.66.1' ];
			}
		};
		add_filter( 'aifinpay_dns_resolver', static fn () => $dns );
		$ua = 'Mozilla/5.0 (compatible; Googlebot/2.1)';
		self::assertSame( 'search', SearchBots::category( $ua, '66.249.66.1' ) );
		self::assertSame( 'search', SearchBots::category( $ua, '66.249.66.1' ) );
		self::assertSame( 1, $dns->asked );
		$dns->fail = true;
		self::assertNull( SearchBots::category( $ua, '66.249.66.2' ), 'an error is not free' );
		self::assertNull( SearchBots::category( $ua, '66.249.66.2' ) );
		self::assertSame( 2, $dns->asked, 'an error is kept for a minute too' );
		self::assertNull( SearchBots::category( self::CHROME, '66.249.66.1' ) );
		self::assertSame( 2, $dns->asked, 'no claim, no lookup' );
		global $wpdb;
		$timeouts = $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_' . SearchBots::PREFIX ) . '%' ) );
		$ttls     = array_map( static fn ( $t ): int => (int) $t - time(), $timeouts );
		sort( $ttls );
		self::assertEqualsWithDelta( 60, $ttls[0], 2 );
		self::assertEqualsWithDelta( 86400, $ttls[1], 2 );
	}

	public function testUnprovenIsTheDefaultOutcome(): void {
		$s       = $this->proven();
		$request = new Request( 'GET', 'site.test', '/paid', [ 'user-agent' => self::CHROME, 'accept' => 'text/html' ] );
		self::assertSame( 'unproven', PaidContent::kind( $request, Settings::readers( $s ) ) );
		self::assertTrue( Unproven::holdable( $request, 'unproven', true, Settings::readers( $s ) ) );
	}
}
