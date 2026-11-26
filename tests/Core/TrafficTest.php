<?php

declare(strict_types=1);

use AiFinPay\Core\AgentIdentity;
use AiFinPay\Core\Traffic;
use AiFinPay\Core\TrafficSender;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Driven by conformance/vectors/gate-traffic-v1.json of AiFinPay/sdk (copy in tests/fixtures). */
final class TrafficTest extends TestCase {

	private static ?array $vectors = null;

	private static function vectors(): array {
		return self::$vectors ??= json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/gate-traffic-v1.json' ), true, 64, JSON_THROW_ON_ERROR );
	}

	private static function named( string $key ): array {
		$out = [];
		foreach ( self::vectors()[ $key ] as $i => $v ) {
			$out[ $v['name'] ?? $key . ' ' . $i . ' ' . json_encode( $v ) ] = [ $v ];
		}
		return $out;
	}

	public function testTheListsAreTheVectors(): void {
		$v = self::vectors();
		self::assertSame( $v['language_prefixes'], Traffic::LANGUAGE_PREFIXES );
		self::assertSame( $v['asset_extensions'], Traffic::ASSET_EXTENSIONS );
		self::assertSame( $v['caps'], Traffic::CAPS );
		self::assertSame( $v['outcomes'], Traffic::OUTCOMES );
		self::assertSame( $v['ua_classes'], AgentIdentity::UA_CLASSES );
		self::assertSame( $v['operators'], AgentIdentity::OPERATORS );
		self::assertSame( $v['max_rows'], Traffic::MAX_ROWS );
		self::assertSame( $v['window_seconds'], Traffic::WINDOW );
		self::assertSame( $v['sender']['hold'], TrafficSender::HOLD );
		self::assertSame( $v['sender']['stale_seconds'], TrafficSender::STALE );
		self::assertSame( [ '404' => TrafficSender::PAUSE_404, 'other' => TrafficSender::PAUSE_OTHER ], $v['sender']['pause_seconds'] );
		self::assertSame( $v['edge_counted']['header'], Traffic::EDGE_HEADER );
	}

	public static function sections(): array {
		return self::named( 'sections' );
	}

	#[DataProvider( 'sections' )]
	public function testTheSectionTable( array $v ): void {
		self::assertSame( $v['section'], Traffic::sectionOf( $v['target'] ), $v['target'] );
	}

	public static function counted(): array {
		return self::named( 'counted' );
	}

	#[DataProvider( 'counted' )]
	public function testAnAssetServedFreeIsNotCounted( array $v ): void {
		self::assertSame( $v['counted'], Traffic::counted( $v['target'], $v['outcome'] ) );
		self::assertSame( $v['counted'], null !== Traffic::rows( [ 'target' => $v['target'], 'ua_class' => 'ai_crawler', 'operator' => 'openai', 'outcome' => $v['outcome'] ] ) );
	}

	public static function countries(): array {
		return self::named( 'countries' );
	}

	#[DataProvider( 'countries' )]
	public function testCountry( array $v ): void {
		self::assertSame( $v['country'], Traffic::countryOf( $v['value'] ) );
	}

	public static function networks(): array {
		return self::named( 'networks' );
	}

	#[DataProvider( 'networks' )]
	public function testNetwork( array $v ): void {
		$value = is_float( $v['value'] ) && floor( $v['value'] ) === $v['value'] ? (int) $v['value'] : $v['value'];
		self::assertSame( $v['network'], Traffic::networkOf( $value ) );
	}

	public static function edgeValues(): array {
		$out = [];
		foreach ( self::vectors()['edge_counted']['values'] as $v ) {
			$out[ $v['name'] ] = [ $v['lines'], $v['edge_counted'] ];
		}
		return $out;
	}

	#[DataProvider( 'edgeValues' )]
	public function testTheEdgeMark( array $lines, bool $counted_by_edge ): void {
		self::assertSame( $counted_by_edge, Traffic::edgeCounted( $lines ) );
	}

	/** PHP joins a repeated field with a comma, which is never the exact value 1. */
	public function testARepeatedEdgeFieldAsPhpSeesItIsCounted(): void {
		self::assertFalse( Traffic::edgeCounted( [ '1, 1' ] ) );
		self::assertFalse( Traffic::edgeCounted( [ '1,1' ] ) );
	}

	/** What a window holds after these requests, as TrafficCounter stores it: one row per request and table. */
	public static function tally( array $platform, array $requests ): array {
		$tables = [];
		foreach ( $requests as $r ) {
			if ( Traffic::edgeCounted( $r['edge_counted'] ?? [] ) ) {
				continue;
			}
			$rows = Traffic::rows( $r, $platform['country'], $r['country'] ?? null, $platform['network'], $r['network'] ?? null );
			foreach ( $rows ?? [] as [ $name, $cells ] ) {
				$tables[ $name ][] = [ ...$cells, $r['n'] ?? 1 ];
			}
		}
		return $tables;
	}

	public static function builds(): array {
		$out = [];
		foreach ( self::vectors()['build'] as $v ) {
			$out[ 'build ' . $v['name'] ] = [ $v ];
		}
		foreach ( self::vectors()['edge_counted']['build'] as $v ) {
			$out[ 'edge_counted ' . $v['name'] ] = [ $v ];
		}
		return $out;
	}

	#[DataProvider( 'builds' )]
	public function testEveryBuildVectorByteForByte( array $v ): void {
		self::assertSame( $v['body'], Traffic::build( $v['window'], self::tally( $v['platform'], $v['requests'] ) ) );
	}

