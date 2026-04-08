<?php
/**
 * Plugin Name: Tapojärvi - Login Redirect
 * Description: Ohjaa ensimmäisellä kirjautumisella Oma tili -sivulle, jatkossa etusivulle. Toimii sekä tavallisen login -lomakkeen että Passwordless / Magic Login -kirjautumisen kanssa.
 * Version: 1.1.0
 * Author: Tapojärvi Oy - Arto Huhta
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ------------------------------------------------------------
 *  Vakio – muokkaa polku tarvittaessa
 * ---------------------------------------------------------- */
if ( ! defined( 'TAPO_ACCOUNT_SLUG' ) ) {
	// WooCommercen Oma tili -sivu (trailing slash!)
	define( 'TAPO_ACCOUNT_SLUG', '/oma-tili/' );
}

/* ------------------------------------------------------------
 *  1) Yhteinen ohjausfunktio
 * ---------------------------------------------------------- */
function tapo_login_redirect( $redirect_to, $requested, $user ) {

	// Jos jokin meni vikaan tai ollaan admin-puolella → älä puutu
	if ( is_wp_error( $user ) || is_admin() ) {
		return $redirect_to;
	}

	$user_id = $user->ID;

	/* --- Ensikirjautuminen ------------------------------- */
	if ( ! get_user_meta( $user_id, '_tapo_first_login_done', true ) ) {

		update_user_meta( $user_id, '_tapo_first_login_done', 1 );

		// Oma tili → suoraan profiilin muokkausvälilehdelle
		return wc_get_account_endpoint_url( 'edit-account' );
	}

	/* --- Paluukäyttäjät ---------------------------------- */
	return home_url();                           // Etusivu
}

/* ------------------------------------------------------------
 *  2) Core-login (wp_login_form yms.)
 *      HUOM! Prioriteetti 999 ⇒ suoritetaan VIIMEISENÄ
 * ---------------------------------------------------------- */
add_filter( 'login_redirect', 'tapo_login_redirect', 999, 3 );

/* ------------------------------------------------------------
 *  3) Passwordless / Magic Login -kirjautumiset
 *      (plugin suodattaa tätä ennen selainredirectiä)
 * ---------------------------------------------------------- */
if ( function_exists( 'passwordless_login' ) ) {
	add_filter( 'passwordless_login_redirect', function ( $redirect_to, $user_id, $user ) {
		// Passwordless-login antaa $user-objektin kolmantena parametrina
		return tapo_login_redirect( $redirect_to, '', $user );
	}, 999, 3 );
}

/* ------------------------------------------------------------
 *  4) (valinnainen) Lähettäjän nimi Magic-linkkisähköposteihin
 * ---------------------------------------------------------- */
add_filter( 'wp_mail_from_name', function() {
	return 'Tapojärvi Store';
} );
