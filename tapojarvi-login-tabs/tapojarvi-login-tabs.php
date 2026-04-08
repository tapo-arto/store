<?php
/**
 * Plugin Name: Tapojärvi Login Tabs
 * Description: Kirjautumis-/rekisteröitymisläpät.
 * Version:     3.1.3
 * Author:      Tapojärvi Oy
 * Text Domain: tapojarvi-login-tabs
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Lataa käännökset /languages-kansiosta */
add_action( 'plugins_loaded', function () {
	load_plugin_textdomain(
		'tapojarvi-login-tabs',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
} );

define( 'TLT_DIR', plugin_dir_path( __FILE__ ) );
define( 'TLT_URL', plugin_dir_url( __FILE__ ) );

/**
 * Varmista oikea kieli admin-ajax -pyynnöissä
 * - Jos pyynnössä on ?lang=fi/en tms., käytä sitä
 * - Muuten päättele kieli HTTP_REFERER:in polusta (esim. /en/ -> en)
 */
add_action( 'init', function () {
	if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) return;
	if ( ! function_exists( 'pll_switch_language' ) ) return;

	$lang = '';
	if ( isset( $_REQUEST['lang'] ) ) {
		$lang = sanitize_key( wp_unslash( $_REQUEST['lang'] ) );
	}
	if ( ! $lang && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
		$path = wp_parse_url( $_SERVER['HTTP_REFERER'], PHP_URL_PATH );
		if ( is_string( $path ) && preg_match( '#^/([a-z]{2})(?:/|$)#', $path, $m ) ) {
			$lang = $m[1];
		}
	}
	if ( $lang ) {
		pll_switch_language( $lang );
	}
}, 0 );

/** Lataa moduulit turvallisesti */
$includes = [
	'includes/shortcodes.php',
	'includes/logic.php',
	'includes/assets.php',
];

foreach ( $includes as $rel_path ) {
	$abs = TLT_DIR . $rel_path;
	if ( file_exists( $abs ) ) {
		require_once $abs;
	} else {
		error_log( sprintf( '[tapojarvi-login-tabs] Missing include: %s', $abs ) );
	}
}