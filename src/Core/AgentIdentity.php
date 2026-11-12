<?php

declare(strict_types=1);

namespace AiFinPay\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Who a request says it is. A copy of the classifier in @aifinpay/gate
 * (gate/src/agents.ts); the gate-events ingest accepts only these enums.
 * It reads what the client declares, so `verified` is never true here.
 */
final class AgentIdentity {

	public const OPERATORS = [
		'openai', 'anthropic', 'perplexity', 'google', 'microsoft', 'meta',
		'bytedance', 'apple', 'amazon', 'commoncrawl', 'aifinpay', 'unknown',
	];

	public const UA_CLASSES = [ 'ai_crawler', 'ai_agent_browser', 'aifp_agent', 'human', 'unknown' ];

	/** Lower-case User-Agent substring => [operator, ua_class]. First match wins. */
	public const UA_TABLE = [
		'gptbot'             => [ 'openai', 'ai_crawler' ],
		'oai-searchbot'      => [ 'openai', 'ai_crawler' ],
		'chatgpt-user'       => [ 'openai', 'ai_agent_browser' ],
		'claudebot'          => [ 'anthropic', 'ai_crawler' ],
		'claude-searchbot'   => [ 'anthropic', 'ai_crawler' ],
		'claude-user'        => [ 'anthropic', 'ai_agent_browser' ],
		'claude-web'         => [ 'anthropic', 'ai_agent_browser' ],
		'anthropic-ai'       => [ 'anthropic', 'ai_crawler' ],
		'perplexitybot'      => [ 'perplexity', 'ai_crawler' ],
		'perplexity-user'    => [ 'perplexity', 'ai_agent_browser' ],
		'ccbot'              => [ 'commoncrawl', 'ai_crawler' ],
		'bytespider'         => [ 'bytedance', 'ai_crawler' ],
		'google-extended'    => [ 'google', 'ai_crawler' ],
		'applebot-extended'  => [ 'apple', 'ai_crawler' ],
		'meta-externalagent' => [ 'meta', 'ai_crawler' ],
		'facebookbot'        => [ 'meta', 'ai_crawler' ],
		'amazonbot'          => [ 'amazon', 'ai_crawler' ],
		'youbot'             => [ 'unknown', 'ai_crawler' ],
		'diffbot'            => [ 'unknown', 'ai_crawler' ],
		'timpibot'           => [ 'unknown', 'ai_crawler' ],
		'omgilibot'          => [ 'unknown', 'ai_crawler' ],
		'cohere-ai'          => [ 'unknown', 'ai_agent_browser' ],
		'ai2bot'             => [ 'unknown', 'ai_crawler' ],
		'mistralai'          => [ 'unknown', 'ai_agent_browser' ],
		'aifinpay-agent'     => [ 'aifinpay', 'aifp_agent' ],
		'headlesschrome'     => [ 'unknown', 'unknown' ],
		'phantomjs'          => [ 'unknown', 'unknown' ],
	];

	/** @return array{operator: string, ua_class: string, verified: bool} */
	public static function classify( Request $request ): array {
		if ( '' !== (string) $request->header( 'AIFP-Receipt' ) || '' !== (string) $request->header( 'AIFP-Agent-Id' ) ) {
			return [ 'operator' => 'aifinpay', 'ua_class' => 'aifp_agent', 'verified' => false ];
		}
		$ua = strtolower( (string) $request->header( 'User-Agent' ) );
		if ( '' === $ua ) {
			return [ 'operator' => 'unknown', 'ua_class' => 'unknown', 'verified' => false ];
		}
		foreach ( self::UA_TABLE as $marker => [ $operator, $class ] ) {
			if ( str_contains( $ua, $marker ) ) {
				return [ 'operator' => $operator, 'ua_class' => $class, 'verified' => false ];
			}
		}
		return [ 'operator' => 'unknown', 'ua_class' => 'human', 'verified' => false ];
	}

	/** A browser or a logged-in user. Anything speaking the protocol is never a reader. */
	public static function isReader( Request $request ): bool {
		$class = self::classify( $request )['ua_class'];
		return 'human' === $class || ( $request->loggedIn && 'aifp_agent' !== $class );
	}
}
