<?php
/**
 * Guru Engine Admin: Listing Meta Box, User Profile Fields & Merchant Payouts Dashboard.
 *
 * @package TheCaribbeanAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Guru_Engine_Admin {

	/**
	 * Registers admin hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_listing_meta_box' ) );
		add_action( 'save_post_job_listing', array( __CLASS__, 'save_listing_meta' ), 10, 2 );

		add_action( 'show_user_profile', array( __CLASS__, 'render_user_profile_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_user_profile_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_user_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_user_profile_fields' ) );

		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
		add_action( 'admin_post_guru_export_payouts_csv', array( __CLASS__, 'handle_export_csv' ) );
		add_action( 'admin_post_guru_mark_batch_paid', array( __CLASS__, 'handle_mark_batch_paid' ) );
	}

	/**
	 * Adds the Guru Engine meta box to job_listing edit screens.
	 */
	public static function register_listing_meta_box() {
		add_meta_box(
			'guru_engine_listing_offers',
			__( 'Guru Engine — Offers, Voucher Pool & Merchant PIN', 'thecaribbean-addon' ),
			array( __CLASS__, 'render_listing_meta_box' ),
			'job_listing',
			'normal',
			'high'
		);
	}

	/**
	 * Renders listing offer & PIN fields.
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render_listing_meta_box( $post ) {
		wp_nonce_field( 'guru_save_listing_meta', 'guru_listing_meta_nonce' );

		$has_pin      = ! empty( get_post_meta( $post->ID, '_guru_merchant_pin', true ) );
		$annual_fee   = get_post_meta( $post->ID, '_guru_annual_fee_usd', true );
		$pool_total   = (float) get_post_meta( $post->ID, '_guru_voucher_pool_total', true );
		$pool_remain  = get_post_meta( $post->ID, '_guru_voucher_pool_remaining', true );
		$unit_value   = get_post_meta( $post->ID, '_guru_voucher_unit_value', true );
		$min_spend    = get_post_meta( $post->ID, '_guru_voucher_min_spend', true );
		$partner_text = get_post_meta( $post->ID, '_guru_partner_privilege_text', true );
		?>
		<style>
			.guru-mb-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: 12px; }
			.guru-mb-field label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 4px; color: #1d2327; }
			.guru-mb-field input { width: 100%; box-sizing: border-box; }
			.guru-mb-badge { font-size: 11px; font-weight: 600; margin-left: 4px; }
		</style>
		<div class="guru-mb-grid">
			<div class="guru-mb-field">
				<label for="guru_merchant_pin">
					Merchant PIN (4-digit)
					<?php echo $has_pin ? '<span class="guru-mb-badge" style="color:#0a7d32;">(Set)</span>' : '<span class="guru-mb-badge" style="color:#b32d2e;">(Not set)</span>'; ?>
				</label>
				<input type="password" id="guru_merchant_pin" name="guru_merchant_pin" value="" maxlength="4" pattern="\d{4}" autocomplete="new-password" placeholder="<?php echo $has_pin ? '•••• (keep)' : 'e.g. 4921'; ?>" />
			</div>
			<div class="guru-mb-field">
				<label for="guru_annual_fee_usd">Annual Fee (USD)</label>
				<input type="number" step="0.01" min="0" id="guru_annual_fee_usd" name="guru_annual_fee_usd" value="<?php echo esc_attr( $annual_fee ); ?>" placeholder="1800" />
			</div>
			<div class="guru-mb-field">
				<label for="guru_voucher_unit_value">Voucher Value (USD)</label>
				<input type="number" step="0.01" min="0" id="guru_voucher_unit_value" name="guru_voucher_unit_value" value="<?php echo esc_attr( $unit_value ); ?>" placeholder="30" />
			</div>
			<div class="guru-mb-field">
				<label for="guru_voucher_min_spend">Min Spend (USD)</label>
				<input type="number" step="0.01" min="0" id="guru_voucher_min_spend" name="guru_voucher_min_spend" value="<?php echo esc_attr( $min_spend ); ?>" placeholder="100" />
			</div>
			<div class="guru-mb-field">
				<label for="guru_voucher_pool_remaining">
					Pool Remaining (USD)
					<span class="guru-mb-badge" style="color:#646970;">(20% = $<?php echo esc_html( number_format( $pool_total, 2 ) ); ?>)</span>
				</label>
				<input type="number" step="0.01" min="0" id="guru_voucher_pool_remaining" name="guru_voucher_pool_remaining" value="<?php echo esc_attr( $pool_remain ); ?>" placeholder="Auto from 20% fee" />
			</div>
		</div>
		<div class="guru-mb-field">
			<label for="guru_partner_privilege_text">Partner Privilege (Layer 2 &mdash; Evergreen Perk)</label>
			<input type="text" id="guru_partner_privilege_text" name="guru_partner_privilege_text" value="<?php echo esc_attr( $partner_text ); ?>" placeholder="e.g. 10% off total bill &amp; complimentary welcome cocktail" />
		</div>
		<?php
	}

	/**
	 * Saves listing offer & PIN meta.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_listing_meta( $post_id, $post ) {
		if ( ! isset( $_POST['guru_listing_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['guru_listing_meta_nonce'] ) ), 'guru_save_listing_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! empty( $_POST['guru_merchant_pin'] ) ) {
			$pin = preg_replace( '/\D/', '', wp_unslash( $_POST['guru_merchant_pin'] ) );
			if ( strlen( $pin ) === 4 ) {
				update_post_meta( $post_id, '_guru_merchant_pin', wp_hash_password( $pin ) );
			}
		}

		$annual_fee = isset( $_POST['guru_annual_fee_usd'] ) ? round( (float) $_POST['guru_annual_fee_usd'], 2 ) : 0.00;
		$pool_total = round( $annual_fee * 0.20, 2 );

		$prev_pool_total = (float) get_post_meta( $post_id, '_guru_voucher_pool_total', true );
		$pool_remain_in  = isset( $_POST['guru_voucher_pool_remaining'] ) && '' !== $_POST['guru_voucher_pool_remaining']
			? round( (float) $_POST['guru_voucher_pool_remaining'], 2 )
			: null;

		if ( null === $pool_remain_in && $pool_total > 0 && $prev_pool_total <= 0 ) {
			$pool_remain_in = $pool_total;
		}

		update_post_meta( $post_id, '_guru_annual_fee_usd', number_format( $annual_fee, 2, '.', '' ) );
		update_post_meta( $post_id, '_guru_voucher_pool_total', number_format( $pool_total, 2, '.', '' ) );

		if ( null !== $pool_remain_in ) {
			update_post_meta( $post_id, '_guru_voucher_pool_remaining', number_format( max( 0, $pool_remain_in ), 2, '.', '' ) );
		}

		$unit_val  = isset( $_POST['guru_voucher_unit_value'] ) ? round( (float) $_POST['guru_voucher_unit_value'], 2 ) : 0.00;
		$min_spend = isset( $_POST['guru_voucher_min_spend'] ) ? round( (float) $_POST['guru_voucher_min_spend'], 2 ) : 0.00;

		update_post_meta( $post_id, '_guru_voucher_unit_value', number_format( max( 0, $unit_val ), 2, '.', '' ) );
		update_post_meta( $post_id, '_guru_voucher_min_spend', number_format( max( 0, $min_spend ), 2, '.', '' ) );

		$partner_text = isset( $_POST['guru_partner_privilege_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['guru_partner_privilege_text'] ) ) : '';
		update_post_meta( $post_id, '_guru_partner_privilege_text', $partner_text );
	}

	/**
	 * Renders Explorer+ membership, card & points fields on WP User Profile.
	 *
	 * @param WP_User $user User object.
	 */
	public static function render_user_profile_fields( $user ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$explorer_status = get_user_meta( $user->ID, '_guru_explorer_status', true );
		$expiry          = get_user_meta( $user->ID, '_guru_subscription_expiry', true );
		$card_code       = get_user_meta( $user->ID, '_guru_card_code', true );
		$card_status     = get_user_meta( $user->ID, '_guru_card_status', true );
		$points_balance  = (int) get_user_meta( $user->ID, '_guru_points_balance', true );
		$points_lifetime = (int) get_user_meta( $user->ID, '_guru_points_lifetime', true );
		?>
		<h2>Guru Engine — Explorer+ Membership, QR Card &amp; Points</h2>
		<table class="form-table">
			<tr>
				<th><label for="guru_explorer_status">Explorer+ Status</label></th>
				<td>
					<select name="guru_explorer_status" id="guru_explorer_status">
						<option value="" <?php selected( $explorer_status, '' ); ?>>Free User</option>
						<option value="active" <?php selected( $explorer_status, 'active' ); ?>>Active</option>
						<option value="expired" <?php selected( $explorer_status, 'expired' ); ?>>Expired</option>
						<option value="cancelled" <?php selected( $explorer_status, 'cancelled' ); ?>>Cancelled</option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="guru_subscription_expiry">Subscription Expiry (YYYY-MM-DD HH:MM:SS)</label></th>
				<td>
					<input type="text" name="guru_subscription_expiry" id="guru_subscription_expiry" value="<?php echo esc_attr( $expiry ); ?>" class="regular-text" placeholder="2027-12-31 23:59:59" />
				</td>
			</tr>
			<tr>
				<th><label for="guru_card_code">Explorer+ Card Code</label></th>
				<td>
					<input type="text" name="guru_card_code" id="guru_card_code" value="<?php echo esc_attr( $card_code ); ?>" class="regular-text" placeholder="CG-849201 (auto-generated if Active &amp; blank)" />
					<?php if ( ! empty( $card_code ) ) : ?>
						<p class="description">Verification URL: <a href="<?php echo esc_url( home_url( '/verify/?card=' . rawurlencode( $card_code ) ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/verify/?card=' . $card_code ) ); ?></a></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="guru_card_status">Card Status</label></th>
				<td>
					<select name="guru_card_status" id="guru_card_status">
						<option value="active" <?php selected( $card_status, 'active' ); ?>>Active</option>
						<option value="lost_reissued" <?php selected( $card_status, 'lost_reissued' ); ?>>Lost / Reissued (Invalidated)</option>
						<option value="suspended" <?php selected( $card_status, 'suspended' ); ?>>Suspended</option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="guru_points_balance">Guru Points Balance</label></th>
				<td>
					<input type="number" name="guru_points_balance" id="guru_points_balance" value="<?php echo esc_attr( $points_balance ); ?>" class="small-text" />
				</td>
			</tr>
			<tr>
				<th><label for="guru_points_lifetime">Lifetime Guru Points</label></th>
				<td>
					<input type="number" name="guru_points_lifetime" id="guru_points_lifetime" value="<?php echo esc_attr( $points_lifetime ); ?>" class="small-text" />
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Saves Explorer+ user meta fields.
	 *
	 * @param int $user_id User ID.
	 */
	public static function save_user_profile_fields( $user_id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$explorer_status = isset( $_POST['guru_explorer_status'] ) ? sanitize_key( wp_unslash( $_POST['guru_explorer_status'] ) ) : '';
		$expiry          = isset( $_POST['guru_subscription_expiry'] ) ? sanitize_text_field( wp_unslash( $_POST['guru_subscription_expiry'] ) ) : '';
		$card_code       = isset( $_POST['guru_card_code'] ) ? sanitize_text_field( wp_unslash( $_POST['guru_card_code'] ) ) : '';
		$card_status     = isset( $_POST['guru_card_status'] ) ? sanitize_key( wp_unslash( $_POST['guru_card_status'] ) ) : 'active';
		$points_balance  = isset( $_POST['guru_points_balance'] ) ? (int) $_POST['guru_points_balance'] : 0;
		$points_lifetime = isset( $_POST['guru_points_lifetime'] ) ? (int) $_POST['guru_points_lifetime'] : 0;

		if ( 'active' === $explorer_status && empty( $card_code ) ) {
			$card_code = Guru_Engine_DB::generate_unique_card_code();
		}

		update_user_meta( $user_id, '_guru_explorer_status', $explorer_status );
		update_user_meta( $user_id, '_guru_subscription_expiry', $expiry );
		update_user_meta( $user_id, '_guru_card_code', $card_code );
		update_user_meta( $user_id, '_guru_card_status', $card_status );
		update_user_meta( $user_id, '_guru_points_balance', max( 0, $points_balance ) );
		update_user_meta( $user_id, '_guru_points_lifetime', max( 0, $points_lifetime ) );
	}

	/**
	 * Registers Guru Engine admin menu and Merchant Payouts submenu.
	 */
	public static function register_admin_menu() {
		add_menu_page(
			'Guru Engine',
			'Guru Engine',
			'manage_options',
			'guru-engine-payouts',
			array( __CLASS__, 'render_payouts_page' ),
			'dashicons-tickets-alt',
			30
		);

		add_submenu_page(
			'guru-engine-payouts',
			'Merchant Payouts',
			'Merchant Payouts',
			'manage_options',
			'guru-engine-payouts',
			array( __CLASS__, 'render_payouts_page' )
		);
	}

	/**
	 * Fetches pending payout summary grouped by listing.
	 *
	 * @return array
	 */
	private static function get_pending_payout_rows() {
		global $wpdb;
		$table = Guru_Engine_DB::table_name();

		return $wpdb->get_results(
			"SELECT
				r.listing_post_id,
				p.post_title AS listing_title,
				COUNT(*) AS redemption_count,
				AVG(r.voucher_value_usd) AS unit_value_usd,
				SUM(r.voucher_value_usd) AS total_due_usd
			FROM {$table} r
			LEFT JOIN {$wpdb->posts} p ON p.ID = r.listing_post_id
			WHERE r.offer_type = 'guru_funded_voucher'
			  AND r.payout_status = 'pending_payout'
			GROUP BY r.listing_post_id, p.post_title
			ORDER BY total_due_usd DESC"
		);
	}

	/**
	 * Renders the Merchant Payouts, Redemptions Log & Settings dashboard.
	 */
	public static function render_payouts_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		$table         = Guru_Engine_DB::table_name();
		$pending_rows  = self::get_pending_payout_rows();
		$total_pending = 0.00;
		foreach ( $pending_rows as $row ) {
			$total_pending += (float) $row->total_due_usd;
		}

		$recent_logs = $wpdb->get_results(
			"SELECT r.*, p.post_title AS listing_title, u.user_login
			FROM {$table} r
			LEFT JOIN {$wpdb->posts} p ON p.ID = r.listing_post_id
			LEFT JOIN {$wpdb->users} u ON u.ID = r.member_user_id
			ORDER BY r.created_at DESC
			LIMIT 25"
		);
		?>
		<div class="wrap">
			<h1>Guru Engine &mdash; Merchant Payouts &amp; Redemptions</h1>

			<?php if ( ! empty( $_GET['batch_paid'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Batch <strong><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['batch_paid'] ) ) ); ?></strong> marked as paid.</p></div>
			<?php endif; ?>

			<h2>Merchant Settlement Report (Pending Batch)</h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>Listing Title</th>
						<th>Post ID</th>
						<th>Redemptions</th>
						<th>Unit Value (USD)</th>
						<th>Total Due (USD)</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $pending_rows ) ) : ?>
						<tr><td colspan="5">No pending Guru-funded voucher payouts.</td></tr>
					<?php else : ?>
						<?php foreach ( $pending_rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row->listing_title ?: '(Deleted Listing)' ); ?></td>
								<td><?php echo (int) $row->listing_post_id; ?></td>
								<td><?php echo (int) $row->redemption_count; ?></td>
								<td>$<?php echo esc_html( number_format( (float) $row->unit_value_usd, 2 ) ); ?></td>
								<td><strong>$<?php echo esc_html( number_format( (float) $row->total_due_usd, 2 ) ); ?></strong></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
				<tfoot>
					<tr>
						<th colspan="4" style="text-align:right;">TOTAL PENDING REIMBURSEMENTS:</th>
						<th>$<?php echo esc_html( number_format( $total_pending, 2 ) ); ?></th>
					</tr>
				</tfoot>
			</table>

			<div style="display:flex; gap:16px; margin:16px 0; align-items:center; flex-wrap:wrap;">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="guru_export_payouts_csv" />
					<?php wp_nonce_field( 'guru_export_payouts_csv' ); ?>
					<button type="submit" class="button button-secondary">Export Batch CSV</button>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex; gap:8px; align-items:center;">
					<input type="hidden" name="action" value="guru_mark_batch_paid" />
					<?php wp_nonce_field( 'guru_mark_batch_paid' ); ?>
					<input type="text" name="payout_batch_id" required placeholder="e.g. BATCH-<?php echo esc_attr( gmdate( 'Y-m' ) ); ?>" class="regular-text" style="width:180px;" />
					<button type="submit" class="button button-primary" <?php disabled( empty( $pending_rows ) ); ?>>Mark Batch Paid</button>
				</form>
			</div>

			<hr />
			<h2>Recent Redemptions (Latest 25)</h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>Ref Code</th>
						<th>Member</th>
						<th>Card Code</th>
						<th>Listing</th>
						<th>Offer Type</th>
						<th>Voucher</th>
						<th>Bill</th>
						<th>Points</th>
						<th>Method</th>
						<th>Payout Status</th>
						<th>Batch</th>
						<th>Date (UTC)</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $recent_logs ) ) : ?>
						<tr><td colspan="12">No redemptions logged yet.</td></tr>
					<?php else : ?>
						<?php foreach ( $recent_logs as $log ) : ?>
							<tr>
								<td><code><?php echo esc_html( $log->reference_code ); ?></code></td>
								<td><?php echo esc_html( $log->user_login ?: '#' . $log->member_user_id ); ?></td>
								<td><?php echo esc_html( $log->card_code ); ?></td>
								<td><?php echo esc_html( $log->listing_title ?: '#' . $log->listing_post_id ); ?></td>
								<td><?php echo esc_html( $log->offer_type ); ?></td>
								<td>$<?php echo esc_html( number_format( (float) $log->voucher_value_usd, 2 ) ); ?></td>
								<td>$<?php echo esc_html( number_format( (float) $log->purchase_amount_usd, 2 ) ); ?></td>
								<td><?php echo (int) $log->points_awarded; ?></td>
								<td><?php echo esc_html( $log->redemption_method ); ?></td>
								<td><?php echo esc_html( $log->payout_status ); ?></td>
								<td><?php echo esc_html( $log->payout_batch_id ?: '—' ); ?></td>
								<td><?php echo esc_html( $log->created_at ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Sanitizes a CSV cell to prevent spreadsheet formula injection.
	 *
	 * @param string $value Raw cell value.
	 * @return string
	 */
	private static function safe_csv_cell( $value ) {
		$value = (string) $value;
		if ( isset( $value[0] ) && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Exports pending payouts as CSV.
	 */
	public static function handle_export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}
		check_admin_referer( 'guru_export_payouts_csv' );

		$rows = self::get_pending_payout_rows();

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="guru-pending-payouts-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Listing Title', 'Post ID', 'Merchant Email', 'Validated Redemptions', 'Unit Value (USD)', 'Total Due (USD)' ) );

		foreach ( $rows as $row ) {
			$author_id    = (int) get_post_field( 'post_author', $row->listing_post_id );
			$author_email = $author_id ? (string) get_the_author_meta( 'user_email', $author_id ) : '';
			fputcsv(
				$out,
				array(
					self::safe_csv_cell( $row->listing_title ?: '(Deleted Listing)' ),
					(int) $row->listing_post_id,
					self::safe_csv_cell( $author_email ),
					(int) $row->redemption_count,
					number_format( (float) $row->unit_value_usd, 2, '.', '' ),
					number_format( (float) $row->total_due_usd, 2, '.', '' ),
				)
			);
		}
		fclose( $out );
		exit;
	}

	/**
	 * Marks all pending Guru-funded voucher redemptions as paid under a batch ID.
	 */
	public static function handle_mark_batch_paid() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}
		check_admin_referer( 'guru_mark_batch_paid' );

		$batch_id = isset( $_POST['payout_batch_id'] ) ? sanitize_text_field( wp_unslash( $_POST['payout_batch_id'] ) ) : '';
		if ( empty( $batch_id ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=guru-engine-payouts' ) );
			exit;
		}

		global $wpdb;
		$table = Guru_Engine_DB::table_name();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET payout_status = 'paid', payout_batch_id = %s
				WHERE offer_type = 'guru_funded_voucher' AND payout_status = 'pending_payout'",
				$batch_id
			)
		);

		wp_safe_redirect( add_query_arg( 'batch_paid', rawurlencode( $batch_id ), admin_url( 'admin.php?page=guru-engine-payouts' ) ) );
		exit;
	}
}
