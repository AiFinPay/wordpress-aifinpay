<?php

declare(strict_types=1);

namespace AiFinPay\WordPress;

defined( 'ABSPATH' ) || exit;

use AiFinPay\Core\Resolver;
use AiFinPay\Core\SearchBot;
use AiFinPay\Core\SystemResolver;

/** SearchBot with its answers kept: a day verified, an hour refused, a minute after a DNS error. */
final class SearchBots {

	public const PREFIX = 'aifinpay_bot_';

	public static function category( string $user_agent, string $ip ): ?string {
		if ( null === SearchBot::claimed( $user_agent ) || null === SearchBot::normaliseIp( $ip ) ) {
			return null;
		}
		$key    = self::PREFIX . md5( (string) SearchBot::claimed( $user_agent ) . '|' . SearchBot::normaliseIp( $ip ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && array_key_exists( 'category', $cached ) ) {
			return is_string( $cached['category'] ) ? $cached['category'] : null;
		}
		$answer = SearchBot::verify( $user_agent, $ip, self::resolver() );
		if ( null === $answer ) {
			return null;
		}
		set_transient( $key, [ 'category' => $answer['category'] ], $answer['ttl'] );
		return $answer['category'];
	}

	private static function resolver(): Resolver {
		$resolver = apply_filters( 'aifinpay_dns_resolver', null );
		return $resolver instanceof Resolver ? $resolver : new SystemResolver();
	}
}
