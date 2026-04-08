<?php
/**
 * Plugin Name: Tapojärvi - Forced Logout on Inactivity
 * Description: Kirjaa käyttäjän ulos passiivisuuden jälkeen, näyttää varoitus-modaalin ja ilmoittaa syyn kirjautumissivulla.
 * Version:     1.3.0
 * Author:      Tapojärvi Oy - Arto Huhta
 * License:     GPLv3 or later
 * Text Domain: tapojarvi-force-logout
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------
 * 0. Lataa käännökset ja perusasetukset
 * ---------------------------------------------------------------- */
add_action( 'plugins_loaded', function () {
	load_plugin_textdomain(
		'tapojarvi-force-logout',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
});

/* Asetukset (voit ylikirjoittaa wp-config.php:ssa) */
if ( ! defined( 'TFL_LOGOUT_AFTER'   ) ) define( 'TFL_LOGOUT_AFTER',   900 ); // 15 min
if ( ! defined( 'TFL_WARNING_BEFORE' ) ) define( 'TFL_WARNING_BEFORE', 120 ); //  2 min
// Vapaaehtoiset: määritä kirjautumissivu ID:llä tai slugilla (toimii monikielisenä)
if ( ! defined( 'TFL_LOGIN_PAGE_ID'   ) ) define( 'TFL_LOGIN_PAGE_ID',   0 );          // esim 123
if ( ! defined( 'TFL_LOGIN_PAGE_SLUG' ) ) define( 'TFL_LOGIN_PAGE_SLUG', 'kirjautuminen' ); // esim 'login' tms.

/* ------------------------------------------------------------------
 *  Apurit: kirjautumissivun URL/ID
 * ---------------------------------------------------------------- */
function tfl_get_login_page_id() {
	if ( TFL_LOGIN_PAGE_ID ) {
		return absint( TFL_LOGIN_PAGE_ID );
	}
	if ( TFL_LOGIN_PAGE_SLUG ) {
		$page = get_page_by_path( TFL_LOGIN_PAGE_SLUG );
		if ( $page && ! is_wp_error( $page ) ) {
			return (int) $page->ID;
		}
	}
	return 0;
}
function tfl_get_login_page_url() {
	$id = tfl_get_login_page_id();
	if ( $id ) {
		return get_permalink( $id );
	}
	// Viimeinen fallback: WordPressin oletuskirjautuminen
	return wp_login_url();
}

/* ------------------------------------------------------------------
 *  1. AJAX: pakota uloskirjautuminen
 * ---------------------------------------------------------------- */
add_action( 'wp_ajax_force_logout',        'tfl_ajax_force_logout' );
add_action( 'wp_ajax_nopriv_force_logout', 'tfl_ajax_force_logout' );
function tfl_ajax_force_logout() {
	check_ajax_referer( 'tfl-force-logout', 'nonce' );
	wp_logout();
	wp_send_json_success();
}

/* ------------------------------------------------------------------
 *  2. Front-end-assetit (vain kirjautuneille)
 * ---------------------------------------------------------------- */
add_action( 'wp_enqueue_scripts', function () {

	if ( ! is_user_logged_in() ) {
		return;
	}

	wp_register_script( 'tfl-force-logout', false, [], '1.3.0', true );

	$redirect_base = tfl_get_login_page_url();
	$redirect_url  = add_query_arg( 'session_expired', '1', $redirect_base );
	$redirect_url  = apply_filters( 'tfl_redirect_url', $redirect_url, $redirect_base );

	wp_localize_script( 'tfl-force-logout', 'tflSettings', [
		'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
		'nonce'         => wp_create_nonce( 'tfl-force-logout' ),
		'logoutAfter'   => TFL_LOGOUT_AFTER * 1000,
		'warningBefore' => TFL_WARNING_BEFORE * 1000,
		'redirectUrl'   => $redirect_url,
		'warningText'   => __( 'Olet ollut pitkään tekemättä mitään. Sinut kirjataan ulos 1 minuutin kuluttua.', 'tapojarvi-force-logout' ),
		'stayText'      => __( 'Pysy kirjautuneena', 'tapojarvi-force-logout' ),
	] );

	wp_enqueue_script( 'tfl-force-logout' );
	wp_add_inline_script( 'tfl-force-logout', tfl_inline_js() );
	add_action( 'wp_footer', 'tfl_render_warning_modal' );
} );

/* ------------------------------------------------------------------
 *  3. Modaalin HTML
 * ---------------------------------------------------------------- */
function tfl_render_warning_modal() { ?>
	<div id="tflLogoutWarning" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.5);justify-content:center;align-items:center;">
		<div style="background:#fff;padding:30px;max-width:400px;text-align:center;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.2);">
			<p id="tflWarningText"></p>
			<button id="tflStayLoggedIn"
			        style="padding:10px 20px;background:#f1c40f;border:none;border-radius:5px;cursor:pointer;font-weight:bold;"></button>
		</div>
	</div>
<?php }

