<?php

declare(strict_types=1);

namespace AiFinPay\WordPress;

defined( 'ABSPATH' ) || exit;

use AiFinPay\Core\HumanCheck;
use AiFinPay\Core\Json;
use AiFinPay\Core\PaidText;
use AiFinPay\Core\Paths;
use AiFinPay\Core\ReceiptGate;
use AiFinPay\Core\Request;
use AiFinPay\Core\Unproven;

/**
 * "Who reads for free" set to proven or everyone, in Enforce: the text of a paid post is not rendered
 * for a client that has not proven it may read it, on the page, in excerpts, feeds and the REST API.
 */
final class PaidContent {

	public const TURNSTILE = 'https://challenges.cloudflare.com/turnstile/v0/api.js?onload=aifpReady&render=explicit';

	private static ?string $kind      = null;
	private static ?string $readers   = null;
	private static bool $full         = false;
	private static bool $protected    = false;
	private static ?array $terms      = null;
	private static bool $held         = false;
	private static bool $quiet        = false;
	private static int $holders       = 0;
	/** @var array<int, ?array{rule: string, path: string}> */
	private static array $paid        = [];
	/** @var string[] */
	private static array $texts       = [];
	private static ?array $firstPaid  = null;

	/** The effective setting when it changes who reads free, else null: Shadow, Off and `named` are left as they were. */
	public static function readers( array $settings ): ?string {
		if ( 'enforce' !== $settings['mode'] ) {
			return null;
		}
		$readers = Settings::readers( $settings );
		return Unproven::NAMED === $readers ? null : $readers;
	}

	/** Behind a proxy the site must hand WordPress the client's address, or no search engine is verified and all of them pay. */
	public static function clientIp(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated as an IP address before any use.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		return (string) apply_filters( 'aifinpay_client_ip', $ip );
	}

	public static function kind( Request $request, string $readers ): string {
		if ( null === self::$kind ) {
			$proven     = Unproven::PROVEN === $readers;
			$ip         = self::clientIp();
			self::$kind = Unproven::classify(
				$request,
				$proven ? ReaderKeys::proof() : null,
				static fn ( string $ua ): ?string => $proven ? SearchBots::category( $ua, $ip ) : null,
				time()
			);
		}
		return self::$kind;
	}

	/** The request is on a protected path: every post it shows is the paid part. */
	public static function onProtected(): void {
		self::$protected = true;
	}

	/** This client may have the whole text. */
	public static function serve(): void {
		self::$full = true;
	}

	/** The page itself as the 402, with these terms. */
	public static function hold( array $terms ): void {
		self::$terms = $terms;
		self::$held  = true;
	}

	/** Pages, archives and feeds: after the gate, before the template. */
	public static function page( Request $request, array $settings ): void {
		$readers = self::readers( $settings );
		if ( null === $readers ) {
			return;
		}
		self::$readers = $readers;
		if ( ! self::$full && in_array( self::kind( $request, $readers ), Unproven::FREE_KINDS, true ) ) {
			self::$full = true;
		}
		add_filter( 'the_content', [ self::class, 'filterContent' ], PHP_INT_MAX );
		if ( self::$full ) {
			ob_start( [ self::class, 'passThrough' ] );
			return;
		}
		add_filter( 'get_the_excerpt', [ self::class, 'filterExcerpt' ], PHP_INT_MAX, 2 );
		add_filter( 'the_content_feed', [ self::class, 'filterFeed' ], PHP_INT_MAX );
		foreach ( $GLOBALS['wp_query']->posts ?? [] as $post ) {
			if ( $post instanceof \WP_Post && self::isPaid( $post ) ) {
				self::remember( $post );
			}
		}
		if ( self::$held ) {
			header( 'X-Robots-Tag: noindex' );
			add_filter( 'wp_robots', [ self::class, 'noindex' ], PHP_INT_MAX );
			self::withoutCopies();
		}
		self::$quiet = ! self::$held && ! is_feed() && Unproven::quiet( $request, self::kind( $request, $readers ), $readers );
		if ( self::$held || self::$quiet ) {
			add_action( 'wp_footer', [ self::class, 'footer' ], PHP_INT_MAX );
		}
		ob_start( [ self::class, 'guard' ] );
	}

