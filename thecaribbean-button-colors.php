<?php
/**
 * Plugin Name: The Caribbean Button Colors
 * Description: Applies #13266E background color to the hero section Search button and the header Add a listing button without modifying any other styles.
 * Version: 1.0.0
 * Author: The Caribbean
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

	$result_template = get_post_meta( $object_id, 'case27_listing_type_result_template', true );
	if ( empty( $result_template ) || false === strpos( wp_json_encode( $result_template ), 'sq ft' ) ) {
		return $value;
	}

	remove_filter( 'get_post_metadata', 'thecaribbean_sync_area_unit_to_sqft', 10 );
	$raw_options = get_post_meta( $object_id, 'case27_listing_type_single_page_options', true );
	add_filter( 'get_post_metadata', 'thecaribbean_sync_area_unit_to_sqft', 10, 4 );

	if ( empty( $raw_options ) ) {
		return $value;
	}

	if ( is_string( $raw_options ) && is_serialized( $raw_options ) ) {
		$unserialized = @unserialize( $raw_options );
		if ( false === $unserialized ) {
			return $value;
		}
		$updated = serialize( thecaribbean_replace_m2_with_sqft( $unserialized ) );
		return array( $updated );
	}

	return array( thecaribbean_replace_m2_with_sqft( $raw_options ) );
}
add_filter( 'get_post_metadata', 'thecaribbean_sync_area_unit_to_sqft', 10, 4 );
