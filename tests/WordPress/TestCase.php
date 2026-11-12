<?php

declare(strict_types=1);

namespace AiFinPay\Tests;

use AiFinPay\WordPress\Connection;
use AiFinPay\WordPress\EventDelivery;
use AiFinPay\WordPress\EventQueue;
use AiFinPay\WordPress\RecordStore;
use AiFinPay\WordPress\Resources;
use AiFinPay\WordPress\Schema;
use AiFinPay\WordPress\Settings;
use AiFinPay\WordPress\WpKeySet;

/** A real WordPress and database; only the outbound HTTP is answered here, through its own filter. */
abstract class TestCase extends \PHPUnit\Framework\TestCase {

	protected const MERCHANT = 'mrch_wp_adapter';
	protected const SECRET   = 'msec_abcdefabcdefabcdefabcdefabcdefabcdef';

	/** @var array<int, array{method: string, url: string, headers: array, body: ?string}> */
	protected array $calls = [];

	/** @var callable(string, string, ?string): array|\WP_Error|int */
	protected $responder;

	protected function setUp(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			self::markTestSkipped( 'Needs AIFINPAY_WP_ROOT: scripts/setup-wp.sh' );
		}
		global $wpdb;
		$wpdb->suppress_errors( false );
		$wpdb->show_errors( false );
		foreach ( [ Settings::OPTION, Connection::OPTION, RecordStore::LAST, EventDelivery::LOCK, EventDelivery::STATE, Resources::STATE ] as $option ) {
			delete_option( $option );
		}
		foreach ( [ RecordStore::TRANSIENT, WpKeySet::TRANSIENT, WpKeySet::HOLD ] as $transient ) {
			delete_transient( $transient );
		}
		foreach ( [ Schema::units(), Schema::events(), Schema::traffic() ] as $table ) {
			$wpdb->query( "DELETE FROM $table" ); // phpcs:ignore
		}
		EventQueue::$enqueued = false;
		$this->calls          = [];
		$this->responder      = static fn () => 500;
		add_filter( 'pre_http_request', [ $this, 'answer' ], 10, 3 );
		add_filter( 'cron_schedules', [ \AiFinPay\WordPress\Maintenance::class, 'addSchedule' ] );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'answer' ], 10 );
	}

	public function answer( $pre, array $args, string $url ) {
		$this->calls[] = [ 'method' => $args['method'], 'url' => $url, 'headers' => $args['headers'], 'body' => $args['body'] ?? null, 'args' => $args ];
		$out           = ( $this->responder )( $args['method'], $url, $args['body'] ?? null );
		if ( $out instanceof \WP_Error ) {
			return $out;
		}
		[ $status, $body ] = is_int( $out ) ? [ $out, '' ] : $out;
		return [ 'response' => [ 'code' => $status, 'message' => '' ], 'body' => is_array( $body ) ? wp_json_encode( $body ) : $body, 'headers' => [], 'cookies' => [] ];
	}

	protected function connect(): void {
		update_option( Connection::OPTION, [ 'merchant_id' => self::MERCHANT, 'secret' => self::SECRET, 'connected_at' => 1 ], false );
	}

	protected function settings( array $patch ): array {
		Settings::save( $patch + Settings::defaults() );
		return Settings::get();
	}
}