	/** REST routes that are not themselves protected: a paid post is listed without its text, or not stored when it carries it. */
	public static function rest( Request $request, array $settings ): void {
		$readers = self::readers( $settings );
		if ( null === $readers ) {
			return;
		}
		if ( in_array( self::kind( $request, $readers ), Unproven::FREE_KINDS, true ) ) {
			self::$full = true;
		}
		foreach ( get_post_types( [ 'show_in_rest' => true ] ) as $type ) {
			add_filter( 'rest_prepare_' . $type, [ self::class, 'filterRest' ], PHP_INT_MAX, 2 );
		}
	}

	/** Paid when on a protected path, or when the post's own address is protected. */
	public static function isPaid( \WP_Post $post ): bool {
		if ( self::$protected ) {
			return true;
		}
		if ( ! array_key_exists( $post->ID, self::$paid ) ) {
			$settings = Settings::get();
			$path     = Paths::inspect( (string) wp_parse_url( (string) get_permalink( $post ), PHP_URL_PATH ) )['path'];
			$own      = array_values( array_filter( $settings['protected_paths'], static fn ( string $p ): bool => ! str_starts_with( $p, Settings::restPath() . '/' ) ) );
			$home     = Settings::homePath();
			self::$paid[ $post->ID ] = Paths::resolve( [ $path ], $own, [ ...$settings['excluded_paths'], $home . '/.well-known/*' ], Settings::tiers( $settings ) );
		}
		return null !== self::$paid[ $post->ID ];
	}

	/** @internal */
	public static function filterContent( $content ) {
		$post = get_post();
		if ( ! $post instanceof \WP_Post || ! self::isPaid( $post ) ) {
			return $content;
		}
		if ( self::$full ) {
			// A page carrying the text must never be stored for the next client.
			CacheCompat::forbid();
			return is_feed() ? $content : '<div data-aifp-content>' . $content . '</div>';
		}
		self::remember( $post );
		return is_feed() ? '<p>' . esc_html__( 'This post is paid for automated access.', 'aifinpay' ) . '</p>' : self::holder();
	}

	/** @internal */
	public static function filterFeed( $content ) {
		$post = get_post();
		return $post instanceof \WP_Post && self::isPaid( $post ) ? '<p>' . esc_html__( 'This post is paid for automated access.', 'aifinpay' ) . '</p>' : $content;
	}

