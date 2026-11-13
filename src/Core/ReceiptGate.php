<?php

declare(strict_types=1);

namespace AiFinPay\Core;

defined( 'ABSPATH' ) || exit;

/**
 * The self-hosted AIFP-1 gate of @aifinpay/gate (gate/src/core.ts), in PHP.
 *
 * No receipt: the gate SDK's 402 body. A receipt: verified here against the
 * published JWKS and its prepaid units metered in this site's database.
 */
final class ReceiptGate {

	public const DETAIL_PAYMENT_REQUIRED = 'Payment Required — prepay a batch of requests and retry with the AIFP-Receipt header';
	public const DETAIL_QUOTA_EXHAUSTED  = 'quota exhausted — prepay the next batch';
	public const DETAIL_RECEIPT_EXPIRED  = 'receipt expired — prepay a new batch';
	public const DETAIL_VERIFY_FAILED    = 'receipt verification failed (signature/issuer/audience)';

	public function __construct(
		private KeySet $keys,
		private Meter $meter,
		private string $api,
		private string $issuer = Receipt::ISSUER
	) {}

	/**
	 * AIFP-License and Link rel="license" from a well-formed lic claim, else none.
	 * The URL is built from the API origin and merchant, never taken from the token.
	 */
	public static function licenseHeaders( string $api, string $merchant, mixed $lic ): array {
		if ( ! is_array( $lic ) || ! is_string( $lic['id'] ?? null ) || ! preg_match( '/^clic_[0-9a-f]{24}$/D', $lic['id'] )
			|| ! is_string( $lic['sha256'] ?? null ) || ! preg_match( '/^[0-9a-f]{64}$/D', $lic['sha256'] )
			|| ! is_int( $lic['rev'] ?? null ) || $lic['rev'] < 1 ) {
			return [];
		}
		$url = rtrim( $api, '/' ) . '/v1/merchants/' . rawurlencode( $merchant ) . '/license/' . $lic['rev'];
		return [
			'AIFP-License' => 'id=' . $lic['id'] . '; rev=' . $lic['rev'] . '; sha256=' . $lic['sha256'] . '; url="' . $url . '"',
			'Link'         => '<' . $url . '/text>; rel="license"',
		];
	}

	/** The 402 body, field for field what buildChallenge() emits. */
	public static function challenge( string $merchant, string $resource, string $tier, string $api, string $detail = '' ): array {
		$api = rtrim( $api, '/' );
		$min = Pricing::MIN_REQUESTS[ $tier ];
		return [
			'error'                     => 'AIFP-402',
			'detail'                    => '' !== $detail ? $detail : self::DETAIL_PAYMENT_REQUIRED,
			'protocol'                  => 'AIFP-1',
			'documentation_url'         => 'https://github.com/AiFinPay/sdk/blob/main/AGENT-FLOW.md',
			'instructions_url'          => 'https://raw.githubusercontent.com/AiFinPay/skill/main/agent/skills/aifinpay/SKILL.md', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- A URL in the protocol document for the agent to read; this site loads nothing from it.
			'merchant_instructions_url' => 'https://raw.githubusercontent.com/AiFinPay/skill/main/agent/skills/aifinpay-merchant/SKILL.md', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- A URL in the protocol document for the agent to read; this site loads nothing from it.
			'merchant_id'               => $merchant,
			'resource'                  => $resource,
			'tier'                      => $tier,
			'unit_weight'               => Pricing::TIER_WEIGHTS[ $tier ],
			'unit_price_usd'            => Pricing::UNIT_PRICE_USD[ $tier ],
			'min_requests'              => $min,
			'scope'                     => 'exact',
			'no_minimum_fee'            => true,
			'settlement_terms_from'     => "POST $api/v1/quote — returns accepted_chains, accepted_assets, amount, order_id and expiry",
			'how_to_pay'                => [
				"POST $api/v1/quote {\"merchant_id\":\"$merchant\",\"resource\":\"$resource\",\"tier\":\"$tier\",\"scope\":\"exact\",\"requests\":$min,\"payer\":\"<your wallet address>\"} — payer is required; raise requests to buy more than the minimum batch; check amount, scope, expiry and payer before paying",
				'settle the quoted batch on-chain from your own wallet (order_id = quote_id)',
				"POST $api/v1/pay {quote_id, chain, asset, tx_ref, payment_authorization} + Idempotency-Key header -> quota receipt (wallet-signature-v1). Keep the quote until you hold the receipt; on a timeout retry with the same key and tx_ref — never pay again",
				'retry this request with headers: AIFP-Receipt: <receipt JWT> and AIFP-Agent-Id: <the address that paid>',
			],
			'no_wallet'                 => 'npx @aifinpay/mcp init — creates or selects a local wallet; read instructions_url for supported clients and payment availability. Wallet setup alone does not enable settlement.',
		];
	}

