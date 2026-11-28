<?php
/**
 * Guru Engine Database & Helper Layer.
 *
 * @package TheCaribbeanAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Guru_Engine_DB {

	const DB_VERSION = '1.0.0';

	/**
	 * Initializes DB hooks.
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_create_tables' ) );
	}

	/**
	 * Returns the full redemptions table name with WordPress prefix.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'guru_redemptions';
	}

	/**
	 * Creates custom tables if DB version is outdated.
	 */
	public static function maybe_create_tables() {
		if ( get_option( 'guru_engine_db_version' ) === self::DB_VERSION ) {
			return;
		}
		self::create_tables();
	}

	/**
	 * Runs dbDelta to create or upgrade wp_guru_redemptions.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			redemption_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			reference_code varchar(20) NOT NULL,
			member_user_id bigint(20) UNSIGNED NOT NULL,
			card_code varchar(32) NOT NULL,
			listing_post_id bigint(20) UNSIGNED NOT NULL,
			offer_type enum('guru_funded_voucher','partner_perk','standard_purchase') NOT NULL,
			voucher_value_usd decimal(10,2) NOT NULL DEFAULT '0.00',
			purchase_amount_usd decimal(10,2) NOT NULL DEFAULT '0.00',
			points_awarded int(11) NOT NULL DEFAULT 0,
			redemption_method enum('qr_scan','member_device_pin') NOT NULL,
			payout_status enum('not_applicable','pending_payout','paid') NOT NULL DEFAULT 'not_applicable',
			payout_batch_id varchar(50) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (redemption_id),
			UNIQUE KEY uk_reference_code (reference_code),
			KEY idx_member_user_id (member_user_id),
			KEY idx_listing_post_id (listing_post_id),
			KEY idx_card_code (card_code),
			KEY idx_payout_status (payout_status)
		) {$charset};";

		dbDelta( $sql );
		update_option( 'guru_engine_db_version', self::DB_VERSION );
	}

	/**
	 * Checks whether a user has an active, non-expired Explorer+ membership and active card.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array{valid:bool,reason:string,explorer_status:string,card_status:string,expiry:string}
	 */
	public static function get_member_status( $user_id ) {
		$explorer_status = (string) get_user_meta( $user_id, '_guru_explorer_status', true );
		$card_status     = (string) get_user_meta( $user_id, '_guru_card_status', true );
		$expiry          = (string) get_user_meta( $user_id, '_guru_subscription_expiry', true );

		if ( 'active' !== $explorer_status ) {
			return array(
				'valid'           => false,
				'reason'          => 'Membership Inactive — Upgrade to Unlock Vouchers & Points',
				'explorer_status' => $explorer_status ?: 'none',
				'card_status'     => $card_status ?: 'none',
				'expiry'          => $expiry,
			);
		}

		if ( ! empty( $expiry ) ) {
			$expiry_ts = strtotime( $expiry );
			if ( false !== $expiry_ts && $expiry_ts < time() ) {
				return array(
					'valid'           => false,
					'reason'          => 'Membership Expired — Renew Explorer+ to Unlock Vouchers & Points',
					'explorer_status' => 'expired',
					'card_status'     => $card_status ?: 'active',
					'expiry'          => $expiry,
				);
			}
		}

		if ( 'active' !== $card_status ) {
			$msg = ( 'lost_reissued' === $card_status )
				? 'Card Invalidated (Lost/Reissued) — Present your updated Explorer+ Card.'
				: 'Card Suspended or Inactive — Contact Support.';
			return array(
				'valid'           => false,
				'reason'          => $msg,
				'explorer_status' => $explorer_status,
				'card_status'     => $card_status ?: 'inactive',
				'expiry'          => $expiry,
			);
		}

		return array(
			'valid'           => true,
			'reason'          => '',
			'explorer_status' => $explorer_status,
			'card_status'     => $card_status,
			'expiry'          => $expiry,
		);
	}

	/**
	 * Finds a user by their Explorer+ card code.
	 *
	 * @param string $card_code Card code (e.g., CG-849201).
	 * @return WP_User|null
	 */
	public static function get_user_by_card_code( $card_code ) {
		$card_code = sanitize_text_field( $card_code );
		if ( empty( $card_code ) ) {
			return null;
		}

		$users = get_users(
			array(
				'meta_key'   => '_guru_card_code',
				'meta_value' => $card_code,
				'number'     => 1,
			)
		);

		return ! empty( $users ) ? $users[0] : null;
	}

	/**
	 * Generates a unique CG-XXXXXX card code.
	 *
	 * @return string
	 */
	public static function generate_unique_card_code() {
		for ( $i = 0; $i < 20; $i++ ) {
			$code = 'CG-' . wp_rand( 100000, 999999 );
			if ( null === self::get_user_by_card_code( $code ) ) {
				return $code;
			}
		}
		return 'CG-' . strtoupper( substr( wp_generate_password( 6, false, false ), 0, 6 ) );
	}

	/**
	 * Generates a unique GURU-XXXXX reference code.
	 *
	 * @return string
	 */
	public static function generate_unique_reference_code() {
		global $wpdb;
		$table = self::table_name();

		for ( $i = 0; $i < 15; $i++ ) {
			$code   = 'GURU-' . wp_rand( 10000, 99999 );
			$exists = $wpdb->get_var(
				$wpdb->prepare( "SELECT redemption_id FROM {$table} WHERE reference_code = %s LIMIT 1", $code )
			);
			if ( ! $exists ) {
				return $code;
			}
		}

		return 'GURU-' . strtoupper( wp_generate_password( 8, false, false ) );
	}

	/**
	 * Returns structured offer details for a listing.
	 *
	 * @param int $listing_id Listing post ID.
	 * @return array
	 */
	public static function get_listing_offers( $listing_id ) {
		$unit_value    = (float) get_post_meta( $listing_id, '_guru_voucher_unit_value', true );
		$min_spend     = (float) get_post_meta( $listing_id, '_guru_voucher_min_spend', true );
		$pool_remain   = (float) get_post_meta( $listing_id, '_guru_voucher_pool_remaining', true );
		$pool_total    = (float) get_post_meta( $listing_id, '_guru_voucher_pool_total', true );
		$partner_text  = trim( (string) get_post_meta( $listing_id, '_guru_partner_privilege_text', true ) );
		$has_pin       = ! empty( get_post_meta( $listing_id, '_guru_merchant_pin', true ) );

		$voucher_available = ( $unit_value > 0 && $pool_remain >= $unit_value );

		return array(
			'listing_id'        => (int) $listing_id,
			'listing_title'     => get_the_title( $listing_id ),
			'has_pin'           => $has_pin,
			'guru_voucher'      => array(
				'available'      => $voucher_available,
				'value_usd'      => $unit_value,
				'min_spend_usd'  => $min_spend,
				'pool_remaining' => $pool_remain,
				'pool_total'     => $pool_total,
			),
			'partner_privilege' => array(
				'available'   => ( '' !== $partner_text ),
				'description' => $partner_text,
			),
			'points_earn_rate'  => '1 point per $10 USD spent',
		);
	}
}
