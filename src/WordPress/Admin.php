<?php

declare(strict_types=1);

namespace AiFinPay\WordPress;

defined( 'ABSPATH' ) || exit;

use AiFinPay\Core\Eligibility;
use AiFinPay\Core\MerchantRecord;
use AiFinPay\Core\Paths;
use AiFinPay\Core\Pricing;

/** Settings → AiFinPay. */
final class Admin {

	private const SLUG       = 'aifinpay';
	private const SAVE       = 'aifinpay_save';
	private const DISCONNECT = 'aifinpay_disconnect';
	private const NOTICES    = 'aifinpay_notices_';

	public static function register( string $file ): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_post_' . self::SAVE, [ self::class, 'handleSave' ] );
		add_action( 'admin_post_' . self::DISCONNECT, [ self::class, 'handleDisconnect' ] );
		add_action( 'admin_init', [ self::class, 'privacyText' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( $file ), [ self::class, 'actionLinks' ] );
	}

	public static function menu(): void {
		add_options_page( 'AiFinPay', 'AiFinPay', 'manage_options', self::SLUG, [ self::class, 'render' ] );
	}

	public static function actionLinks( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'aifinpay' ) . '</a>' );
		return $links;
	}

	public static function privacyText(): void {
		wp_add_privacy_policy_content(
			'AiFinPay',
			wp_kses_post(
				'<p>' . __( 'AiFinPay counts requests from AI agents to the paths you protect. It stores no IP address, User-Agent or visitor identifier. If you connect the site and turn on reporting, counts per operator, agent class, path pattern and time are sent to AiFinPay. Readers are not reported.', 'aifinpay' ) . '</p>'
			)
		);
	}

	private static function url(): string {
		return admin_url( 'options-general.php?page=' . self::SLUG );
	}

	private static function notices( array $notices ): void {
		set_transient( self::NOTICES . get_current_user_id(), $notices, 60 );
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function handleDisconnect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'aifinpay' ), 403 );
		}
		check_admin_referer( self::DISCONNECT );
		Connection::delete();
		EventQueue::clear();
		delete_option( Resources::STATE );
		$settings = Settings::get();
		if ( 'enforce' === $settings['mode'] ) {
			$settings['mode'] = 'shadow';
			Settings::save( $settings );
			CacheCompat::purge();
		}
		self::notices( [ [ 'success', __( 'Disconnected. The secret was deleted from this site. Nothing is charged.', 'aifinpay' ) ] ] );
	}

	public static function handleSave(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'aifinpay' ), 403 );
		}
		check_admin_referer( self::SAVE );
		$notices = [];

		$code = isset( $_POST['connect_code'] ) ? sanitize_text_field( wp_unslash( $_POST['connect_code'] ) ) : '';
		if ( '' !== $code ) {
			try {
				$connection = Connection::redeem( $code );
				Connection::save( $connection['merchant_id'], $connection['secret'] );
				/* translators: %s: merchant ID. */
				$notices[] = [ 'success', sprintf( __( 'Connected as %s.', 'aifinpay' ), $connection['merchant_id'] ) ];
			} catch ( \RuntimeException $e ) {
				$notices[] = [ 'error', self::connectError( $e->getMessage() ) ];
			}
		}

		$before = Settings::get();
		$next   = $before;
		$tier   = isset( $_POST['tier'] ) ? sanitize_key( wp_unslash( $_POST['tier'] ) ) : '';
		if ( Pricing::isTier( $tier ) ) {
			$next['tier'] = $tier;
		}
		foreach ( [ 'protected_paths', 'excluded_paths' ] as $key ) {
			$parsed = Paths::parseLines( isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '' );
			if ( $parsed['invalid'] ) {
				/* translators: %s: the path patterns that were refused. */
				$notices[] = [ 'error', sprintf( __( 'Not saved, these are not valid paths: %s. Use one exact path or /prefix/* per line.', 'aifinpay' ), implode( ', ', $parsed['invalid'] ) ) ];
			} else {
				$next[ $key ] = array_slice( $parsed['valid'], 0, 50 );
			}
		}
		foreach ( [ 'charge_alternates', 'report_events', 'discovery_documents', 'discovery_link' ] as $key ) {
			$next[ $key ] = ! empty( $_POST[ $key ] );
		}
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'shadow';
		if ( in_array( $mode, [ 'off', 'shadow' ], true ) ) {
			$next['mode'] = $mode;
		} elseif ( 'enforce' === $mode ) {
			$problems = self::enforceProblems( $next, RecordStore::get( true ) );
			if ( $problems ) {
				foreach ( $problems as $problem ) {
					$notices[] = [ 'error', __( 'Enforce was not turned on: ', 'aifinpay' ) . self::reason( $problem ) ];
				}
			} else {
				$next['mode'] = 'enforce';
			}
		}

		Settings::save( $next );
		if ( ! $next['report_events'] ) {
			EventQueue::clear();
		}
		$changed = fn ( string $key ): bool => $before[ $key ] !== $next[ $key ];
		if ( $changed( 'mode' ) || $changed( 'protected_paths' ) || $changed( 'excluded_paths' ) || $changed( 'charge_alternates' ) ) {
			CacheCompat::purge();
		}
		Resources::sync( $next );
		$notices[] = [ 'success', __( 'Settings saved.', 'aifinpay' ) ];
		foreach ( Resources::failed() as $pattern ) {
			/* translators: %s: a path pattern. */
			$notices[] = [ 'warning', sprintf( __( 'AiFinPay did not accept %s yet. Requests to it are not reported until it does; the plugin retries daily.', 'aifinpay' ), $pattern ) ];
		}
		self::notices( $notices );
	}

	private static function enforceProblems( array $settings, ?array $record ): array {
		return Eligibility::problems(
			$settings['protected_paths'],
			(string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'' !== (string) get_option( 'permalink_structure' ),
			MerchantRecord::charging( Connection::merchantId(), $record )
		);
	}

	private static function connectError( string $code ): string {
		$messages = [
			'invalid_code' => __( 'This connect code is not valid: it is mistyped, expired or already used. Issue a new one in the AiFinPay dashboard.', 'aifinpay' ),
			'rate_limited' => __( 'Too many connect attempts from this server. Try again in a few minutes.', 'aifinpay' ),
		];
		return $messages[ $code ] ?? __( 'AiFinPay could not be reached. Nothing changed; try again.', 'aifinpay' );
	}

	private static function reason( string $code ): string {
		$reasons = [
			'not_connected'          => __( 'this site is not connected to AiFinPay.', 'aifinpay' ),
			'record_unavailable'     => __( 'the AiFinPay site record cannot be read right now.', 'aifinpay' ),
			'no_site_url'            => __( 'the site has no site URL in the AiFinPay dashboard.', 'aifinpay' ),
			'live_domain_unverified' => __( 'verify the domain of this site in the AiFinPay dashboard first.', 'aifinpay' ),
			'host_mismatch'          => __( 'this site’s address differs from the site URL in the AiFinPay dashboard.', 'aifinpay' ),
			'plain_permalinks'       => __( 'choose a permalink structure other than Plain: paths cannot be told apart without one.', 'aifinpay' ),
			'no_paths'               => __( 'list at least one protected path.', 'aifinpay' ),
			'all_paths'              => __( 'replace /* with the paths you want to charge for. A protected path cannot be served from a page cache, so /* would turn the page cache off for the whole site.', 'aifinpay' ),
		];
		return $reasons[ $code ] ?? $code;
	}

	private static function approval( array $charging ): string {
		$answers = [
			'test_merchant'        => __( 'Yes: test site, testnet payments only', 'aifinpay' ),
			'live_domain_verified' => __( 'Yes: live site, domain verified', 'aifinpay' ),
		];
		return $answers[ $charging['reason'] ] ?? __( 'No: ', 'aifinpay' ) . self::reason( $charging['reason'] );
	}

	/** @return array<string, string> Label => plain text. */
	public static function statusRows( array $settings ): array {
		$merchant = Connection::merchantId();
		$record   = '' !== $merchant ? RecordStore::get() : null;
		$charging = MerchantRecord::charging( $merchant, $record );
		$modes    = [
			'off'     => __( 'Off', 'aifinpay' ),
			'shadow'  => __( 'Shadow: observing, nothing is charged or blocked', 'aifinpay' ),
			'enforce' => __( 'Enforce: AI agents pay on protected paths', 'aifinpay' ),
		];
		$rows     = [
			__( 'Mode', 'aifinpay' )     => $modes[ $settings['mode'] ],
			__( 'Merchant', 'aifinpay' ) => '' === $merchant ? __( 'Not connected', 'aifinpay' ) : $merchant . ( isset( $record['name'] ) ? ' — ' . $record['name'] : '' ),
		];
		if ( '' !== $merchant ) {
			$rows[ __( 'Network', 'aifinpay' ) ]          = null === $record ? __( 'Unknown', 'aifinpay' ) : ( 'test' === $record['network_mode'] ? __( 'Test', 'aifinpay' ) : __( 'Live', 'aifinpay' ) );
			$rows[ __( 'Site URL', 'aifinpay' ) ]         = null === $record || null === $record['site_url'] ? __( 'Not set', 'aifinpay' ) : $record['site_url'] . ' ' . ( $record['site_verified'] ? __( '(domain verified)', 'aifinpay' ) : __( '(domain not verified)', 'aifinpay' ) );
			$rows[ __( 'Charging host', 'aifinpay' ) ]    = $charging['host'] ?? __( 'None', 'aifinpay' );
			$rows[ __( 'Charging approved', 'aifinpay' ) ] = self::approval( $charging );
			$state  = EventDelivery::state();
			$report = ! $settings['report_events'] ? __( 'Off', 'aifinpay' ) : sprintf(
				/* translators: 1: events waiting, 2: last delivery time. */
				__( '%1$d waiting; last delivered %2$s', 'aifinpay' ),
				EventQueue::count(),
				$state['last_delivered_at'] ? gmdate( 'Y-m-d H:i', (int) $state['last_delivered_at'] ) . ' UTC' : __( 'never', 'aifinpay' )
			);
			if ( in_array( $state['last_status'], [ 401, 403 ], true ) ) {
				$report .= ' — ' . __( 'AiFinPay rejected the credential: connect again with a new code.', 'aifinpay' );
			}
			$rows[ __( 'Reporting', 'aifinpay' ) ] = $report;
		}
		$caches = CacheCompat::active();
		if ( $caches ) {
			$rows[ __( 'Page cache', 'aifinpay' ) ] = implode( ', ', $caches ) . (
				'shadow' === $settings['mode'] && in_array( Paths::ALL, $settings['protected_paths'], true )
					? ' — ' . __( 'agents answered from the cache are not counted. List specific paths for exact counts.', 'aifinpay' )
					: ''
			);
		}
		return $rows;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = Settings::get();
		$notices  = get_transient( self::NOTICES . get_current_user_id() );
		delete_transient( self::NOTICES . get_current_user_id() );
		?>
		<div class="wrap">
			<h1>AiFinPay</h1>
			<?php foreach ( is_array( $notices ) ? $notices : [] as [ $type, $text ] ) : ?>
				<div class="notice notice-<?php echo esc_attr( $type ); ?>"><p><?php echo esc_html( $text ); ?></p></div>
			<?php endforeach; ?>

			<h2><?php esc_html_e( 'Status', 'aifinpay' ); ?></h2>
			<table class="widefat striped" id="aifinpay-status">
				<tbody>
				<?php foreach ( self::statusRows( $settings ) as $label => $value ) : ?>
					<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $value ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Agent traffic, last 30 days', 'aifinpay' ); ?></h2>
			<?php self::renderTraffic(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE ); ?>">
				<?php wp_nonce_field( self::SAVE ); ?>
				<h2><?php esc_html_e( 'Connect', 'aifinpay' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="aifinpay-connect-code"><?php esc_html_e( 'Connect code', 'aifinpay' ); ?></label></th>
						<td>
							<input type="text" id="aifinpay-connect-code" name="connect_code" class="regular-text" autocomplete="off" spellcheck="false" maxlength="40">
							<p class="description"><?php esc_html_e( 'In the AiFinPay dashboard (dash.aifinpay.io) open your site, then Settings → API keys → Connect a CMS site. On save this site fetches a new API secret and keeps it on this server; the previous secret stops working.', 'aifinpay' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Report to AiFinPay', 'aifinpay' ); ?></th>
						<td>
							<label><input type="checkbox" name="report_events" value="1" <?php checked( $settings['report_events'] ); ?>> <?php esc_html_e( 'Send agent requests to my AiFinPay dashboard', 'aifinpay' ); ?></label>
							<p class="description"><?php esc_html_e( 'One event per AI-agent request on a protected path: operator, agent class, path pattern and time. Never the User-Agent, IP address or page content. Readers are not reported.', 'aifinpay' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Gate', 'aifinpay' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Mode', 'aifinpay' ); ?></th>
						<td>
							<fieldset>
							<?php
							foreach ( [
								'shadow'  => __( 'Shadow: count AI agents and what they would pay. Every page is served as usual.', 'aifinpay' ),
								'enforce' => __( 'Enforce: AI agents on protected paths get HTTP 402 until they pay. Readers stay free. Those paths are no longer served from a page cache.', 'aifinpay' ),
								'off'     => __( 'Off', 'aifinpay' ),
							] as $value => $label ) :
								?>
								<label style="display:block;margin-bottom:.4em"><input type="radio" name="mode" value="<?php echo esc_attr( $value ); ?>" <?php checked( $settings['mode'], $value ); ?>> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aifinpay-tier"><?php esc_html_e( 'Price per request', 'aifinpay' ); ?></label></th>
						<td>
							<select id="aifinpay-tier" name="tier">
							<?php foreach ( Pricing::UNIT_PRICE_USD as $tier => $price ) : ?>
								<option value="<?php echo esc_attr( $tier ); ?>" <?php selected( $settings['tier'], $tier ); ?>><?php echo esc_html( ucfirst( $tier ) . ': $' . $price ); ?></option>
							<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aifinpay-protected"><?php esc_html_e( 'Protected paths', 'aifinpay' ); ?></label></th>
						<td>
							<textarea id="aifinpay-protected" name="protected_paths" rows="4" class="large-text code"><?php echo esc_textarea( implode( "\n", $settings['protected_paths'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One exact path or /prefix/* per line. /* observes the whole site in Shadow without touching your page cache. A specific path is never page-cached, in Shadow too, so its counts are exact.', 'aifinpay' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aifinpay-excluded"><?php esc_html_e( 'Excluded paths', 'aifinpay' ); ?></label></th>
						<td><textarea id="aifinpay-excluded" name="excluded_paths" rows="2" class="large-text code"><?php echo esc_textarea( implode( "\n", $settings['excluded_paths'] ) ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'REST API and feed', 'aifinpay' ); ?></th>
						<td>
							<label><input type="checkbox" name="charge_alternates" value="1" <?php checked( $settings['charge_alternates'] ); ?>> <?php esc_html_e( 'In Enforce, also charge AI agents on the REST content routes and the main feed', 'aifinpay' ); ?></label>
							<p class="description"><?php esc_html_e( 'The same posts are readable at these addresses. Without this an agent can skip the payment. Logged-in users and browsers are not charged.', 'aifinpay' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Agent documents', 'aifinpay' ); ?></th>
						<td>
							<label style="display:block"><input type="checkbox" name="discovery_documents" value="1" <?php checked( $settings['discovery_documents'] ); ?>> <?php esc_html_e( 'Serve /llms.txt and /.well-known/x402.json (a file of that name on the server takes precedence)', 'aifinpay' ); ?></label>
							<label style="display:block"><input type="checkbox" name="discovery_link" value="1" <?php checked( $settings['discovery_link'] ); ?>> <?php esc_html_e( 'Point agents to them from every HTML page', 'aifinpay' ); ?></label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<?php if ( null !== Connection::get() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::DISCONNECT ); ?>">
					<?php wp_nonce_field( self::DISCONNECT ); ?>
					<?php submit_button( __( 'Disconnect this site', 'aifinpay' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function renderTraffic(): void {
		$rows = TrafficLog::report();
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No AI-agent requests to protected paths yet. Counted on this site; nothing is sent anywhere unless you turn on reporting.', 'aifinpay' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped" id="aifinpay-traffic">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Operator', 'aifinpay' ); ?></th>
					<th><?php esc_html_e( 'Agent class', 'aifinpay' ); ?></th>
					<th><?php esc_html_e( 'Requests observed', 'aifinpay' ); ?></th>
					<th><?php esc_html_e( 'Answered with 402', 'aifinpay' ); ?></th>
					<th><?php esc_html_e( 'Paid requests', 'aifinpay' ); ?></th>
					<th><?php esc_html_e( 'Charged', 'aifinpay' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row['operator'] ); ?></td>
					<td><?php echo esc_html( $row['ua_class'] ); ?></td>
					<td><?php echo esc_html( (string) $row['observed'] ); ?></td>
					<td><?php echo esc_html( (string) $row['challenged'] ); ?></td>
					<td><?php echo esc_html( (string) $row['paid'] ); ?></td>
					<td>$<?php echo esc_html( $row['charged'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