	/** Port of scopeCovers() (gate/src/scope.ts): where one batch may be spent. */
	public static function scopeCovers( mixed $scope, mixed $resource, string $path ): bool {
		$resource = is_string( $resource ) ? $resource : '';
		if ( 'merchant' === $scope ) {
			return true;
		}
		if ( 'prefix' === $scope ) {
			if ( '/' === $resource || $path === $resource ) {
				return true;
			}
			$boundary = str_ends_with( $resource, '/' ) ? $resource : $resource . '/';
			// The SDKs buy "/paid/" for a "/paid/*" 402, and that wildcard covers "/paid" itself.
			return $path === substr( $boundary, 0, -1 ) || str_starts_with( $path, $boundary );
		}
		if ( ! str_ends_with( $resource, '/*' ) ) {
			return $path === $resource;
		}
		return $path === substr( $resource, 0, -2 ) || str_starts_with( $path, substr( $resource, 0, -1 ) );
	}

	/** EVM addresses compare without checksum case; Solana addresses exactly. */
	public static function samePayer( string $payer, ?string $agent ): bool {
		if ( null === $agent || '' === $agent ) {
			return false;
		}
		$evm = '/^0x[0-9a-fA-F]{40}$/D';
		return preg_match( $evm, $payer ) && preg_match( $evm, $agent ) ? strtolower( $payer ) === strtolower( $agent ) : $payer === $agent;
	}

	/**
	 * Shadow: serve, spend nothing. A presented receipt is verified as in
	 * enforce, so the report says what enforce would have answered.
	 *
	 * @return array{status: int, body: ?array, headers: array<string, string>, event: ?array, outcome: string, units: int}
	 */
	public function shadow( Request $request, string $merchant, ?string $network, string $rule, string $path, string $tier, int $now ): array {
		if ( AgentIdentity::isReader( $request ) ) {
			return self::result( 200, null, [], null, 'reader' );
		}
		$weight = Pricing::TIER_WEIGHTS[ $tier ];
		if ( '' === (string) $request->header( 'AIFP-Receipt' ) || ! preg_match( MerchantRecord::RE_MERCHANT, $merchant ) ) {
			return self::result( 200, null, [ 'AIFP-Shadow-Status' => '402' ], [ 'kind' => '402' ] + AgentIdentity::classify( $request ), 'shadow', $weight );
		}
		try {
			$would = $this->judge( $request, $merchant, $network, $rule, $path, $tier, $now, false );
		} catch ( \Throwable ) {
			return self::result( 200, null, [ 'AIFP-Shadow-Status' => '503' ], null, 'refused' );
		}
		$headers = 200 === $would['status'] ? [] : [ 'AIFP-Shadow-Status' => (string) $would['status'] ];
		return self::result( 200, null, $headers, $would['event'], null === $would['event'] ? 'refused' : 'shadow', $would['units'] );
	}

