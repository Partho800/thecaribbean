<?php
/**
 * Plugin Name: The Addon
 * Description: Customizes site branding (#13266E), white scrolled header & page background, listing sq ft sync, and powers the Explorer+ Voucher Redemption, Dynamic QR Card, Guru Points, and Merchant Payouts system.
 * Version: 2.0.0
 * Author: Raju
 * Author URI: https://pnscodebd.vercel.app/
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues custom button color styles after theme and Elementor stylesheets.
 */
function thecaribbean_enqueue_button_colors() {
	$css_rel_path = 'assets/css/button-colors.css';
	$css_abs_path = plugin_dir_path( __FILE__ ) . $css_rel_path;
	$version      = file_exists( $css_abs_path ) ? (string) filemtime( $css_abs_path ) : '1.0.0';

	wp_enqueue_style(
		'thecaribbean-button-colors',
		plugin_dir_url( __FILE__ ) . $css_rel_path,
		array(),
		$version
	);

	if ( is_front_page() ) {
		$mobile_rel = 'assets/css/mobile-home.css';
		$mobile_abs = plugin_dir_path( __FILE__ ) . $mobile_rel;
		$mobile_ver = file_exists( $mobile_abs ) ? (string) filemtime( $mobile_abs ) : '1.0.0';
		wp_enqueue_style(
			'thecaribbean-mobile-home',
			plugin_dir_url( __FILE__ ) . $mobile_rel,
			array( 'thecaribbean-button-colors' ),
			$mobile_ver,
			'(max-width: 767px)'
		);
	}
}
add_action( 'wp_enqueue_scripts', 'thecaribbean_enqueue_button_colors', PHP_INT_MAX );

/**
 * Overrides the theme's dynamic brand color option on the frontend.
 *
 * @return string
 */
function thecaribbean_override_brand_color() {
	return '#13266E';
}
if ( ! is_admin() ) {
	add_filter( 'option_options_general_brand_color', 'thecaribbean_override_brand_color' );
}

/**
 * Recursively replaces `m2` area unit placeholders with `sq ft` in listing type single-page options.
 *
 * @param mixed $data Serialized/unserialized metadata value.
 * @return mixed
 */
function thecaribbean_replace_m2_with_sqft( $data ) {
	if ( is_string( $data ) ) {
		return str_replace(
			array( '[[field]] m2', '[[area]] m2' ),
			array( '[[field]] sq ft', '[[area]] sq ft' ),
			$data
		);
	}

	if ( is_array( $data ) ) {
		foreach ( $data as $key => $value ) {
			$data[ $key ] = thecaribbean_replace_m2_with_sqft( $value );
		}
	}

	return $data;
}

/**
 * Syncs detail page Area unit with the preview card (`sq ft`) when the listing type card uses `sq ft`.
 *
 * @param mixed  $value     Short-circuit value.
 * @param int    $object_id Post ID.
 * @param string $meta_key  Meta key.
 * @param bool   $single    Whether a single value was requested.
 * @return mixed
 */
function thecaribbean_sync_area_unit_to_sqft( $value, $object_id, $meta_key, $single ) {
	if ( 'case27_listing_type_single_page_options' !== $meta_key || is_admin() ) {
		return $value;
	}

	remove_filter( 'get_post_metadata', 'thecaribbean_sync_area_unit_to_sqft', 10 );
	$raw_options = get_post_meta( $object_id, 'case27_listing_type_single_page_options', true );
	add_filter( 'get_post_metadata', 'thecaribbean_sync_area_unit_to_sqft', 10, 4 );

	if ( empty( $raw_options ) ) {
		return $value;
	}

	$result_template = get_post_meta( $object_id, 'case27_listing_type_result_template', true );
	$has_sqft        = ! empty( $result_template ) && false !== strpos( wp_json_encode( $result_template ), 'sq ft' );

	if ( is_string( $raw_options ) && is_serialized( $raw_options ) ) {
		$unserialized = @unserialize( $raw_options );
		if ( false === $unserialized ) {
			return $value;
		}
		if ( $has_sqft ) {
			$unserialized = thecaribbean_replace_m2_with_sqft( $unserialized );
		}
		$unserialized = Guru_Engine_Frontend::inject_vouchers_tab( $unserialized );
		return array( serialize( $unserialized ) );
	}

	if ( $has_sqft ) {
		$raw_options = thecaribbean_replace_m2_with_sqft( $raw_options );
	}
	$raw_options = Guru_Engine_Frontend::inject_vouchers_tab( $raw_options );

	return array( $raw_options );
}
add_filter( 'get_post_metadata', 'thecaribbean_sync_area_unit_to_sqft', 10, 4 );

// Load Guru Engine modules.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-guru-db.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-guru-admin.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-guru-rest.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-guru-woocommerce.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-guru-frontend.php';

Guru_Engine_DB::init();
Guru_Engine_Admin::init();
Guru_Engine_REST::init();
Guru_Engine_WooCommerce::init();
Guru_Engine_Frontend::init();

register_activation_hook( __FILE__, function () {
	Guru_Engine_DB::create_tables();
	Guru_Engine_WooCommerce::register_account_endpoint();
	Guru_Engine_WooCommerce::ensure_explorer_product();
	flush_rewrite_rules();
} );
