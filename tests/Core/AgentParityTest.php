<?php

declare(strict_types=1);

use AiFinPay\Core\AgentIdentity;
use AiFinPay\Core\Discovery;
use AiFinPay\Core\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Same inputs, same answer as @aifinpay/gate: tests/fixtures/agent-parity.json. */
final class AgentParityTest extends TestCase {

	private static function fixture(): array {
		return json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/agent-parity.json' ), true, 64, JSON_THROW_ON_ERROR );
	}

	public function testEnumsAndTableAreTheGates(): void {
		$f = self::fixture();
		self::assertSame( $f['operators'], AgentIdentity::OPERATORS );
		self::assertSame( $f['ua_classes'], AgentIdentity::UA_CLASSES );
		self::assertSame( $f['table'], array_map( static fn ( string $marker, array $row ): array => [ $marker, ...$row ], array_keys( AgentIdentity::UA_TABLE ), AgentIdentity::UA_TABLE ) );
	}

	public static function cases(): iterable {
		foreach ( self::fixture()['classify'] as $i => $case ) {
			yield $i . ' ' . ( $case['headers']['User-Agent'] ?? '(no user agent)' ) => [ $case['headers'], $case['result'] ];
		}
	}

	#[DataProvider( 'cases' )]
	public function testClassifyMatchesTheGate( array $headers, array $expected ): void {
		$lower = array_combine( array_map( 'strtolower', array_keys( $headers ) ), array_values( $headers ) );
		self::assertSame( $expected, AgentIdentity::classify( new Request( 'GET', 'x.test', '/', $lower ) ) );
	}

	public function testLinkHeaderIsTheGates(): void {
		$f = self::fixture()['discovery_link'];
		self::assertSame( $f['default'], Discovery::linkHeader() );
		self::assertSame( $f['without_x402'], Discovery::linkHeader( Discovery::LLMS_TXT_PATH, false ) );
		self::assertNull( Discovery::linkHeader( false, false ) );
		foreach ( $f['html'] as [ $type, $html ] ) {
			self::assertSame( $html, Discovery::isHtml( $type ), (string) $type );
		}
	}

	public function testX402DocumentIsTheGates(): void {
		$f = self::fixture()['discovery'];
		self::assertSame( json_encode( $f['body'] ), json_encode( Discovery::x402Document( $f['args']['merchant_id'], $f['args']['resources'], $f['args']['api_base'] ) ) );
	}

	public function testResourcesCarryTheTierAndComeFromValidPatterns(): void {
		self::assertSame(
			[
				[ 'resource' => '/pricing', 'tier' => 'premium', 'scope' => 'exact' ],
				[ 'resource' => '/resources/*', 'tier' => 'premium', 'scope' => 'prefix' ],
			],
			Discovery::resources( [ '/pricing', '/resources/*', '/bad//path', 7 ], 'premium' )
		);
	}

	public function testLlmsTxtSaysTheMode(): void {
		$shadow = Discovery::llmsTxt( false, null, Discovery::resources( [ '/pricing' ] ), "My\nSite", 'https://example.com/' );
		self::assertStringStartsWith( "# My Site — access for AI agents\n", $shadow );
		self::assertStringContainsString( 'Mode: shadow. Every page is served as usual and nothing is charged', $shadow );
		self::assertStringNotContainsString( 'x402.json', $shadow );
		$enforce = Discovery::llmsTxt( true, 'mrch_abc', [], 'Site', 'https://example.com' );
		self::assertStringContainsString( 'Mode: enforce.', $enforce );
		self::assertStringContainsString( 'No protected paths are configured.', $enforce );
		self::assertStringContainsString( '- Merchant: mrch_abc', $enforce );
	}
}