	/**
	 * Enforce. A reader is served before anything else is looked at, so no
	 * outage or missing approval can reach a person.
	 *
	 * @param array{approved: bool, host: ?string, network_mode: ?string, reason: string} $charging
	 * @return array{status: int, body: ?array, headers: array<string, string>, event: ?array, outcome: string, units: int}
	 */
	public function enforce( Request $request, string $merchant, array $charging, string $rule, string $path, string $tier, int $now ): array {
		if ( AgentIdentity::isReader( $request ) ) {
			return self::result( 200, null, [ 'AIFP-Paywall' => 'exempt' ], null, 'reader' );
		}
		if ( ! preg_match( MerchantRecord::RE_MERCHANT, $merchant ) || true !== $charging['approved']
			|| null === $charging['host'] || Request::normaliseHost( $request->host ) !== $charging['host'] ) {
			return self::result( 503, [ 'error' => 'enforcement_not_approved' ], [], null, 'refused' );
		}
		return $this->judge( $request, $merchant, $charging['network_mode'], $rule, $path, $tier, $now, true );
	}

	/** What the receipt gate answers this agent. With $spend false nothing is metered. */
	private function judge( Request $request, string $merchant, ?string $network, string $rule, string $path, string $tier, int $now, bool $spend ): array {
		$weight    = Pricing::TIER_WEIGHTS[ $tier ];
		$who       = AgentIdentity::classify( $request );
		$challenge = function ( string $detail = '' ) use ( &$who, $merchant, $rule, $tier, $weight ): array {
			return self::result( 402, self::challenge( $merchant, $rule, $tier, $this->api, $detail ), [], [ 'kind' => '402' ] + $who, '402', $weight );
		};
		$forbid    = static fn ( string $detail ): array => self::result( 403, [ 'error' => 'AIFP-403', 'detail' => $detail ], [], null, 'refused' );

		$token = $request->header( 'AIFP-Receipt' );
		if ( null === $token || '' === $token ) {
			return $challenge();
		}
		try {
			$p = $this->verified( $token, $merchant, $now );
		} catch ( \RuntimeException $e ) {
			if ( 'keys_unavailable' === $e->getMessage() ) {
				// Closed: an outage must not turn a paid page into a free one.
				return self::result(
					503,
					[ 'error' => 'AIFP-503-METER', 'detail' => 'receipt verification is temporarily unavailable — retry shortly' ],
					[ 'Retry-After' => '30' ],
					null,
					'refused'
				);
			}
			return 'expired' === $e->getMessage() ? $challenge( self::DETAIL_RECEIPT_EXPIRED ) : $forbid( self::DETAIL_VERIFY_FAILED );
		}
		$who = [ 'operator' => 'aifinpay', 'ua_class' => 'aifp_agent', 'verified' => true ];
		if ( isset( $p['typ_aifp'] ) && 'quota' !== $p['typ_aifp'] ) {
			return $forbid( 'receipt type "' . ( is_string( $p['typ_aifp'] ) ? $p['typ_aifp'] : '?' ) . '" is not spendable — this endpoint needs a quota receipt' );
		}
		if ( ! is_string( $p['receipt_id'] ?? null ) || '' === $p['receipt_id'] ) {
			return $forbid( 'receipt carries no receipt_id and cannot be metered' );
		}
		// A test site takes test receipts and a live site live ones, whatever the signature says.
		if ( null !== $network && ( 'test' === ( $p['network_mode'] ?? null ) ? 'test' : 'live' ) !== $network ) {
			return $forbid( 'receipt was paid on another network than this site charges on' );
		}
		$bought = $p['resource'] ?? null;
		if ( ! self::scopeCovers( $p['scope'] ?? null, is_string( $bought ) ? Paths::spelled( $bought ) : $bought, $path ) ) {
			return $forbid( 'receipt is scoped to ' . ( is_string( $p['resource'] ?? null ) ? $p['resource'] : '?' ) . ' (' . ( is_string( $p['scope'] ?? null ) && '' !== $p['scope'] ? $p['scope'] : 'exact' ) . '), not ' . $path );
		}
		// A payer-bound batch is spent only by the agent that paid for it.
		$payer = $p['payer'] ?? $p['sub'] ?? null;
		$agent = $request->header( 'AIFP-Agent-Id' );
		if ( is_string( $payer ) && '' !== $payer && ! self::samePayer( $payer, $agent ) ) {
			return $forbid(
				null === $agent || '' === $agent
					? 'this receipt is bound to the address that paid: retry with AIFP-Agent-Id set to it. Nothing was spent.'
					: 'AIFP-Agent-Id does not match the receipt payer'
			);
		}
		if ( isset( $p['unit_quota'] ) && ! is_int( $p['unit_quota'] ) ) {
			return $forbid( 'receipt carries no usable unit_quota' );
		}
		$limit = isset( $p['unit_quota'] )
			? $p['unit_quota']
			: ( is_int( $p['quota'] ?? null ) && $p['quota'] > 0 ? $p['quota'] : 1 ) * ( Pricing::TIER_WEIGHTS[ $p['tier'] ?? '' ] ?? 1 );
		if ( $limit < 1 ) {
			return $forbid( 'receipt carries no spendable units' );
		}
		if ( $limit <= 1 && ( ! is_string( $p['nonce'] ?? null ) || '' === $p['nonce'] ) ) {
			return $forbid( 'single-use receipt carries no nonce' );
		}
		if ( ! $spend ) {
			return self::result( 200, null, [], [ 'kind' => 'serve' ] + $who, 'paid', $weight );
		}
		try {
			$used = $this->meter->spend( $merchant, $p['receipt_id'], $weight, $limit, $p['exp'] + Receipt::CLOCK_TOLERANCE );
		} catch ( \Throwable ) {
			return self::result(
				503,
				[ 'error' => 'AIFP-503-METER', 'detail' => 'quota store unavailable — retry shortly' ],
				[ 'Retry-After' => '5' ],
				null,
				'refused'
			);
		}
		if ( null === $used ) {
			return $challenge( self::DETAIL_QUOTA_EXHAUSTED );
		}
		return self::result(
			200,
			null,
			[ 'AIFP-Quota-Remaining' => (string) ( $limit - $used ), 'AIFP-Receipt-Id' => $p['receipt_id'] ] + self::licenseHeaders( $this->api, $merchant, $p['lic'] ?? null ),
			[ 'kind' => 'serve' ] + $who,
			'paid',
			$weight
		);
	}

