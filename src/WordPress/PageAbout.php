<?php

declare(strict_types=1);

namespace AiFinPay\WordPress;

defined( 'ABSPATH' ) || exit;

use AiFinPay\Core\About;
use AiFinPay\Core\PaidText;

/** What the page is, for the 402: the post title and the Excerpt the owner typed, never text taken from the post. */
final class PageAbout {

	public const FILTER = 'aifinpay_about';

	/** The 402 body with `about` added when there is something to say; any failure leaves the body as it was. */
	public static function add( ?array $body, array $settings ): ?array {
		if ( 'AIFP-402' !== ( $body['error'] ?? null ) ) {
			return $body;
		}
		try {
			$about = self::current( $settings );
		} catch ( \Throwable ) {
			return $body;
		}
		return null === $about ? $body : About::insert( $body, $about );
	}

	/** @return ?array{title?: string, description?: string} */
	public static function current( array $settings ): ?array {
		$post  = self::page();
		$given = null === $post ? [] : [ 'title' => $post->post_title, 'description' => self::summary( $post ) ];
		$about = About::build( $settings['about_pages'], apply_filters( self::FILTER, $given, $post ) );
		if ( null !== $post && isset( $about['description'] ) && PaidText::leaks( $about['description'], [ $post->post_content ] ) ) {
			unset( $about['description'] );
		}
		return [] === $about ? null : $about;
	}

	/** The post this request shows on its own; null for an archive, a feed or the REST API. */
	public static function page(): ?\WP_Post {
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() || ! is_singular() ) {
			return null;
		}
		$post = get_queried_object();
		return $post instanceof \WP_Post ? $post : null;
	}

	/** Only the Excerpt field as typed: WordPress's automatic excerpt is cut from the paid text. */
	public static function summary( \WP_Post $post ): string {
		return '' === $post->post_password ? $post->post_excerpt : '';
	}

	/** Whether this post's Excerpt is the description the 402 carries, so the page showing it does not leak it. */
	public static function publishes( \WP_Post $post ): bool {
		$page = self::page();
		if ( null === $page || $page->ID !== $post->ID ) {
			return false;
		}
		$description = self::current( Settings::get() )['description'] ?? null;
		return null !== $description && About::plain( self::summary( $post ), About::DESCRIPTION_MAX ) === $description;
	}
}
