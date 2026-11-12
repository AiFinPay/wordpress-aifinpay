<?php

declare(strict_types=1);

namespace AiFinPay\WordPress;

defined( 'ABSPATH' ) || exit;

use AiFinPay\Core\AgentIdentity;
use AiFinPay\Core\Discovery;
use AiFinPay\Core\Json;
use AiFinPay\Core\MerchantRecord;
use AiFinPay\Core\Paths;
use AiFinPay\Core\ReceiptGate;
use AiFinPay\Core\Request;

/** The gate at the three places a WordPress request can be answered. */
final class FrontGate {

	private static bool $done = false;

	public static function currentRequest(): Request {
		$headers = [];
		foreach ( [ 'HTTP_USER_AGENT' => 'user-agent', 'HTTP_AIFP_RECEIPT' => 'aifp-receipt', 'HTTP_AIFP_AGENT_ID' => 'aifp-agent-id' ] as $key => $name ) {
			if ( isset( $_SERVER[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared and parsed, never stored or printed; sanitizing would change a signed token.
				$headers[ $name ] = (string) wp_unslash( $_SERVER[ $key ] );
			}
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- inspect is the sanitizer; only compared with patterns.
		$seen = Paths::inspect( isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/' );
		return new Request(
			isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET',
			isset( $_SERVER['HTTP_HOST'] ) ? Request::normaliseHost( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '',
			$seen['path'],
			$headers,
			is_user_logged_in(),
			$seen['ambiguous']
		);
	}

	/** Pages, archives and feeds, after canonical redirects and before any output. */
	public static function onTemplateRedirect(): void {
		$settings = Settings::get();
		if ( 'off' === $settings['mode'] || is_404() || is_robots() || is_favicon() ) {
			return;
		}
		// Every method: WordPress renders the page for OPTIONS and PUT as it does for GET.
		$request = self::currentRequest();
		self::refuseIfAmbiguous( $request, $request->ambiguous, $settings );
		$candidates = [ $request->path ];
		if ( is_singular() ) {
			// However the URL was spelled, the post it resolved to has one permalink.
			$candidates[] = Paths::inspect( (string) wp_parse_url( (string) get_permalink( get_queried_object_id() ), PHP_URL_PATH ) )['path'];
		}
		if ( is_feed() ) {
			// /?feed=rss2 and every archive's feed carry the same posts as the main feed.
			$candidates[] = Settings::homePath() . '/feed';
		}
		$resolved = Paths::resolve( $candidates, Settings::patterns( $settings ), self::excluded( $settings ) );
		if ( null !== $resolved ) {
			self::run( $request, $resolved['rule'], $resolved['path'], $settings );
		}
	}

	/** The REST API. Only patterns naming the REST prefix apply: "/*" must not reach webhooks and form endpoints. */
	public static function onRestInit(): void {
		if ( self::$done || ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return;
		}
		self::$done = true;
		$settings   = Settings::get();
		if ( 'off' === $settings['mode'] ) {
			return;
		}
		$request = self::currentRequest();
		// A CORS preflight gets the route's schema here, never its content.
		if ( 'OPTIONS' === $request->method ) {
			return;
		}
		$rest     = Settings::restPath();
		$route    = $GLOBALS['wp']->query_vars['rest_route'] ?? '/';
		// WordPress has decoded the route once, so a mark in it was an escaped one.
		$seen     = Paths::inspect( $rest . '/' . str_replace( [ '?', '#' ], [ '%3F', '%23' ], is_string( $route ) ? $route : '' ) );
		self::refuseIfAmbiguous( $request, $request->ambiguous || $seen['ambiguous'], $settings );
		$path     = $seen['path'];
		$patterns = array_filter( Settings::patterns( $settings ), static fn ( string $p ): bool => str_starts_with( $p, $rest . '/' ) );
		$resolved = Paths::resolve( [ $path ], $patterns, $settings['excluded_paths'] );
		if ( null !== $resolved ) {
			self::run( $request, $resolved['rule'], $resolved['path'], $settings );
		}
	}

	/** /llms.txt and /.well-known/x402.json, built from this site's settings. A physical file wins: the web server answers first. */
	public static function onParseRequest(): void {
		$settings = Settings::get();
		if ( 'off' === $settings['mode'] || ! $settings['discovery_documents'] ) {
			return;
		}
		$request = self::currentRequest();
		$home    = Settings::homePath();
		if ( ! in_array( $request->method, [ 'GET', 'HEAD' ], true ) || ! in_array( $request->path, [ $home . Discovery::LLMS_TXT_PATH, $home . Discovery::X402_PATH ], true ) ) {
			return;
		}
		$merchant  = Connection::merchantId();
		$resources = Discovery::resources( Settings::patterns( $settings ), $settings['tier'] );
		if ( $home . Discovery::LLMS_TXT_PATH === $request->path ) {
			$type = 'text/markdown; charset=utf-8';
			$body = Discovery::llmsTxt( 'enforce' === $settings['mode'], '' !== $merchant ? $merchant : null, $resources, wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), home_url() );
		} elseif ( '' !== $merchant ) {
			$type = 'application/json';
			$body = Json::encode( Discovery::x402Document( $merchant, $resources, Api::origin() ) );
		} else {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: ' . $type );
		header( 'Cache-Control: public, max-age=300' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( 'shadow' === $settings['mode'] ) {
			header( 'AIFP-Mode: shadow' );
		}
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A plain-text or JSON document built from validated settings.
		exit;
	}

	/** On every front-end response: the mode, and on HTML the way to the agent documents. */
	public static function filterHeaders( array $headers ): array {
		$settings = Settings::get();
		if ( 'off' === $settings['mode'] ) {
			return $headers;
		}
		if ( 'shadow' === $settings['mode'] ) {
			$headers['AIFP-Mode'] = 'shadow';
		}
		if ( $settings['discovery_link'] && Discovery::isHtml( $headers['Content-Type'] ?? null ) ) {
			$home = Settings::homePath();
			$x402 = $settings['discovery_documents'] && '' !== Connection::merchantId() ? $home . Discovery::X402_PATH : false;
			$link = (string) Discovery::linkHeader( $home . Discovery::LLMS_TXT_PATH, $x402 );
			$headers['Link'] = isset( $headers['Link'] ) ? $headers['Link'] . ', ' . $link : $link;
		}
		return $headers;
	}

	private static function excluded( array $settings ): array {
		$home = Settings::homePath();
		return [ ...$settings['excluded_paths'], $home . Discovery::LLMS_TXT_PATH, $home . '/.well-known/*' ];
	}

	private static function run( Request $request, string $rule, string $path, array $settings ): void {
		$reader = AgentIdentity::isReader( $request );
		if ( 'shadow' === $settings['mode'] ) {
			try {
				self::shadow( $request, $rule, $path, $reader, $settings );
			} catch ( \Throwable ) {
				// Observing never breaks a page.
				return;
			}
			return;
		}
		CacheCompat::forbid();
		try {
			$merchant = Connection::merchantId();
			$charging = $reader ? MerchantRecord::charging( '', null ) : MerchantRecord::charging( $merchant, RecordStore::get() );
			$gate     = new ReceiptGate( new WpKeySet(), new WpdbMeter(), Api::origin() );
			$decision = $gate->enforce( $request, $merchant, $charging, $rule, $path, $settings['tier'], time() );
		} catch ( \Throwable ) {
			$decision = [ 'status' => 503, 'body' => [ 'error' => 'payment_service_unavailable' ], 'headers' => [], 'event' => null, 'outcome' => 'refused', 'units' => 0 ];
		}
		self::observe( $decision, $rule, $settings );
		foreach ( $decision['headers'] as $name => $value ) {
			// Link is added beside the page's own values.
			header( $name . ': ' . $value, 'Link' !== $name );
		}
		if ( 200 !== $decision['status'] ) {
			self::refuse( $decision['status'], $decision['body'] );
		}
	}

	/** Origins disagree on such a spelling, so no price is safe to quote. A reader still gets the page. */
	private static function refuseIfAmbiguous( Request $request, bool $ambiguous, array $settings ): void {
		if ( $ambiguous && 'enforce' === $settings['mode'] && ! AgentIdentity::isReader( $request ) ) {
			CacheCompat::forbid();
			self::refuse( 400, [ 'error' => 'noncanonical_path', 'detail' => 'this path means different things to different servers' ] );
		}
	}

	/** @return never */
	private static function refuse( int $status, array $body ): void {
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		header_remove( 'Link' );
		echo Json::encode( $body ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON, byte for byte the gate SDK's body.
		exit;
	}

	private static function shadow( Request $request, string $rule, string $path, bool $reader, array $settings ): void {
		// The site-wide default leaves readers' caching alone; a path the owner chose is measured exactly.
		if ( Paths::ALL !== $rule || ! $reader ) {
			CacheCompat::forbid();
		}
		$merchant = Connection::merchantId();
		// Only a presented receipt reads the record or the keys: observing costs no request otherwise.
		$network  = ! $reader && '' !== (string) $request->header( 'AIFP-Receipt' ) && '' !== $merchant ? ( RecordStore::get()['network_mode'] ?? null ) : null;
		$gate     = new ReceiptGate( new WpKeySet(), new WpdbMeter(), Api::origin() );
		$decision = $gate->shadow( $request, $merchant, $network, $rule, $path, $settings['tier'], time() );
		foreach ( $decision['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}
		self::observe( $decision, $rule, $settings );
	}

	/** Nothing here may change the answer. */
	private static function observe( array $decision, string $rule, array $settings ): void {
		$event = $decision['event'];
		if ( null === $event ) {
			return;
		}
		try {
			TrafficLog::record( $rule, $event['operator'], $event['ua_class'], $decision['outcome'], $decision['units'] );
			if ( $settings['report_events'] && null !== Connection::get() ) {
				EventQueue::push( $event, $rule, time() );
			}
		} catch ( \Throwable ) {
			return;
		}
	}
}