/* ------------------------------------------------------------------
 *  4. Inline-JS (ajastimet)
 * ---------------------------------------------------------------- */
function tfl_inline_js() {
	return <<<JS
(function () {
	if (typeof tflSettings === 'undefined') { return; }

	const logoutAfter   = +tflSettings.logoutAfter;
	const warningBefore = +tflSettings.warningBefore;
	const ajaxUrl       = tflSettings.ajaxUrl;
	const nonce         = tflSettings.nonce;
	const redirectUrl   = tflSettings.redirectUrl;

	const modal = document.getElementById('tflLogoutWarning');
	const stay  = document.getElementById('tflStayLoggedIn');
	const txt   = document.getElementById('tflWarningText');

	txt.textContent = tflSettings.warningText;
	stay.textContent = tflSettings.stayText;

	let t1, t2;
	const reset = () => {
		clearTimeout(t1); clearTimeout(t2); modal.style.display='none';
		t2 = setTimeout(()=>{ modal.style.display='flex'; }, logoutAfter - warningBefore);
		t1 = setTimeout(logout, logoutAfter);
	};
	const logout = () => {
		fetch(ajaxUrl, {
			method:'POST', credentials:'same-origin',
			headers:{'Content-Type':'application/x-www-form-urlencoded'},
			body:'action=force_logout&nonce='+encodeURIComponent(nonce)
		}).then(()=>{ window.location.href = redirectUrl; });
	};

	['load','mousemove','mousedown','click','scroll','keypress'].forEach(e=>addEventListener(e,reset));
	stay.addEventListener('click', reset);
	reset();
})();
JS;
}

/* ------------------------------------------------------------------
 *  5. Käsitellään ?session_expired=1  ->  asetetaan cookie
 * ---------------------------------------------------------------- */
add_action( 'template_redirect', function () {
	if ( isset( $_GET['session_expired'] ) && $_GET['session_expired'] === '1' ) {
		setcookie( 'tfl_session_expired', '1', time() + DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		wp_safe_redirect( tfl_get_login_page_url() );
		exit;
	}
});

/* ------------------------------------------------------------------
 *  6. WooCommercen kirjautumissivulle notice (yksi sivu, yksi kerta)
 * ---------------------------------------------------------------- */
add_action( 'woocommerce_before_customer_login_form', function () {

	if ( is_user_logged_in() ) {
		return;
	}
	if ( empty( $_COOKIE['tfl_session_expired'] ) ) {
		return;
	}

	wc_print_notice( __( 'Istunto päättyi inaktiivisuuden vuoksi.', 'tapojarvi-force-logout' ), 'notice' );

	// Tyhjennetään cookie → ilmoitus ei toistu muualla
	setcookie( 'tfl_session_expired', '', time() - HOUR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
} );

/* ------------------------------------------------------------------
 *  7. Fallback-notice, jos WooCommercea ei ole / lomaketta ei näy
 * ---------------------------------------------------------------- */
add_action( 'wp_footer', function () {

	if ( is_user_logged_in() || empty( $_COOKIE['tfl_session_expired'] ) ) {
		return;
	}

	// Näytä ilmoitus vain kirjautumissivulla (ID/slug apureista)
	$page_id = tfl_get_login_page_id();
	if ( ! $page_id || ! is_page( $page_id ) ) {
		return;
	}

	echo '<style>#tflSessionExpiredNotice{position:fixed;top:0;left:0;right:0;background:#f1c40f;color:#000;padding:15px;text-align:center;font-weight:bold;z-index:99999;}</style>';
	echo '<div id="tflSessionExpiredNotice">' . esc_html__( 'Istuntosi päättyi inaktiivisuuden vuoksi.', 'tapojarvi-force-logout' ) . '</div>';

	// Poistetaan cookie – näkyy vain kerran
	setcookie( 'tfl_session_expired', '', time() - HOUR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
} );

/* ------------------------------------------------------------------
 *  8. Tyhjennetään cookie heti onnistuneessa kirjautumisessa
 * ---------------------------------------------------------------- */
add_action( 'wp_login', function () {
	if ( isset( $_COOKIE['tfl_session_expired'] ) ) {
		setcookie( 'tfl_session_expired', '', time() - HOUR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
	}
}, 10, 0 );