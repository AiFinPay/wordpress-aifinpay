<?php

declare(strict_types=1);

use AiFinPay\Core\Json;
use AiFinPay\Core\ReceiptGate;
use AiFinPay\Tests\TestCase;
use AiFinPay\WordPress\Admin;
use AiFinPay\WordPress\PageAbout;
use AiFinPay\WordPress\PaidContent;
use AiFinPay\WordPress\ReaderKeys;
use AiFinPay\WordPress\Settings;

require_once __DIR__ . '/TestCase.php';

/** "Tell agents what a page is" in a real WordPress; the HTTP of it is in tests/e2e. */
final class PageAboutTest extends TestCase {

	private const MARK = 'PAID TEXT MARKER';
	private const BODY = '<p>' . self::MARK . ' herons nest in tall trees along slow rivers and hunt fish at dawn while kingfishers dive from low branches.</p>';

	private array $posts = [];

	protected function setUp(): void {
		parent::setUp();
		PaidContent::reset();
	}

	protected function tearDown(): void {
		foreach ( $this->posts as $id ) {
			wp_delete_post( $id, true );
		}
		PaidContent::reset();
		remove_all_filters( PageAbout::FILTER );
		delete_option( ReaderKeys::OPTION );
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'] = new WP_Query();
		$GLOBALS['post']         = null;
		parent::tearDown();
	}

	private function post( string $slug, string $title, string $excerpt, string $content = self::BODY, array $more = [] ): WP_Post {
		$id            = wp_insert_post( $more + [ 'post_type' => 'post', 'post_name' => $slug, 'post_title' => $title, 'post_status' => 'publish', 'post_content' => $content, 'post_excerpt' => $excerpt ] );
		$this->posts[] = $id;
		return get_post( $id );
	}

	/** The request shows this post on its own. */
	private function show( WP_Post $post ): void {
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'] = new WP_Query( [ 'p' => $post->ID ] );
		$GLOBALS['post']         = $post;
	}

	public function testAFreshSiteSaysTitleAndSummaryAndAnInstalledSiteSaysNothing(): void {
		self::assertSame( 'title_summary', Settings::get()['about_pages'], 'nothing stored yet' );
		self::assertSame( 'title_summary', $this->settings( [] )['about_pages'], 'saved by activation' );
		update_option( Settings::OPTION, [ 'mode' => 'enforce', 'protected_paths' => [ '/paid' ], 'free_readers' => 'proven' ] );
		self::assertSame( 'off', Settings::get()['about_pages'], 'saved before the setting existed: the 402 it sent' );
		foreach ( [ 'TITLE', 'summary', 1, true, null, [] ] as $bad ) {
			update_option( Settings::OPTION, [ 'about_pages' => $bad ] );
			self::assertSame( 'off', Settings::get()['about_pages'], var_export( $bad, true ) );
		}
	}

	public function testANewSiteSaysTheTitleAndTheExcerptTypedForThePost(): void {
		$s    = $this->settings( [] );
		$post = $this->post( 'herons', 'Herons &amp; <em>rivers</em>', 'A field guide to <b>herons</b>.' );
		$this->show( $post );
		self::assertSame( [ 'title' => 'Herons & rivers', 'description' => 'A field guide to herons.' ], PageAbout::current( $s ) );
		self::assertSame( [ 'title' => 'Herons & rivers' ], PageAbout::current( $this->settings( [ 'about_pages' => 'title' ] ) ) );
		self::assertNull( PageAbout::current( $this->settings( [ 'about_pages' => 'off' ] ) ) );
	}

	public function testWithoutAManualExcerptThereIsNoDescriptionThoughWordPressWouldMakeOne(): void {
		$s    = $this->settings( [] );
		$post = $this->post( 'no-excerpt', 'Herons', '' );
		self::assertStringContainsString( self::MARK, get_the_excerpt( $post ), 'WordPress cuts an excerpt from the text' );
		self::assertSame( '', PageAbout::summary( $post ) );
		$this->show( $post );
		$about = PageAbout::current( $s );
		self::assertSame( [ 'title' => 'Herons' ], $about );
		self::assertStringNotContainsString( self::MARK, (string) wp_json_encode( $about ) );
		self::assertStringNotContainsString( 'herons nest', strtolower( (string) wp_json_encode( $about ) ) );
	}

	public function testAnExcerptThatRepeatsThePaidTextIsNotSaid(): void {
		$s    = $this->settings( [] );
		$post = $this->post( 'copied', 'Herons', 'Herons nest in tall trees along slow rivers and hunt fish.' );
		$this->show( $post );
		self::assertSame( [ 'title' => 'Herons' ], PageAbout::current( $s ) );
		self::assertFalse( PageAbout::publishes( $post ) );
		$short = $this->post( 'short', 'Short', 'Kingfishers, in short.', '<p>Kingfishers</p>' );
		$this->show( $short );
		self::assertSame( [ 'title' => 'Short' ], PageAbout::current( $s ), 'a text shorter than a run is not said whole either' );
	}

	public function testAPasswordProtectedPostHasNoDescription(): void {
		$post = $this->post( 'locked', 'Locked', 'Typed, but behind a password.', self::BODY, [ 'post_password' => 'pw' ] );
		$this->show( $post );
		self::assertSame( [ 'title' => 'Locked' ], PageAbout::current( $this->settings( [] ) ) );
	}

