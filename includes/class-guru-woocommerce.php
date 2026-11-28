<?php
/**
 * Guru Engine WooCommerce Integration: Membership Activation, Order Points & My Account Tab.
 *
 * @package TheCaribbeanAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Guru_Engine_WooCommerce {

	/**
	 * Registers WooCommerce hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_account_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'ensure_explorer_product' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_direct_checkout_redirect' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_account_menu_item' ) );
		add_filter( 'wp_nav_menu_items', array( __CLASS__, 'add_mylisting_user_menu_item' ), 10, 2 );
		add_action( 'woocommerce_account_explorer-card_endpoint', array( __CLASS__, 'render_account_card_tab' ) );
		add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'render_dashboard_card_widget' ) );

		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'handle_order_completed' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'handle_order_completed' ) );
	}

	/**
	 * Registers explorer-card in WooCommerce query vars.
	 *
	 * @param array $vars WooCommerce query vars.
	 * @return array
	 */
	public static function add_query_vars( $vars ) {
		$vars['explorer-card'] = 'explorer-card';
		return $vars;
	}

	/**
	 * Injects Explorer+ Card link into My Listing's custom mylisting-user-menu nav menu.
	 *
	 * @param string   $items Menu HTML.
	 * @param stdClass $args  Menu args.
	 * @return string
	 */
	public static function add_mylisting_user_menu_item( $items, $args ) {
		if ( empty( $args->theme_location ) || 'mylisting-user-menu' !== $args->theme_location ) {
			return $items;
		}
		if ( false !== strpos( $items, 'explorer-card' ) ) {
			return $items;
		}

		$url        = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'explorer-card' ) : home_url( '/my-account-2/explorer-card/' );
		$is_current = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'explorer-card' );
		$cls        = 'menu-item' . ( $is_current ? ' current-menu-item' : '' );
		$new_item   = '<li class="' . esc_attr( $cls ) . '"><a href="' . esc_url( $url ) . '"><i class="mi card_membership"></i> Explorer+ Card</a></li>';

		$updated = preg_replace(
			'/(<li\b[^>]*>(?:(?!<\/li>).)*(?:customer-logout|Logout)<\/a><\/li>)/is',
			$new_item . '$1',
			$items,
			1,
			$count
		);

		return ( $count > 0 && null !== $updated ) ? $updated : ( $items . $new_item );
	}

	/**
	 * Renders the Explorer+ Digital Card & Points directly on the My Account Dashboard tab.
	 */
	public static function render_dashboard_card_widget() {
		echo '<div style="margin-top:20px;">' . do_shortcode( '[guru_member_card]' ) . '</div>';
	}

	/**
	 * Ensures an Explorer+ Annual Membership ($199) WooCommerce product exists and returns its ID.
	 *
	 * @return int
	 */
	public static function ensure_explorer_product() {
		$product_id = (int) get_option( 'guru_explorer_product_id', 0 );
		if ( $product_id > 0 && 'product' === get_post_type( $product_id ) && 'publish' === get_post_status( $product_id ) ) {
			return $product_id;
		}

		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return 0;
		}

		$product = new WC_Product_Simple();
		$product->set_name( 'Explorer+ Annual Membership' );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_regular_price( '199.00' );
		$product->set_price( '199.00' );
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->set_description( 'Annual Explorer+ subscription ($199 USD/yr) unlocking Guru-Sponsored Vouchers, Partner Privileges, Dynamic QR Card, and Guru Points.' );

		$new_id = $product->save();
		if ( $new_id > 0 ) {
			update_option( 'guru_explorer_product_id', $new_id );
		}

		return (int) $new_id;
	}

	/**
	 * Adds the Explorer+ product to cart and redirects directly to Checkout when ?guru_upgrade=1 is visited.
	 */
	public static function handle_direct_checkout_redirect() {
		if ( empty( $_GET['guru_upgrade'] ) || '1' !== $_GET['guru_upgrade'] ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_get_checkout_url' ) ) {
			return;
		}

		$product_id = self::ensure_explorer_product();
		if ( $product_id <= 0 ) {
			return;
		}

		if ( null === WC()->cart ) {
			wc_load_cart();
		}

		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product_id, 1 );

		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Registers the /my-account/explorer-card/ rewrite endpoint.
	 */
	public static function register_account_endpoint() {
		add_rewrite_endpoint( 'explorer-card', EP_ROOT | EP_PAGES );
	}

	/**
	 * Adds Explorer+ Card item to WooCommerce My Account navigation.
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public static function add_account_menu_item( $items ) {
		$new_items = array();
		foreach ( $items as $key => $label ) {
			$new_items[ $key ] = $label;
			if ( 'dashboard' === $key ) {
				$new_items['explorer-card'] = __( 'Explorer+ Card & Points', 'thecaribbean-addon' );
			}
		}
		if ( ! isset( $new_items['explorer-card'] ) ) {
			$new_items['explorer-card'] = __( 'Explorer+ Card & Points', 'thecaribbean-addon' );
		}
		return $new_items;
	}

	/**
	 * Renders the Explorer+ Digital QR Card & Points tab in My Account.
	 */
	public static function render_account_card_tab() {
		echo do_shortcode( '[guru_member_card]' );
	}

	/**
	 * Handles Explorer+ membership activation and Guru Points accrual on completed WooCommerce orders.
	 *
	 * @param int $order_id WooCommerce Order ID.
	 */
	public static function handle_order_completed( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$user_id = (int) $order->get_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		// 1. Activate Explorer+ membership if the configured membership product was purchased.
		$membership_product_id = (int) get_option( 'guru_explorer_product_id', 0 );
		if ( $membership_product_id > 0 && ! $order->get_meta( '_guru_membership_activated' ) ) {
			foreach ( $order->get_items() as $item ) {
				if ( (int) $item->get_product_id() === $membership_product_id ) {
					update_user_meta( $user_id, '_guru_explorer_status', 'active' );
					update_user_meta( $user_id, '_guru_subscription_expiry', gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ) );
					if ( ! get_user_meta( $user_id, '_guru_card_code', true ) ) {
						update_user_meta( $user_id, '_guru_card_code', Guru_Engine_DB::generate_unique_card_code() );
					}
					if ( ! get_user_meta( $user_id, '_guru_card_status', true ) ) {
						update_user_meta( $user_id, '_guru_card_status', 'active' );
					}
					$order->update_meta_data( '_guru_membership_activated', '1' );
					$order->save();
					break;
				}
			}
		}

		// 2. Award 1 Guru Point per $10 USD spent for active Explorer+ members (idempotent).
		if ( $order->get_meta( '_guru_points_awarded_order' ) ) {
			return;
		}

		$status = Guru_Engine_DB::get_member_status( $user_id );
		if ( ! $status['valid'] ) {
			return;
		}

		$points = (int) floor( (float) $order->get_total() / 10 );
		if ( $points > 0 ) {
			$cur_bal  = (int) get_user_meta( $user_id, '_guru_points_balance', true );
			$cur_life = (int) get_user_meta( $user_id, '_guru_points_lifetime', true );
			update_user_meta( $user_id, '_guru_points_balance', $cur_bal + $points );
			update_user_meta( $user_id, '_guru_points_lifetime', $cur_life + $points );
		}

		$order->update_meta_data( '_guru_points_awarded_order', (string) $points );
		$order->save();
	}
}
