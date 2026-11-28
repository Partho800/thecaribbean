<?php
/**
 * Guru Engine Frontend: /verify/ QR Endpoint, Single Listing Offers Tab & Digital Member Card.
 *
 * @package TheCaribbeanAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Guru_Engine_Frontend {

	/**
	 * Registers frontend hooks.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), PHP_INT_MAX );
		add_action( 'template_redirect', array( __CLASS__, 'handle_verify_route' ), 1 );
		add_shortcode( 'guru_listing_offers', array( __CLASS__, 'render_listing_offers_shortcode' ) );
		add_shortcode( 'guru_member_card', array( __CLASS__, 'render_member_card_shortcode' ) );
	}

	/**
	 * Enqueues Guru Engine CSS & JS on frontend pages.
	 */
	public static function enqueue_assets() {
		$plugin_file = dirname( __DIR__ ) . '/thecaribbean-button-colors.php';
		$css_rel     = 'assets/css/guru-engine.css';
		$js_rel      = 'assets/js/guru-engine.js';

		$css_ver = file_exists( dirname( __DIR__ ) . '/' . $css_rel ) ? (string) filemtime( dirname( __DIR__ ) . '/' . $css_rel ) : '1.0.0';
		$js_ver  = file_exists( dirname( __DIR__ ) . '/' . $js_rel ) ? (string) filemtime( dirname( __DIR__ ) . '/' . $js_rel ) : '1.0.0';

		wp_enqueue_style( 'guru-engine-css', plugin_dir_url( $plugin_file ) . $css_rel, array(), $css_ver );
		wp_enqueue_script( 'guru-engine-js', plugin_dir_url( $plugin_file ) . $js_rel, array(), $js_ver, true );
		wp_localize_script(
			'guru-engine-js',
			'GuruEngineConfig',
			array(
				'restUrl' => esc_url_raw( rest_url( 'guru/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Injects the Vouchers & Offers tab into My Listing single_page_options array.
	 *
	 * @param array $options Unserialized single_page_options.
	 * @return array
	 */
	public static function inject_vouchers_tab( $options ) {
		if ( ! is_array( $options ) || empty( $options['menu_items'] ) || ! is_array( $options['menu_items'] ) ) {
			return $options;
		}

		foreach ( $options['menu_items'] as $item ) {
			if ( isset( $item['slug'] ) && 'coupon' === $item['slug'] ) {
				return $options;
			}
		}

		$coupon_tab = array(
			'page'     => 'custom',
			'label'    => 'Coupon',
			'slug'     => 'coupon',
			'template' => 'full-width',
			'layout'   => array(
				array(
					'type'    => 'raw',
					'title'   => 'Explorer+ Coupon & Partner Privileges',
					'icon'    => 'mi local_offer',
					'content' => '[guru_listing_offers]',
				),
			),
		);

		$menu_items = array_values( $options['menu_items'] );
		array_splice( $menu_items, 2, 0, array( $coupon_tab ) );
		$options['menu_items'] = $menu_items;

		return $options;
	}

	/**
	 * Handles requests to /verify/?card=CG-XXXXXX.
	 */
	public static function handle_verify_route() {
		$req_uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$req_path = trim( (string) wp_parse_url( $req_uri, PHP_URL_PATH ), '/' );
		$base     = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$rel_path = $base && 0 === strpos( $req_path, $base ) ? trim( substr( $req_path, strlen( $base ) ), '/' ) : $req_path;

		if ( 'verify' !== $rel_path ) {
			return;
		}

		status_header( 200 );
		nocache_headers();

		$card_code  = isset( $_GET['card'] ) ? sanitize_text_field( wp_unslash( $_GET['card'] ) ) : '';
		$pre_lid    = isset( $_GET['listing_id'] ) ? absint( $_GET['listing_id'] ) : 0;
		$user       = Guru_Engine_DB::get_user_by_card_code( $card_code );
		$status     = $user ? Guru_Engine_DB::get_member_status( $user->ID ) : array(
			'valid'  => false,
			'reason' => empty( $card_code ) ? 'No Explorer+ Card Code provided.' : 'Invalid Explorer+ Card Code.',
		);

		$venues = array();
		if ( $status['valid'] ) {
			$listing_ids = get_posts(
				array(
					'post_type'      => 'job_listing',
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'     => '_guru_merchant_pin',
							'compare' => 'EXISTS',
						),
					),
				)
			);
			foreach ( $listing_ids as $lid ) {
				$venues[ $lid ] = Guru_Engine_DB::get_listing_offers( $lid );
			}
		}

		$plugin_file = dirname( __DIR__ ) . '/thecaribbean-button-colors.php';
		$css_url     = plugin_dir_url( $plugin_file ) . 'assets/css/guru-engine.css?ver=' . time();
		$js_url      = plugin_dir_url( $plugin_file ) . 'assets/js/guru-engine.js?ver=' . time();
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>Explorer+ Card Verification &mdash; <?php bloginfo( 'name' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>" />
</head>
<body class="guru-verify-body">
	<div class="guru-verify-wrap">
		<div class="guru-verify-brand">
			<strong>The Caribbean Guru</strong> &bull; Explorer+ Verification
		</div>

		<?php if ( ! $status['valid'] ) : ?>
			<div class="guru-card guru-card-red">
				<div class="guru-status-badge guru-badge-red">LOCKOUT &bull; INACTIVE</div>
				<h1><?php echo esc_html( $status['reason'] ); ?></h1>
				<?php if ( ! empty( $card_code ) ) : ?>
					<p class="guru-meta-line">Card ID: <code><?php echo esc_html( $card_code ); ?></code></p>
				<?php endif; ?>
				<p>Vouchers, Partner Privileges, and Guru Points cannot be redeemed with this card.</p>
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="guru-btn guru-btn-outline">Return to The Caribbean Guru</a>
			</div>
		<?php else : ?>
			<?php
			$first_name = get_user_meta( $user->ID, 'first_name', true );
			if ( empty( $first_name ) ) {
				$first_name = $user->display_name;
			}
			$avatar_url = get_avatar_url( $user->ID, array( 'size' => 160 ) );
			?>
			<div class="guru-card guru-card-green">
				<div class="guru-status-badge guru-badge-green">ACTIVE &bull; EXPLORER+</div>
				<div class="guru-member-header">
					<img src="<?php echo esc_url( $avatar_url ); ?>" alt="<?php echo esc_attr( $first_name ); ?>" class="guru-member-avatar" />
					<div>
						<h2><?php echo esc_html( $first_name ); ?></h2>
						<p class="guru-meta-line">Card ID: <strong><?php echo esc_html( $card_code ); ?></strong> &bull; Tier: <strong>Explorer+</strong></p>
					</div>
				</div>

				<?php if ( empty( $venues ) ) : ?>
					<div class="guru-alert guru-alert-warn">
						No merchant venues have a 4-digit Merchant PIN configured yet. Ask an administrator to set a Merchant PIN on a listing in WP Admin.
					</div>
				<?php else : ?>
					<form id="guru-verify-redeem-form" class="guru-redeem-form">
						<input type="hidden" name="card_code" value="<?php echo esc_attr( $card_code ); ?>" />
						<input type="hidden" name="redemption_method" value="qr_scan" />

						<div class="guru-field">
							<label for="guru_listing_id">Select Venue / Listing</label>
							<select id="guru_listing_id" name="listing_id" required>
								<?php foreach ( $venues as $lid => $v ) : ?>
									<option value="<?php echo (int) $lid; ?>" <?php selected( $pre_lid, $lid ); ?>>
										<?php echo esc_html( $v['listing_title'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="guru-field">
							<label>Select Offer</label>
							<div id="guru-offer-options" class="guru-offer-options"></div>
						</div>

						<div class="guru-field-row">
							<div class="guru-field">
								<label for="guru_purchase_amount">Total Bill Amount (USD)</label>
								<input type="number" step="0.01" min="0.01" id="guru_purchase_amount" name="purchase_amount_usd" required placeholder="e.g. 150.00" />
							</div>
							<div class="guru-field">
								<label for="guru_merchant_pin">4-Digit Merchant PIN</label>
								<input type="password" maxlength="4" pattern="\d{4}" inputmode="numeric" id="guru_merchant_pin" name="merchant_pin" required placeholder="****" />
							</div>
						</div>

						<div id="guru-redeem-feedback" class="guru-feedback" style="display:none;"></div>
						<button type="submit" class="guru-btn guru-btn-primary">Verify PIN &amp; Complete Redemption</button>
					</form>
					<script>
						window.GuruEngineConfig = {
							restUrl: <?php echo wp_json_encode( esc_url_raw( rest_url( 'guru/v1/' ) ) ); ?>,
							nonce: <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>,
							venues: <?php echo wp_json_encode( $venues ); ?>
						};
					</script>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
	<script src="<?php echo esc_url( $js_url ); ?>"></script>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Renders [guru_listing_offers] inside the single listing Vouchers & Offers tab.
	 *
	 * @return string
	 */
	public static function render_listing_offers_shortcode() {
		$listing_id = get_the_ID();
		if ( ! $listing_id || 'job_listing' !== get_post_type( $listing_id ) ) {
			return '';
		}

		$offers       = Guru_Engine_DB::get_listing_offers( $listing_id );
		$has_voucher  = ! empty( $offers['guru_voucher']['available'] );
		$has_partner  = ! empty( $offers['partner_privilege']['available'] );
		$is_logged_in = is_user_logged_in();
		$user_id      = get_current_user_id();
		$status       = $is_logged_in ? Guru_Engine_DB::get_member_status( $user_id ) : array( 'valid' => false );
		$card_code    = $is_logged_in ? (string) get_user_meta( $user_id, '_guru_card_code', true ) : '';

		ob_start();
		?>
		<div class="guru-listing-offers-wrap">
			<div class="guru-offers-grid">
				<?php if ( $has_voucher ) : ?>
					<div class="guru-offer-box guru-offer-layer1">
						<span class="guru-offer-tag">Layer 1 &bull; Guru-Sponsored Voucher</span>
						<h4>$<?php echo esc_html( number_format( (float) $offers['guru_voucher']['value_usd'], 2 ) ); ?> USD Off</h4>
						<p>
							<?php if ( $offers['guru_voucher']['min_spend_usd'] > 0 ) : ?>
								Minimum bill spend: <strong>$<?php echo esc_html( number_format( (float) $offers['guru_voucher']['min_spend_usd'], 2 ) ); ?> USD</strong>.
							<?php endif; ?>
							Redeemable once every 90 days per Explorer+ member.
						</p>
					</div>
				<?php endif; ?>

				<?php if ( $has_partner ) : ?>
					<div class="guru-offer-box guru-offer-layer2">
						<span class="guru-offer-tag">Layer 2 &bull; Partner Privilege</span>
						<h4>Evergreen Member Perk</h4>
						<p><?php echo esc_html( $offers['partner_privilege']['description'] ); ?></p>
					</div>
				<?php endif; ?>

				<div class="guru-offer-box guru-offer-points">
					<span class="guru-offer-tag">Guru Points</span>
					<h4>1 Point per $10 USD Spent</h4>
					<p>Earn Guru Points on every verified visit or purchase at this venue.</p>
				</div>
			</div>

			<?php if ( $is_logged_in && $status['valid'] && ! empty( $card_code ) ) : ?>
				<?php
				$card_page_url = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'explorer-card' ) : home_url( '/my-account-2/explorer-card/' );
				$points_bal    = (int) get_user_meta( $user_id, '_guru_points_balance', true );
				?>
				<div class="guru-member-status-bar">
					<div>
						<span class="guru-status-badge guru-badge-green" style="margin-bottom:0; margin-right:8px;">EXPLORER+ ACTIVE</span>
						<span class="guru-meta-line">Card: <strong><?php echo esc_html( $card_code ); ?></strong> &bull; Points: <strong><?php echo number_format( $points_bal ); ?></strong></span>
					</div>
					<a href="<?php echo esc_url( $card_page_url ); ?>" class="guru-btn guru-btn-outline">View My Explorer+ Card &amp; Details</a>
				</div>
				<?php if ( $offers['has_pin'] ) : ?>
					<div class="guru-device-redeem-box">
						<button type="button" class="guru-btn guru-btn-primary" id="guru-toggle-device-redeem">
							Redeem Voucher / Log Visit
						</button>

						<form id="guru-device-redeem-form" class="guru-redeem-form" style="display:none; margin-top:16px;">
							<input type="hidden" name="card_code" value="<?php echo esc_attr( $card_code ); ?>" />
							<input type="hidden" name="listing_id" value="<?php echo (int) $listing_id; ?>" />
							<input type="hidden" name="redemption_method" value="member_device_pin" />

							<p class="guru-staff-note">Hand your device to venue staff to select the offer and enter their 4-digit Merchant PIN.</p>

							<div class="guru-field">
								<label>Select Offer</label>
								<div class="guru-offer-options">
									<?php if ( $has_voucher ) : ?>
										<label class="guru-radio-card">
											<input type="radio" name="offer_type" value="guru_funded_voucher" checked />
											<span><strong>Guru-Sponsored Voucher</strong> &mdash; $<?php echo esc_html( number_format( (float) $offers['guru_voucher']['value_usd'], 2 ) ); ?> USD off (Min $<?php echo esc_html( number_format( (float) $offers['guru_voucher']['min_spend_usd'], 2 ) ); ?>)</span>
										</label>
									<?php endif; ?>
									<?php if ( $has_partner ) : ?>
										<label class="guru-radio-card">
											<input type="radio" name="offer_type" value="partner_perk" <?php checked( ! $has_voucher ); ?> />
											<span><strong>Partner Privilege</strong> &mdash; <?php echo esc_html( $offers['partner_privilege']['description'] ); ?></span>
										</label>
									<?php endif; ?>
									<label class="guru-radio-card">
										<input type="radio" name="offer_type" value="standard_purchase" <?php checked( ! $has_voucher && ! $has_partner ); ?> />
										<span><strong>Standard Purchase</strong> &mdash; Earn Guru Points (1 pt / $10 spent)</span>
									</label>
								</div>
							</div>

							<div class="guru-field-row">
								<div class="guru-field">
									<label for="guru_dev_amount">Total Purchase Amount (USD)</label>
									<input type="number" step="0.01" min="0.01" id="guru_dev_amount" name="purchase_amount_usd" required placeholder="e.g. 150.00" />
								</div>
								<div class="guru-field">
									<label for="guru_dev_pin">4-Digit Merchant PIN</label>
									<input type="password" maxlength="4" pattern="\d{4}" inputmode="numeric" id="guru_dev_pin" name="merchant_pin" required placeholder="****" />
								</div>
							</div>

							<div id="guru-device-feedback" class="guru-feedback" style="display:none;"></div>
							<button type="submit" class="guru-btn guru-btn-primary">Authorize &amp; Redeem</button>
						</form>
					</div>
				<?php else : ?>
					<p class="guru-pin-notice">In-venue PIN redemption is not yet configured for this listing.</p>
				<?php endif; ?>
			<?php else : ?>
				<div class="guru-upgrade-banner">
					<div>
						<strong>Explorer+ Members Only ($199 USD/yr):</strong> Upgrade to unlock Guru-Sponsored Vouchers, Partner Privileges, your Dynamic QR Card, and Guru Points.
					</div>
					<a href="<?php echo esc_url( add_query_arg( 'guru_upgrade', '1', home_url( '/' ) ) ); ?>" class="guru-btn guru-btn-primary">Upgrade to Explorer+</a>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Renders [guru_member_card] digital QR card, points balance & redemption history.
	 *
	 * @return string
	 */
	public static function render_member_card_shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<p>Please <a href="' . esc_url( wp_login_url() ) . '">log in</a> to view your Explorer+ Card &amp; Guru Points.</p>';
		}

		global $wpdb;
		$user_id   = get_current_user_id();
		$user      = wp_get_current_user();
		$status    = Guru_Engine_DB::get_member_status( $user_id );
		$card_code = (string) get_user_meta( $user_id, '_guru_card_code', true );
		$points    = (int) get_user_meta( $user_id, '_guru_points_balance', true );
		$lifetime  = (int) get_user_meta( $user_id, '_guru_points_lifetime', true );
		$verify_url = ! empty( $card_code ) ? home_url( '/verify/?card=' . rawurlencode( $card_code ) ) : '';

		$table = Guru_Engine_DB::table_name();
		$logs  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.*, p.post_title AS listing_title
				FROM {$table} r
				LEFT JOIN {$wpdb->posts} p ON p.ID = r.listing_post_id
				WHERE r.member_user_id = %d
				ORDER BY r.created_at DESC
				LIMIT 10",
				$user_id
			)
		);

		ob_start();
		?>
		<div class="guru-member-dashboard">
			<div class="guru-digital-card <?php echo $status['valid'] ? 'is-active' : 'is-inactive'; ?>">
				<div class="guru-dc-info">
					<span class="guru-offer-tag"><?php echo $status['valid'] ? 'EXPLORER+ ACTIVE' : 'MEMBERSHIP INACTIVE'; ?></span>
					<h3><?php echo esc_html( $user->display_name ); ?></h3>
					<p>Card ID: <strong><?php echo esc_html( $card_code ?: 'Not Issued' ); ?></strong></p>
					<?php if ( ! empty( $status['expiry'] ) ) : ?>
						<p>Valid Until: <strong><?php echo esc_html( gmdate( 'M j, Y', strtotime( $status['expiry'] ) ) ); ?></strong></p>
					<?php endif; ?>
					<div class="guru-points-row">
						<div><span>Points Balance</span><strong><?php echo number_format( $points ); ?></strong></div>
						<div><span>Lifetime Points</span><strong><?php echo number_format( $lifetime ); ?></strong></div>
					</div>
				</div>
				<?php if ( $status['valid'] && ! empty( $verify_url ) ) : ?>
					<div class="guru-dc-qr">
						<div class="guru-qr-box" data-qr-url="<?php echo esc_url( $verify_url ); ?>"></div>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $logs ) ) : ?>
				<h4 style="margin-top:24px;">Recent Redemptions &amp; Points History</h4>
				<table class="shop_table shop_table_responsive">
					<thead>
						<tr>
							<th>Reference</th>
							<th>Venue</th>
							<th>Offer</th>
							<th>Bill</th>
							<th>Points</th>
							<th>Date</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><code><?php echo esc_html( $log->reference_code ); ?></code></td>
								<td><?php echo esc_html( $log->listing_title ?: '#' . $log->listing_post_id ); ?></td>
								<td><?php echo esc_html( $log->offer_type ); ?></td>
								<td>$<?php echo esc_html( number_format( (float) $log->purchase_amount_usd, 2 ) ); ?></td>
								<td>+<?php echo (int) $log->points_awarded; ?></td>
								<td><?php echo esc_html( $log->created_at ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
