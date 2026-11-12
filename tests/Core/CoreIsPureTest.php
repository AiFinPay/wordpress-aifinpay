<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** src/Core is what moves to a shared package: it may call PHP and itself, nothing else. */
final class CoreIsPureTest extends TestCase {

	public function testCoreCallsNoFrameworkFunction(): void {
		$found = [];
		foreach ( glob( dirname( __DIR__, 2 ) . '/src/Core/*.php' ) as $file ) {
			$tokens = array_values( array_filter( token_get_all( (string) file_get_contents( $file ) ), static fn ( $t ) => ! is_array( $t ) || ! in_array( $t[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) );
			foreach ( $tokens as $i => $t ) {
				if ( ! is_array( $t ) || ! in_array( $t[0], [ T_STRING, T_NAME_FULLY_QUALIFIED ], true ) || '(' !== ( $tokens[ $i + 1 ] ?? null ) ) {
					continue;
				}
				$before = $tokens[ $i - 1 ] ?? null;
				if ( is_array( $before ) && in_array( $before[0], [ T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_FN ], true ) ) {
					continue;
				}
				$name = ltrim( $t[1], '\\' );
				if ( in_array( $name, [ 'defined' ], true ) ) {
					continue;
				}
				if ( ! function_exists( $name ) || ! ( new ReflectionFunction( $name ) )->isInternal() ) {
					$found[] = basename( $file ) . ': ' . $name;
				}
			}
		}
		self::assertSame( [], $found );
	}
}