	/** @internal */
	public static function filterExcerpt( $excerpt, $post = null ) {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post || ! self::isPaid( $post ) ) {
			return $excerpt;
		}
		self::remember( $post );
		return '';
	}

	/** @internal */
	public static function filterRest( $response, $post ) {
		if ( ! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post || ! self::isPaid( $post ) ) {
			return $response;
		}
		if ( self::$full ) {
			CacheCompat::forbid();
			return $response;
		}
		$data = $response->get_data();
		foreach ( [ 'content', 'excerpt' ] as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$data[ $field ] = [ 'rendered' => '', 'protected' => true ];
			}
		}
		$response->set_data( $data );
		return $response;
	}

	/** @internal */
	public static function noindex( array $robots ): array {
		return [ 'noindex' => true ] + $robots;
	}

	/** @internal */
	public static function footer(): void {
		if ( self::$held ) {
			echo self::termsTag(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with < escaped.
		} else {
			echo '<div id="aifp-w" hidden></div>';
		}
		echo self::script(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
	}

	/**
	 * Holds the whole response until it is complete: a page that still carries paid text, or a held page
	 * whose holder or terms are missing, is replaced by the bare 402 page.
	 *
	 * @internal
	 */
	public static function guard( string $buffer, int $phase ): string {
		static $out = '';
		$out .= $buffer;
		if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
			return '';
		}
		$page = $out;
		$out  = '';
		$sure = ! PaidText::leaks( $page, self::$texts )
			&& ( ! self::$held || ( str_contains( $page, '<div data-aifp-paid>' ) && str_contains( $page, '<script type="application/json" id="aifp-402">' ) ) );
		if ( $sure ) {
			return $page;
		}
		if ( ! headers_sent() ) {
			http_response_code( 402 );
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'X-Robots-Tag: noindex' );
			CacheCompat::headers();
		}
		return self::barePage();
	}

	/** Holds the output so a paid post found late can still keep the page out of caches. @internal */
	public static function passThrough( string $buffer, int $phase ): string {
		static $out = '';
		$out .= $buffer;
		if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
			return '';
		}
		$page = $out;
		$out  = '';
		return $page;
	}

	/** POST /__aifp/human, only while proven is in force. */
	public static function onParseRequest(): void {
		$settings = Settings::get();
		$request  = FrontGate::currentRequest();
		if ( Unproven::PROVEN !== self::readers( $settings ) || Settings::homePath() . HumanCheck::PATH !== $request->path ) {
			return;
		}
		$body   = 'POST' === $request->method ? (string) file_get_contents( 'php://input', false, null, 0, 4096 ) : '';
		$answer = self::check( $request, $body );
		status_header( $answer['status'] );
		foreach ( $answer['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}
		exit;
	}

	/** @return array{status: int, headers: array<string, string>} */
	public static function check( Request $request, string $body ): array {
		$proof  = ReaderKeys::proof();
		$secret = ReaderKeys::turnstileSecret();
		if ( null === $proof || '' === $secret ) {
			return [ 'status' => 403, 'headers' => [ 'Cache-Control' => 'private, no-store, max-age=0' ] ];
		}
		$siteverify = static function ( string $token, string $ip ) use ( $secret ): ?array {
			$fields = [ 'secret' => $secret, 'response' => $token ];
			if ( '' !== $ip ) {
				$fields['remoteip'] = $ip;
			}
			$response = wp_remote_post( HumanCheck::SITEVERIFY, [ 'body' => $fields, 'timeout' => 5, 'redirection' => 0, 'limit_response_size' => 65536 ] );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return null;
			}
			$data = json_decode( (string) wp_remote_retrieve_body( $response ), true, 8 );
			return is_array( $data ) ? $data : null;
		};
		return HumanCheck::answer(
			$request->method,
			$body,
			(string) $request->header( 'User-Agent' ),
			self::clientIp(),
			(string) wp_parse_url( home_url(), PHP_URL_HOST ),
			$siteverify,
			$proof,
			time()
		);
	}

	/** @internal Between tests. */
	public static function reset(): void {
		self::$kind      = null;
		self::$readers   = null;
		self::$full      = false;
		self::$protected = false;
		self::$terms     = null;
		self::$held      = false;
		self::$quiet     = false;
		self::$holders   = 0;
		self::$paid      = [];
		self::$texts     = [];
		self::$firstPaid = null;
	}

	private static function remember( \WP_Post $post ): void {
		self::$texts[] = $post->post_content;
		self::$texts[] = PageAbout::publishes( $post ) ? '' : $post->post_excerpt;
		self::$firstPaid ??= self::$paid[ $post->ID ] ?? null;
	}

	private static function holder(): string {
		if ( 0 !== self::$holders++ ) {
			return '<div data-aifp-paid></div>';
		}
		if ( Unproven::PROVEN !== self::$readers ) {
			return '<div data-aifp-paid><p>' . esc_html__( 'This part of the page is paid for automated access.', 'aifinpay' ) . '</p></div>';
		}
		return '<div data-aifp-paid><div id="aifp-w"></div><p id="aifp-m">' . esc_html__( 'Loading the rest of the page…', 'aifinpay' ) . '</p></div>';
	}

	/** Head parts that repeat the text: SEO plugins' descriptions and structured data. */
	private static function withoutCopies(): void {
		foreach ( [ 'wpseo_metadesc', 'wpseo_opengraph_desc', 'wpseo_twitter_description', 'rank_math/frontend/description', 'rank_math/opengraph/facebook/og_description', 'rank_math/opengraph/twitter/twitter_description', 'aioseo_description', 'aioseo_og_description', 'aioseo_twitter_description' ] as $hook ) {
			add_filter( $hook, '__return_empty_string', PHP_INT_MAX );
		}
		foreach ( [ 'wpseo_json_ld_output', 'rank_math/json_ld' ] as $hook ) {
			add_filter( $hook, '__return_empty_array', PHP_INT_MAX );
		}
		add_filter( 'aioseo_schema_disable', '__return_true', PHP_INT_MAX );
	}

	private static function termsBody(): array {
		if ( null !== self::$terms ) {
			return self::$terms;
		}
		$settings = Settings::get();
		$rule     = self::$firstPaid['rule'] ?? '/';
		$tier     = Settings::tiers( $settings )[ $rule ] ?? $settings['tier'];
		return PageAbout::add( ReceiptGate::challenge( Connection::merchantId(), $rule, $tier, Api::origin() ), $settings );
	}

	private static function termsTag(): string {
		return '<script type="application/json" id="aifp-402">' . str_replace( '<', '\u003c', Json::encode( self::termsBody() ) ) . '</script>';
	}

	private static function inScript( string $value ): string {
		return str_replace( '<', '\u003c', Json::encode( $value ) );
	}

	/** Turnstile, then POST the token; on 204 fill each holder from the page fetched again, or reload. */
	private static function script( string $on_pass = 'fill' ): string {
		$check   = self::inScript( Settings::homePath() . HumanCheck::PATH );
		$refused = self::inScript( __( 'This part of the page is paid for automated access.', 'aifinpay' ) );
		$sitekey = self::inScript( Settings::get()['turnstile_sitekey'] );
		return '<script>(function(){
var holds=document.querySelectorAll("[data-aifp-paid]");
function refused(){var m=document.getElementById("aifp-m");if(m)m.textContent=' . $refused . ';}
function reload(){location.reload();}
function fill(){if(!holds.length)return;return fetch(location.href,{credentials:"same-origin",headers:{accept:"text/html"}}).then(function(p){
if(p.status!==200)return reload();
return p.text().then(function(t){var full=new DOMParser().parseFromString(t,"text/html").querySelectorAll("[data-aifp-content]");
if(full.length!==holds.length)return reload();
for(var i=0;i<holds.length;i++){holds[i].innerHTML=full[i].innerHTML;holds[i].removeAttribute("data-aifp-paid");}});}).catch(reload);}
window.aifpReady=function(){turnstile.render("#aifp-w",{sitekey:' . $sitekey . ',appearance:"interaction-only","error-callback":refused,
callback:function(token){fetch(' . $check . ',{method:"POST",credentials:"same-origin",headers:{"content-type":"application/json"},body:JSON.stringify({token:token})})
.then(function(r){return r.status===204?' . $on_pass . '():refused();}).catch(refused);}});};
})();</script><script src="' . esc_url( self::TURNSTILE ) . '" async defer></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Printed with the page it guards; a held page must not depend on the theme's enqueue.
	}

	/** When the page cannot be shown with its paid part held back. */
	private static function barePage(): string {
		$proven = Unproven::PROVEN === self::$readers;
		$hold   = $proven
			? '<div data-aifp-paid><div id="aifp-w"></div><p id="aifp-m">' . esc_html__( 'Loading the page…', 'aifinpay' ) . '</p></div>'
			: '';
		return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
			. '<title>' . esc_html__( 'Payment required', 'aifinpay' ) . '</title>'
			. '<style>body{font:16px/1.5 system-ui,sans-serif;margin:0;display:grid;place-items:center;min-height:100vh;color:#1c2430;background:#f6f8fb}main{max-width:34rem;padding:2rem}</style></head>'
			. '<body><main>' . $hold . '<p>' . esc_html__( 'Automated clients: this page is paid. The price and how to pay are in this response (HTTP 402).', 'aifinpay' ) . '</p></main>'
			. self::termsTag() . ( $proven ? self::script( 'reload' ) : '' ) . '</body></html>';
	}
}