	public function testAnArchiveOrTheRestApiSaysOnlyWhatTheSiteSupplies(): void {
		$s = $this->settings( [] );
		$this->post( 'one', 'One', 'Typed.' );
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'] = new WP_Query( [ 'post_type' => 'post' ] );
		self::assertNull( PageAbout::page() );
		self::assertNull( PageAbout::current( $s ) );
		add_filter( PageAbout::FILTER, static fn ( $about, $post ) => null === $post ? [ 'title' => 'All <b>posts</b>', 'description' => str_repeat( 'x', 400 ) ] : $about, 10, 2 );
		$about = PageAbout::current( $s );
		self::assertSame( 'All posts', $about['title'] );
		self::assertSame( 300, strlen( $about['description'] ) );
		remove_all_filters( PageAbout::FILTER );
		add_filter( PageAbout::FILTER, '__return_false' );
		self::assertNull( PageAbout::current( $s ) );
		remove_all_filters( PageAbout::FILTER );
		add_filter( PageAbout::FILTER, static fn () => [ 'title' => 'Fine', 'description' => [ 'not text' ] ] );
		self::assertNull( PageAbout::current( $s ), 'a wrong shape drops the whole object' );
		self::assertNull( PageAbout::current( $this->settings( [ 'about_pages' => 'off' ] + $s ) ), 'off whatever the filter says' );
	}

	public function testOnlyThe402GetsItAndOtherwiseItIsTheSameBody(): void {
		$s    = $this->settings( [] );
		$post = $this->post( 'herons', 'Herons', 'A field guide.' );
		$this->show( $post );
		$body = ReceiptGate::challenge( self::MERCHANT, '/paid', 'standard', 'https://api.aifinpay.io' );
		$with = PageAbout::add( $body, $s );
		self::assertSame( [ 'title' => 'Herons', 'description' => 'A field guide.' ], $with['about'] );
		unset( $with['about'] );
		self::assertSame( Json::encode( $body ), Json::encode( $with ), 'price, scope, quote terms: unchanged' );
		self::assertSame( $body, PageAbout::add( $body, $this->settings( [ 'about_pages' => 'off' ] ) ) );
		foreach ( [ null, [ 'error' => 'AIFP-403', 'detail' => 'x' ], [ 'error' => 'enforcement_not_approved' ] ] as $other ) {
			self::assertSame( $other, PageAbout::add( $other, $s ) );
		}
		add_filter( PageAbout::FILTER, static function () {
			throw new RuntimeException( 'a broken filter' );
		} );
		self::assertSame( $body, PageAbout::add( $body, $s ), 'information never breaks the 402' );
	}

	public function testTheHeldPageCarriesItAndItsOwnExcerptDoesNotMakeItBare(): void {
		update_option( ReaderKeys::OPTION, [ 'reader_key' => base64_encode( str_repeat( 'r', 32 ) ), 'turnstile_secret' => 'tsk_test_secret_value' ], false );
		$this->settings( [ 'mode' => 'enforce', 'protected_paths' => [ '/herons' ], 'free_readers' => 'proven', 'turnstile_sitekey' => '0x4AAAAAAAtest' ] );
		$post = $this->post( 'herons', 'Herons', 'A field guide, typed by the owner.' );
		$this->show( $post );
		self::assertTrue( PageAbout::publishes( $post ) );
		PaidContent::filterContent( self::BODY );
		$terms = PageAbout::add( ReceiptGate::challenge( self::MERCHANT, '/herons', 'standard', 'https://api.aifinpay.io' ), Settings::get() );
		PaidContent::hold( $terms );
		$page = '<html><body><h1>Herons</h1><div data-aifp-paid></div><script type="application/json" id="aifp-402">' . Json::encode( $terms ) . '</script></body></html>';
		self::assertSame( $page, PaidContent::guard( $page, PHP_OUTPUT_HANDLER_FINAL ), 'the description is what the owner chose to say' );

		PaidContent::reset();
		$this->show( $post );
		PaidContent::filterContent( self::BODY );
		$bare = PaidContent::guard( '<html>' . self::BODY . '</html>', PHP_OUTPUT_HANDLER_FINAL );
		self::assertStringStartsWith( '<!doctype html>', $bare );
		self::assertStringNotContainsString( self::MARK, $bare );
		self::assertStringContainsString( '"about":{"title":"Herons","description":"A field guide, typed by the owner."}', $bare, 'the bare page says it too' );

		PaidContent::reset();
		$this->settings( [ 'about_pages' => 'off' ] + Settings::get() );
		$this->show( $post );
		self::assertFalse( PageAbout::publishes( $post ) );
		PaidContent::filterContent( self::BODY );
		self::assertStringStartsWith( '<!doctype html>', PaidContent::guard( $page, PHP_OUTPUT_HANDLER_FINAL ), 'off: the excerpt is paid text again' );
	}

	public function testTheSettingsScreenNamesTheModesAndTheNewSetting(): void {
		self::assertSame( [ 'AI agents only', 'Strict', 'Everyone pays' ], array_column( Admin::readersLabels(), 0 ) );
		self::assertSame( [ 'named', 'proven', 'everyone' ], array_keys( Admin::readersLabels() ) );
		$s = $this->settings( [ 'free_readers' => 'everyone', 'about_pages' => 'title' ] );
		self::assertSame( 'Everyone pays: Every request without a receipt pays. For APIs, catalogs and data feeds.', Admin::statusRows( $s )['Who reads for free'] );
		wp_set_current_user( 1 );
		ob_start();
		try {
			Admin::render();
		} finally {
			$html = (string) ob_get_clean();
			wp_set_current_user( 0 );
		}
		foreach ( [ 'AI agents only', 'Strict', 'Everyone pays', 'Title and summary' ] as $name ) {
			self::assertStringContainsString( '<strong>' . $name . '</strong>', $html );
		}
		self::assertStringContainsString( 'Tell agents what a page is', $html );
		self::assertMatchesRegularExpression( '/name="about_pages" value="title"\s+checked/', $html );
		self::assertMatchesRegularExpression( '/name="free_readers" value="everyone"\s+checked/', $html );
	}
}
