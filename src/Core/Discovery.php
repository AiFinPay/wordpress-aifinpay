<?php

declare(strict_types=1);

namespace AiFinPay\Core;

defined( 'ABSPATH' ) || exit;

/**
 * How an agent on any page finds the site's terms: gate/src/discovery-link.ts
 * and buildDiscoveryDocument in gate/src/discovery.ts.
 */
final class Discovery {

	public const LLMS_TXT_PATH = '/llms.txt';
	public const X402_PATH     = '/.well-known/x402.json';

	private const DOCS           = 'https://github.com/AiFinPay/sdk/blob/main/AGENT-FLOW.md';
	private const SKILL          = 'https://raw.githubusercontent.com/AiFinPay/skill/main/agent/skills/aifinpay/SKILL.md'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- A URL in the protocol document for the agent to read; this site loads nothing from it.
	private const MERCHANT_SKILL = 'https://raw.githubusercontent.com/AiFinPay/skill/main/agent/skills/aifinpay-merchant/SKILL.md'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- A URL in the protocol document for the agent to read; this site loads nothing from it.

	public static function linkHeader( string|false $llms_txt = self::LLMS_TXT_PATH, string|false $x402 = self::X402_PATH ): ?string {
		$links = [];
		if ( false !== $llms_txt ) {
			$links[] = '<' . $llms_txt . '>; rel="alternate"; type="text/markdown"; title="AI agents: access and payment"';
		}
		if ( false !== $x402 ) {
			$links[] = '<' . $x402 . '>; rel="describedby"; type="application/json"';
		}
		return $links ? implode( ', ', $links ) : null;
	}

	public static function isHtml( ?string $content_type ): bool {
		$type = strtolower( trim( explode( ';', (string) $content_type, 2 )[0] ) );
		return 'text/html' === $type || 'application/xhtml+xml' === $type;
	}

	/** Protected patterns as gate resources: "/x/*" is a prefix scope. */
	public static function resources( array $patterns, string $tier = 'standard' ): array {
		$resources = [];
		foreach ( $patterns as $pattern ) {
			if ( is_string( $pattern ) && Paths::validPattern( $pattern ) ) {
				$resources[] = [
					'resource' => $pattern,
					'tier'     => $tier,
					'scope'    => str_ends_with( $pattern, '/*' ) ? 'prefix' : 'exact',
				];
			}
		}
		return $resources;
	}

	/** @param array<int, array{resource: string, tier?: string, scope?: string, name?: string}> $resources */
	public static function x402Document( string $merchant_id, array $resources, string $api_base = 'https://api.aifinpay.io' ): array {
		$api = rtrim( $api_base, '/' );
		return [
			'x402_version'              => 1,
			'protocol'                  => 'AIFP-1',
			'merchant_id'               => $merchant_id,
			'quote_endpoint'            => $api . '/v1/quote',
			'pay_endpoint'              => $api . '/v1/pay',
			'onboarding'                => 'npx @aifinpay/mcp init',
			'documentation_url'         => self::DOCS,
			'instructions_url'          => self::SKILL,
			'merchant_instructions_url' => self::MERCHANT_SKILL,
			'resources'                 => array_map(
				static fn ( array $r ): array => [
					'resource'       => $r['resource'],
					...( isset( $r['name'] ) ? [ 'name' => $r['name'] ] : [] ),
					'tier'           => $r['tier'] ?? 'standard',
					'scope'          => $r['scope'] ?? 'exact',
					'unit_price_usd' => Pricing::UNIT_PRICE_USD[ $r['tier'] ?? 'standard' ] ?? '0.0005',
				],
				$resources
			),
			'settlement_terms_from'     => $api . '/v1/quote',
		];
	}

	public static function llmsTxt( bool $enforce, ?string $merchant, array $resources, string $site_name, string $base_url ): string {
		$base    = rtrim( $base_url, '/' );
		$example = $resources ? $base . rtrim( $resources[0]['resource'], '*' ) : $base . '/<protected path>';
		$lines   = [
			'# ' . trim( (string) preg_replace( '/[\r\n]+/', ' ', $site_name ) ) . ' — access for AI agents',
			'',
			'> Paid access for AI agents on this site runs through AiFinPay (HTTP 402, AIFP-1).',
			'',
			'## Access',
			'',
			$enforce
				? 'Mode: enforce. An AI agent requesting a protected path gets HTTP 402 with a quote until it sends a valid AIFP-Receipt.'
				: 'Mode: shadow. Every page is served as usual and nothing is charged; AiFinPay counts AI-agent requests to the protected paths below.',
			'',
			'## Protected paths',
			'',
		];
		foreach ( $resources as $r ) {
			$lines[] = '- ' . $base . $r['resource'] . ( 'prefix' === $r['scope'] ? ' (and everything under it)' : '' );
		}
		if ( ! $resources ) {
			$lines[] = 'No protected paths are configured.';
		}
		array_push(
			$lines,
			'',
			'## How to pay',
			'',
			'1. Install a payer: `npx skills add AiFinPay/skill` (the AiFinPay skill for your agent) or `npx -y @aifinpay/mcp` (MCP server with a local wallet and the payable_fetch tool).',
			"2. Fund that wallet with the asset and network this site's HTTP 402 quote names (accepted_chains, accepted_assets).",
			$enforce
				? '3. Call the paid URL, for example ' . $example . '. It answers HTTP 402 with the quote; payable_fetch (MCP) or fetchPaid (Node SDK) quotes, pays and retries with the receipt.'
				: '3. Once this site enforces payment, the paid URL (for example ' . $example . ') answers HTTP 402 with the quote; payable_fetch (MCP) or fetchPaid (Node SDK) quotes, pays and retries with the receipt. Until then it is served without payment.',
			'',
			'## Machine-readable',
			''
		);
		if ( null !== $merchant ) {
			$lines[] = '- x402 discovery: ' . $base . self::X402_PATH;
			$lines[] = '- Merchant: ' . $merchant;
		}
		$lines[] = '- Agent flow: ' . self::DOCS;
		$lines[] = '- Agent skill: ' . self::SKILL;
		$lines[] = '';
		return implode( "\n", $lines );
	}
}
