<?php
/**
 * Guru Engine REST API Endpoints (/wp-json/guru/v1/*).
 *
 * @package TheCaribbeanAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Guru_Engine_REST {

	/**
	 * Registers REST API routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers verify-card and redeem endpoints.
	 */
	public static function register_routes() {
		register_rest_route(
			'guru/v1',
			'/verify-card/(?P<card_code>[A-Za-z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'verify_card' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'guru/v1',
			'/redeem',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'process_redemption' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET /wp-json/guru/v1/verify-card/{card_code}
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function verify_card( WP_REST_Request $request ) {
		$card_code  = sanitize_text_field( $request['card_code'] );
		$listing_id = absint( $request->get_param( 'listing_id' ) );

		$user = Guru_Engine_DB::get_user_by_card_code( $card_code );
		if ( ! $user ) {
			return new WP_Error( 'invalid_card', 'Invalid Explorer+ Card Code.', array( 'status' => 404 ) );
		}

		$status     = Guru_Engine_DB::get_member_status( $user->ID );
		$first_name = get_user_meta( $user->ID, 'first_name', true );
		if ( empty( $first_name ) ) {
			$first_name = $user->display_name;
		}

		if ( ! $status['valid'] ) {
			return new WP_REST_Response(
				array(
					'status' => 'inactive',
					'data'   => array(
						'card_code' => $card_code,
						'reason'    => $status['reason'],
						'member'    => array(
							'first_name'        => $first_name,
							'membership_status' => $status['explorer_status'],
							'card_status'       => $status['card_status'],
							'tier'              => 'Explorer+',
						),
					),
				),
				200
			);
		}

		$offers = array();
		if ( $listing_id > 0 ) {
			if ( 'job_listing' === get_post_type( $listing_id ) && 'publish' === get_post_status( $listing_id ) ) {
				$offers[] = Guru_Engine_DB::get_listing_offers( $listing_id );
			}
		} else {
			$listing_ids = get_posts(
				array(
					'post_type'      => 'job_listing',
					'post_status'    => 'publish',
					'posts_per_page' => 50,
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
				$offers[] = Guru_Engine_DB::get_listing_offers( $lid );
			}
		}

		return new WP_REST_Response(
			array(
				'status' => 'success',
				'data'   => array(
					'card_code'        => $card_code,
					'member'           => array(
						'first_name'        => $first_name,
						'avatar_url'        => get_avatar_url( $user->ID, array( 'size' => 160 ) ),
						'membership_status' => $status['explorer_status'],
						'card_status'       => $status['card_status'],
						'tier'              => 'Explorer+',
					),
					'available_offers' => $offers,
				),
			),
			200
		);
	}

	/**
	 * POST /wp-json/guru/v1/redeem
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function process_redemption( WP_REST_Request $request ) {
		global $wpdb;

		$card_code         = sanitize_text_field( (string) $request->get_param( 'card_code' ) );
		$listing_id        = absint( $request->get_param( 'listing_id' ) );
		$offer_type        = sanitize_key( (string) $request->get_param( 'offer_type' ) );
		$merchant_pin      = preg_replace( '/\D/', '', (string) $request->get_param( 'merchant_pin' ) );
		$purchase_usd      = round( (float) $request->get_param( 'purchase_amount_usd' ), 2 );
		$redemption_method = sanitize_key( (string) $request->get_param( 'redemption_method' ) );

		if ( empty( $redemption_method ) ) {
			$redemption_method = 'qr_scan';
		}

		if ( ! in_array( $offer_type, array( 'guru_funded_voucher', 'partner_perk', 'standard_purchase' ), true ) ) {
			return new WP_Error( 'invalid_offer_type', 'Invalid offer type selected.', array( 'status' => 400 ) );
		}

		if ( ! in_array( $redemption_method, array( 'qr_scan', 'member_device_pin' ), true ) ) {
			return new WP_Error( 'invalid_method', 'Invalid redemption method.', array( 'status' => 400 ) );
		}

		if ( $purchase_usd <= 0 ) {
			return new WP_Error( 'invalid_purchase_amount', 'Purchase amount must be greater than zero.', array( 'status' => 400 ) );
		}

		if ( 'job_listing' !== get_post_type( $listing_id ) || 'publish' !== get_post_status( $listing_id ) ) {
			return new WP_Error( 'invalid_listing', 'Selected venue listing is not valid or published.', array( 'status' => 404 ) );
		}

		// Rate-limit Merchant PIN brute-force attempts per IP + listing.
		$client_ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$rate_key      = 'guru_pin_fail_' . md5( $client_ip . '_' . $listing_id );
		$fail_attempts = (int) get_transient( $rate_key );
		if ( $fail_attempts >= 5 ) {
			return new WP_Error( 'too_many_attempts', 'Too many incorrect PIN attempts. Please try again in 15 minutes.', array( 'status' => 429 ) );
		}

		// 1. PIN Verification.
		$stored_pin_hash = (string) get_post_meta( $listing_id, '_guru_merchant_pin', true );
		if ( empty( $stored_pin_hash ) || strlen( $merchant_pin ) !== 4 || ! wp_check_password( $merchant_pin, $stored_pin_hash ) ) {
			set_transient( $rate_key, $fail_attempts + 1, 15 * MINUTE_IN_SECONDS );
			return new WP_Error( 'invalid_pin', 'Incorrect 4-digit Merchant PIN.', array( 'status' => 403 ) );
		}
		delete_transient( $rate_key );

		// 2. Resolve User by Card Code.
		$user = Guru_Engine_DB::get_user_by_card_code( $card_code );
		if ( ! $user ) {
			return new WP_Error( 'invalid_card', 'Invalid Explorer+ Card Code.', array( 'status' => 404 ) );
		}

		// Member device fallback must belong to the authenticated user (unless admin).
		if ( 'member_device_pin' === $redemption_method ) {
			if ( ! is_user_logged_in() || ( get_current_user_id() !== (int) $user->ID && ! current_user_can( 'manage_options' ) ) ) {
				return new WP_Error( 'unauthorized_member', 'You must be logged in as the cardholder for device PIN redemption.', array( 'status' => 403 ) );
			}
		}

		// 3. Subscription & Card Status Check.
		$status = Guru_Engine_DB::get_member_status( $user->ID );
		if ( ! $status['valid'] ) {
			return new WP_Error( 'inactive_member', $status['reason'], array( 'status' => 403 ) );
		}

		$table = Guru_Engine_DB::table_name();

		// 4. Layer 1 Guardrails: Partner Perk / Voucher checks.
		if ( 'partner_perk' === $offer_type ) {
			$partner_text = trim( (string) get_post_meta( $listing_id, '_guru_partner_privilege_text', true ) );
			if ( '' === $partner_text ) {
				return new WP_Error( 'no_partner_perk', 'Partner Privilege is not configured for this venue.', array( 'status' => 400 ) );
			}
		}

		if ( 'guru_funded_voucher' === $offer_type ) {
			$min_spend = (float) get_post_meta( $listing_id, '_guru_voucher_min_spend', true );
			if ( $min_spend > 0 && $purchase_usd < $min_spend ) {
				return new WP_Error(
					'min_spend_not_met',
					sprintf( 'Minimum spend of $%s USD is required for this Guru Voucher.', number_format( $min_spend, 2 ) ),
					array( 'status' => 400 )
				);
			}

			// 90-day per-member, per-listing cooldown.
			$cooldown_days     = 90;
			$recent_redemption = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table}
					WHERE member_user_id = %d
					  AND listing_post_id = %d
					  AND offer_type = 'guru_funded_voucher'
					  AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
					$user->ID,
					$listing_id,
					$cooldown_days
				)
			);

			if ( $recent_redemption > 0 ) {
				return new WP_Error( 'cooldown_active', 'Guru Voucher already redeemed at this venue within 90 days.', array( 'status' => 429 ) );
			}
		}

		// 5. Database Transaction & Row-Level Locking.
		$wpdb->query( 'START TRANSACTION' );

		$voucher_usd   = 0.00;
		$payout_status = 'not_applicable';

		if ( 'guru_funded_voucher' === $offer_type ) {
			$unit_val = round( (float) get_post_meta( $listing_id, '_guru_voucher_unit_value', true ), 2 );
			if ( $unit_val <= 0 ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'voucher_not_configured', 'Guru Voucher value is not configured for this venue.', array( 'status' => 400 ) );
			}

			$rem_balance = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value FROM {$wpdb->postmeta}
					WHERE post_id = %d AND meta_key = '_guru_voucher_pool_remaining'
					FOR UPDATE",
					$listing_id
				)
			);

			if ( null === $rem_balance || (float) $rem_balance < $unit_val ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'pool_depleted', 'Guru Voucher pool for this listing has been exhausted.', array( 'status' => 400 ) );
			}

			$new_balance = round( (float) $rem_balance - $unit_val, 2 );
			update_post_meta( $listing_id, '_guru_voucher_pool_remaining', number_format( $new_balance, 2, '.', '' ) );
			$voucher_usd   = $unit_val;
			$payout_status = 'pending_payout';
		}

		$ref_code = Guru_Engine_DB::generate_unique_reference_code();
		$points   = (int) floor( $purchase_usd / 10 );

		$inserted = $wpdb->insert(
			$table,
			array(
				'reference_code'      => $ref_code,
				'member_user_id'      => (int) $user->ID,
				'card_code'           => $card_code,
				'listing_post_id'     => $listing_id,
				'offer_type'          => $offer_type,
				'voucher_value_usd'   => number_format( $voucher_usd, 2, '.', '' ),
				'purchase_amount_usd' => number_format( $purchase_usd, 2, '.', '' ),
				'points_awarded'      => $points,
				'redemption_method'   => $redemption_method,
				'payout_status'       => $payout_status,
				'created_at'          => current_time( 'mysql', 1 ),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', 'Failed to record redemption transaction.', array( 'status' => 500 ) );
		}

		// Update Points.
		$current_points = (int) get_user_meta( $user->ID, '_guru_points_balance', true );
		$lifetime_pts   = (int) get_user_meta( $user->ID, '_guru_points_lifetime', true );
		$new_balance    = $current_points + $points;
		update_user_meta( $user->ID, '_guru_points_balance', $new_balance );
		update_user_meta( $user->ID, '_guru_points_lifetime', $lifetime_pts + $points );

		$wpdb->query( 'COMMIT' );

		return new WP_REST_Response(
			array(
				'status'              => 'success',
				'reference_code'      => $ref_code,
				'voucher_value_usd'   => $voucher_usd,
				'purchase_amount_usd' => $purchase_usd,
				'points_awarded'      => $points,
				'points_balance'      => $new_balance,
				'message'             => 'Redemption verified and logged successfully.',
			),
			200
		);
	}
}
