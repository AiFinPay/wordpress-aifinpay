<?php

declare(strict_types=1);

use AiFinPay\Tests\TestCase;
use AiFinPay\WordPress\EventDelivery;
use AiFinPay\WordPress\EventQueue;
use AiFinPay\WordPress\Connection;

require_once __DIR__ . '/TestCase.php';

final class EventDeliveryTest extends TestCase {

	private function queue( int $count, ?int $at = null, array $patch = [] ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			EventQueue::push( $patch + [ 'kind' => '402', 'operator' => 'openai', 'ua_class' => 'ai_crawler', 'verified' => false ], '/*', $at ?? time() );
		}
	}

	protected function setUp(): void {
		parent::setUp();
		$this->connect();
		$this->settings( [ 'report_events' => true ] );
	}

	public function testTheWireShapeIsTheIngestsIdentityEvent(): void {
		$this->queue( 1 );
		$this->responder = static fn () => [ 200, [ 'accepted' => 1 ] ];
		EventDelivery::run();
		self::assertCount( 1, $this->calls );
		self::assertStringEndsWith( '/v1/merchants/' . self::MERCHANT . '/gate-events', $this->calls[0]['url'] );
		self::assertSame( self::SECRET, $this->calls[0]['headers']['AIFP-Merchant-Secret'] );
		$body = json_decode( $this->calls[0]['body'], true );
		self::assertSame( [ 'events' ], array_keys( $body ) );
		$event = $body['events'][0];
		$keys  = array_keys( $event );
		sort( $keys );
		self::assertSame( [ 'at', 'id', 'kind', 'operator', 'resource', 'ua_class', 'verified' ], $keys );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $event['id'] );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $event['at'] );
		self::assertFalse( $event['verified'] );
		self::assertSame( '/*', $event['resource'] );
		self::assertSame( 0, EventQueue::count() );
		self::assertNotNull( EventDelivery::state()['last_delivered_at'] );
	}

	public function testBatchesOfFifty(): void {
		$this->queue( 120 );
		$this->responder = static fn () => 200;
		EventDelivery::run();
		self::assertSame( [ 50, 50, 20 ], array_map( static fn ( array $c ): int => count( json_decode( $c['body'], true )['events'] ), $this->calls ) );
		self::assertSame( 0, EventQueue::count() );
	}

	public function testBusyAndUnreachableKeepTheEvents(): void {
		$this->queue( 2 );
		foreach ( [ 429, 503, new WP_Error( 'down', 'down' ) ] as $answer ) {
			$this->calls     = [];
			$this->responder = static fn () => $answer;
			EventDelivery::run();
			self::assertCount( 1, $this->calls, 'stops at once' );
			self::assertSame( 2, EventQueue::count() );
		}
	}

	public function testARetryKeepsTheSameIds(): void {
		$this->queue( 2 );
		$this->responder = static fn () => 503;
		EventDelivery::run();
		EventDelivery::run();
		self::assertSame( $this->calls[0]['body'], $this->calls[1]['body'] );
	}

	public function testARejectedCredentialKeepsTheEventsAndSaysSo(): void {
		$this->queue( 2 );
		$this->responder = static fn () => 403;
		EventDelivery::run();
		self::assertSame( 2, EventQueue::count() );
		self::assertSame( 403, EventDelivery::state()['last_status'] );
	}

	public function testOneBadEventIsDroppedAndTheRestDelivered(): void {
		$this->queue( 3 );
		$bad             = EventQueue::take( 3 )[1]['id'];
		$this->responder = static function ( string $m, string $u, ?string $body ) use ( $bad ) {
			return str_contains( (string) $body, $bad ) ? 400 : 200;
		};
		EventDelivery::run();
		self::assertSame( 0, EventQueue::count() );
		self::assertCount( 4, $this->calls, 'the batch, then one by one' );
	}

	public function testAnOutageDuringTheSplitKeepsWhatIsLeft(): void {
		$this->queue( 3 );
		$n               = 0;
		$this->responder = static function () use ( &$n ) {
			$n++;
			return 1 === $n ? 400 : ( 2 === $n ? 200 : 503 );
		};
		EventDelivery::run();
		self::assertSame( 2, EventQueue::count() );
	}

	public function testEventsTooOldForTheIngestAreDropped(): void {
		$this->queue( 1, time() - EventDelivery::MAX_AGE - 10 );
		EventDelivery::run();
		self::assertSame( 0, EventQueue::count() );
		self::assertSame( [], $this->calls );
	}

	public function testNothingIsSentWhenReportingIsOffOrDisconnected(): void {
		$this->queue( 1 );
		$this->settings( [ 'report_events' => false ] );
		EventDelivery::run();
		$this->settings( [ 'report_events' => true ] );
		delete_option( Connection::OPTION );
		EventDelivery::run();
		self::assertSame( [], $this->calls );
	}

	public function testTheQueueIsCapped(): void {
		global $wpdb;
		$this->queue( 1 );
		for ( $i = 0; $i < EventQueue::CAP; $i++ ) {
			$wpdb->insert( AiFinPay\WordPress\Schema::events(), [ 'id' => sprintf( '%036d', $i ), 'payload' => '{}', 'created' => time() ] );
		}
		$before = EventQueue::count();
		$this->queue( 1 );
		self::assertSame( $before, EventQueue::count() );
	}

	public function testALockedRunDoesNothing(): void {
		$this->queue( 1 );
		add_option( EventDelivery::LOCK, time() + 60, '', false );
		EventDelivery::run();
		self::assertSame( [], $this->calls );
		update_option( EventDelivery::LOCK, time() - 1 );
		$this->responder = static fn () => 200;
		EventDelivery::run();
		self::assertCount( 1, $this->calls, 'a stale lock is taken over' );
		self::assertFalse( get_option( EventDelivery::LOCK ) );
	}

	public function testTheFallbackWaitsForCron(): void {
		$finished = 0;
		$finish   = static function () use ( &$finished ): void {
			$finished++;
		};
		$this->queue( 1 );
		EventDelivery::fallback( $finish );
		self::assertSame( 0, $finished, 'cron may still run' );
		self::assertSame( [], $this->calls );
	}

	public function testTheFallbackIgnoresARequestThatQueuedNothing(): void {
		$this->queue( 1, time() - EventDelivery::CRON_OVERDUE - 1 );
		EventQueue::$enqueued = false;
		$finished             = 0;
		EventDelivery::fallback(
			static function () use ( &$finished ): void {
				$finished++;
			}
		);
		self::assertSame( 0, $finished );
	}

	public function testTheFallbackFinishesTheResponseFirst(): void {
		$order           = [];
		$this->queue( 60, time() - EventDelivery::CRON_OVERDUE - 1 );
		$this->responder = static function () use ( &$order ) {
			$order[] = 'send';
			return 200;
		};
		EventDelivery::fallback(
			static function () use ( &$order ): void {
				$order[] = 'finish';
			}
		);
		self::assertSame( [ 'finish', 'send' ], $order, 'one batch, after the visitor has the page' );
		self::assertSame( 10, EventQueue::count() );
	}

	public function testWithoutAWayToFinishItAsksCron(): void {
		$spawned = 0;
		$this->queue( 1, time() - EventDelivery::CRON_OVERDUE - 1 );
		if ( function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' ) ) {
			self::markTestSkipped( 'This SAPI can finish a response.' );
		}
		EventDelivery::fallback(
			null,
			static function () use ( &$spawned ): void {
				$spawned++;
			}
		);
		self::assertSame( 1, $spawned );
		self::assertSame( [], $this->calls, 'a visitor never waits for AiFinPay' );
	}
}