	public function testAReaderIsCountedButNeverByOperatorSectionOrNetwork(): void {
		$rows = Traffic::rows( [ 'target' => '/blog/a', 'ua_class' => 'human', 'operator' => 'unknown', 'outcome' => 'served' ], true, 'US', true, 'AS15169' );
		self::assertSame( [ [ 'classes', [ 'human', 'served' ] ], [ 'countries', [ 'US', 'human' ] ] ], $rows );
		$agent = Traffic::rows( [ 'target' => '/blog/a', 'ua_class' => 'ai_crawler', 'operator' => 'openai', 'outcome' => 'served' ], true, 'US', true, 'AS15169' );
		self::assertContains( [ 'networks', [ 'AS15169', 'ai_crawler' ] ], $agent );
		self::assertContains( [ 'sections', [ '/blog', 'ai_crawler' ] ], $agent );
	}

	public function testNoPathBeyondTheFirstSegmentIsKept(): void {
		$rows  = Traffic::rows( [ 'target' => '/blog/2026/11/secret-title?token=abc', 'ua_class' => 'ai_crawler', 'operator' => 'openai', 'outcome' => 'challenged' ] );
		$flat  = json_encode( $rows );
		self::assertStringNotContainsString( 'secret', $flat );
		self::assertStringNotContainsString( 'token', $flat );
		self::assertContains( [ 'sections', [ '/blog', 'ai_crawler' ] ], $rows );
	}

	public static function decisions(): array {
		$serve = [ 'kind' => 'serve', 'operator' => 'aifinpay', 'ua_class' => 'aifp_agent', 'verified' => true ];
		$asked = [ 'kind' => '402', 'operator' => 'openai', 'ua_class' => 'ai_crawler', 'verified' => false ];
		return [
			'reader'          => [ [ 'status' => 200, 'headers' => [], 'event' => null ], 'served' ],
			'exempt'          => [ [ 'status' => 200, 'headers' => [ 'AIFP-Paywall' => 'exempt' ], 'event' => null ], 'served' ],
			'paid'            => [ [ 'status' => 200, 'headers' => [], 'event' => $serve ], 'paid' ],
			'challenged'      => [ [ 'status' => 402, 'headers' => [], 'event' => $asked ], 'challenged' ],
			'blocked'         => [ [ 'status' => 403, 'headers' => [], 'event' => null ], 'blocked' ],
			'error'           => [ [ 'status' => 503, 'headers' => [ 'Retry-After' => '30' ], 'event' => null ], 'error' ],
			'shadow would ask' => [ [ 'status' => 200, 'headers' => [ 'AIFP-Shadow-Status' => '402' ], 'event' => $asked ], 'challenged' ],
			'shadow would refuse' => [ [ 'status' => 200, 'headers' => [ 'AIFP-Shadow-Status' => '403' ], 'event' => null ], 'blocked' ],
			'shadow outage'   => [ [ 'status' => 200, 'headers' => [ 'AIFP-Shadow-Status' => '503' ], 'event' => null ], 'error' ],
			'shadow paid'     => [ [ 'status' => 200, 'headers' => [], 'event' => $serve ], 'paid' ],
			'noncanonical'    => [ [ 'status' => 400, 'headers' => [], 'event' => null ], 'blocked' ],
		];
	}

	#[DataProvider( 'decisions' )]
	public function testTheOutcomeOfADecisionWhateverTheMode( array $decision, string $outcome ): void {
		self::assertSame( $outcome, Traffic::outcome( $decision ) );
	}

	public static function scenarios(): array {
		$out = [];
		foreach ( self::vectors()['sender']['scenarios'] as $s ) {
			$out[ $s['name'] ] = [ $s['steps'], $s['expect'] ];
		}
		return $out;
	}

	#[DataProvider( 'scenarios' )]
	public function testEverySenderScenario( array $steps, array $expect ): void {
		$state = TrafficSender::emptyState();
		$bytes = [];
		foreach ( $steps as $i => $step ) {
			$answers = $step['answers'];
			$sent    = [];
			$closed  = null === $step['closed'] ? [] : [ [ 'id' => $step['closed'], 'to' => $step['at'], 'body' => '{"id":"' . $step['closed'] . '"}' ] ];
			$state   = TrafficSender::tick(
				$state,
				$step['at'],
				$closed,
				static function ( string $body ) use ( &$answers, &$sent, &$bytes ): ?int {
					$id      = json_decode( $body, true )['id'];
					$sent[]  = $id;
					$bytes[ $id ] ??= $body;
					self::assertSame( $bytes[ $id ], $body, 'a retry sends the same bytes' );
					$answer = array_shift( $answers );
					return is_int( $answer ) ? $answer : null;
				}
			);
			self::assertSame( $expect[ $i ]['sent'], $sent, "step $i sent" );
			self::assertSame( $expect[ $i ]['held'], array_column( $state['held'], 'id' ), "step $i held" );
			self::assertSame( $expect[ $i ]['paused_until'], $state['paused_until'], "step $i paused_until" );
		}
	}

	public function testAHeldWindowSurvivesAStoredRoundTripAndAGarbledStateIsEmpty(): void {
		$state = TrafficSender::tick( TrafficSender::emptyState(), 300, [ [ 'id' => 'w1', 'to' => 300, 'body' => '{"id":"w1"}' ] ], static fn (): ?int => 503 );
		self::assertSame( $state, TrafficSender::state( unserialize( serialize( $state ) ) ) );
		self::assertSame( TrafficSender::emptyState(), TrafficSender::state( 'garbage' ) );
		self::assertSame( [], TrafficSender::state( [ 'held' => [ [ 'id' => 1 ] ], 'paused_until' => 'x' ] )['held'] );
	}
}