	/**
	 * @throws \RuntimeException 'keys_unavailable', 'expired' or 'invalid'.
	 */
	private function verified( string $token, string $merchant, int $now ): array {
		try {
			$keys = $this->keys->keys();
		} catch ( \Throwable ) {
			throw new \RuntimeException( 'keys_unavailable' );
		}
		try {
			return Receipt::verify( $token, $keys, $merchant, $this->issuer, $now );
		} catch ( \RuntimeException $e ) {
			if ( 'unknown_key' !== $e->getMessage() ) {
				throw $e;
			}
		}
		try {
			$fresh = $this->keys->refresh();
		} catch ( \Throwable ) {
			throw new \RuntimeException( 'keys_unavailable' );
		}
		if ( null === $fresh ) {
			throw new \RuntimeException( 'invalid' );
		}
		try {
			return Receipt::verify( $token, $fresh, $merchant, $this->issuer, $now );
		} catch ( \RuntimeException $e ) {
			throw new \RuntimeException( 'expired' === $e->getMessage() ? 'expired' : 'invalid' );
		}
	}

	private static function result( int $status, ?array $body, array $headers, ?array $event, string $outcome, int $units = 0 ): array {
		return compact( 'status', 'body', 'headers', 'event', 'outcome', 'units' );
	}
}
