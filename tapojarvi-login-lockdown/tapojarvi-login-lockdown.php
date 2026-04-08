<?php
/**
 * Plugin Name: Tapojärvi - Login Lockdown
 * Description: Sulkee wp-login.php/wp-admin vierailijoilta, sallii magic-linkin
 *              ja ohjaa vanhentuneet linkit takaisin kirjautumissivulle.
 *              Ilmoitus näytetään vain lyhytkoodilla [tapo_magic_notice].
 * Version:     1.2.0
 * Author:      Tapojärvi Oy - Arto Huhta
 * License:     GPLv3 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ---------------------------------------------------------------
 *  Yksi muuttuja: kirjautumissivun polku (pidä etu- ja loppu-slash)
 * -------------------------------------------------------------- */
if ( ! defined( 'TAPO_LOGIN_SLUG' ) ) {
    define( 'TAPO_LOGIN_SLUG', '/kirjaudu/' );   // --> vaihda tarvittaessa
}

/* ================================================================
 * 1. Estä wp-login.php / wp-admin vierailijoilta
 * ============================================================= */
add_action( 'init', function () {

    if ( is_user_logged_in() ) {
        return; // sisällä → ok
    }

    $uriRaw = strtok( $_SERVER['REQUEST_URI'] ?? '', '?' );
    $uri    = rtrim( $uriRaw, '/' );              // poista mahdollinen loppuslash
    $is_get = $_SERVER['REQUEST_METHOD'] === 'GET';
    $login  = str_ends_with( $uri, 'wp-login.php' );
    $admin  = str_starts_with( $uri, '/wp-admin' )
              && ! str_ends_with( $uri, 'admin-ajax.php' )
              && ! str_ends_with( $uri, 'admin-post.php' );

    $has_token = isset( $_GET['token'] ) || isset( $_GET['key'] ) ||
                 ( isset( $_GET['action'] ) && $_GET['action'] === 'magiclink_login' );

    $login_flag = isset( $_GET['login'] );            // ?login=...
$redir_flag = isset( $_GET['redirect_to'] ) && (
    // mik ä tahansa ?login=... parametri redirect_to-URL:ssä
    strpos( urldecode( $_GET['redirect_to'] ), 'login=' ) !== false ||
    // fallback: includes magic_expired kuten ennen
    strpos( urldecode( $_GET['redirect_to'] ), 'magic_expired' ) !== false
);


    $magic_ok = $login && $is_get && $has_token && ! $login_flag && ! $redir_flag;
    if ( $magic_ok ) {
        return;                                       // päästä vain VIELÄ VALIDI linkki
    }

    /* --- kaikki muut wp-login/wp-admin-pyynnöt → /kirjaudu/ --- */
    if ( ( $login && $is_get ) || $admin ) {

        $target = home_url( TAPO_LOGIN_SLUG );

        // Jos virheilippu jo queryssä → kopioi se
        if ( $login_flag ) {
            $target = add_query_arg( 'login', sanitize_key( $_GET['login'] ), $target );

        // …tai jos virhe piilossa redirect_to-parametrissa
        } elseif ( $redir_flag ) {
            $target = add_query_arg( 'login', 'magic_expired', $target );
        }

        wp_safe_redirect( $target );
        exit;
    }
}, 1 );


/* ================================================================
 * 2. Virheellinen salasana / tyhjät kentät
 * ============================================================= */
add_action( 'wp_login_failed', function () {
    wp_safe_redirect( add_query_arg( 'login', 'failed',
        wp_get_referer() ?: home_url( TAPO_LOGIN_SLUG ) ) );
    exit;
} );
add_filter( 'authenticate', function ( $user, $u, $p ) {
    if ( empty( $u ) || empty( $p ) ) {
        wp_safe_redirect( add_query_arg( 'login', 'empty',
            wp_get_referer() ?: home_url( TAPO_LOGIN_SLUG ) ) );
        exit;
    }
    return $user;
}, 30, 3 );

/* ================================================================
 * 3. WooCommerce-noticet (kirjautumislomakkeen yläpuolelle)
 * ============================================================= */
add_action( 'woocommerce_before_customer_login_form', function () {
    switch ( $_GET['login'] ?? '' ) {
        case 'failed':
            wc_print_notice(
                __( 'Virheellinen käyttäjätunnus tai salasana.', 'woocommerce' ),
                'error'
            );
            break;

        case 'empty':
            wc_print_notice(
                __( 'Käyttäjätunnus ja salasana ovat pakollisia kenttiä.', 'woocommerce' ),
                'error'
            );
            break;

        case 'magic_expired':
            wc_print_notice(
                __( 'Kirjautumislinkkisi on vanhentunut. Tilaa uusi linkki sähköpostiisi Työntekijä-välilehdeltä.', 'tapojarvi' ),
                'notice'
            );
            break;
    }
} );


/* ================================================================
 * 4. Pakota vierailijat kirjautumissivulle (pl. lost-password)
 * ============================================================= */
add_action( 'template_redirect', function () {

    if ( is_user_logged_in() ) return;
    if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ||
         ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ||
         ( defined( 'DOING_CRON' ) && DOING_CRON ) ) return;

    /* --- Sallitut sivut --------------------------------------- */
    if ( is_page( [ trim( TAPO_LOGIN_SLUG, '/' ), 'login' ] ) ) return;
    if ( is_wc_endpoint_url( 'lost-password' ) ) return;    // lost-password
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' &&
         str_contains( $_SERVER['REQUEST_URI'], 'lost-password' ) ) return;

    /* --- Kaikki muu → kirjautumissivu ------------------------- */
    wp_safe_redirect( home_url( TAPO_LOGIN_SLUG ) );
    exit;
}, 2 );


function tapo_magiclink_error_redirect( $msg ) {

    $plain   = wp_strip_all_tags( is_array( $msg ) ? implode( ' ', $msg ) : $msg );

    // ↴ lisää tänne kaikki tekstit, joista haluat ohjata /kirjaudu/?login=magic_expired
$needles = [
    // Passwordless-Loginin yleiset virheilmoitukset
    'login link has expired',
    'invalid login link',
    'this login link has expired',
    'this link has expired',

    // Vanhat/nykyiset hakusanat
    'login link expired',
    'invalid magic login token',
    'token has expired',
    'invalid token',
    'too many failed login attempts'
];


    foreach ( $needles as $n ) {
        if ( stripos( $plain, $n ) !== false ) {
            wp_safe_redirect(
                add_query_arg( 'login', 'magic_expired', home_url( TAPO_LOGIN_SLUG ) )
            );
            exit;
        }
    }
    return $msg;
}
add_filter( 'login_errors',  'tapo_magiclink_error_redirect', 1 );
add_filter( 'login_message', 'tapo_magiclink_error_redirect', 1 );


/* ================================================================
 * 6. Passwordless-login-lisäosan virhefiltteri
 * ============================================================= */
add_filter( 'passwordless_login_error', function ( $msg ) {
    wp_safe_redirect( add_query_arg( 'login', 'magic_expired',
        home_url( TAPO_LOGIN_SLUG ) ) );
    exit;
    return $msg;
}, 999 );

/* ================================================================
 * 7. Sieppaa mahdollinen wp_die() –virhesivu
 * ============================================================= */
add_filter( 'wp_die_handler', function() {
$uriRaw = strtok( $_SERVER['REQUEST_URI'] ?? '', '?' );
$uri    = rtrim( $uriRaw, '/' );              // ←  UUSI

    $is_login_page = str_ends_with( $uri, 'wp-login.php' ) ||
                     str_contains( $uri, 'magiclink_login' ) ||
                     ( isset( $_GET['token'] ) || isset( $_GET['key'] ) );

    if ( $is_login_page && function_exists( 'tapo_wp_die_intercept' ) ) {
        if ( isset( $_SESSION['tapo_magic_error_handled'] )
             && $_SESSION['tapo_magic_error_handled'] ) {
            return '_default_wp_die_handler';
        }
        return 'tapo_wp_die_intercept';
    }
    return '_default_wp_die_handler';
} );

function tapo_wp_die_intercept( $message, $title = '', $args = array() ) {
    if ( ! session_id() ) {
        session_start();
    }

    $plain = wp_strip_all_tags( is_array( $message )
             ? implode( ' ', $message ) : $message );

    if ( isset( $_SESSION['tapo_magic_error_handled'] )
         && $_SESSION['tapo_magic_error_handled'] ) {
        return call_user_func( '_default_wp_die_handler',
                               $message, $title, $args );
    }

$needles = [
    // Passwordless-Loginin yleiset virheilmoitukset
    'login link has expired',
    'invalid login link',
    'this login link has expired',
    'this link has expired',

    // Vanhat/nykyiset hakusanat
    'login link expired',
    'invalid magic login token',
    'token has expired',
    'invalid token',
    'too many failed login attempts'
];


    foreach ( $needles as $n ) {
        if ( stripos( $plain, $n ) !== false ) {
            $_SESSION['tapo_magic_error_handled'] = true;
            wp_safe_redirect( add_query_arg( 'login', 'magic_expired',
                home_url( TAPO_LOGIN_SLUG ) ) );
            exit;
        }
    }
    return call_user_func( '_default_wp_die_handler',
                           $message, $title, $args );
}

/* ================================================================
 * 8. ”Takaisin kirjautumiseen” -nappi lost-password-sivulle
 * ============================================================= */
add_action( 'woocommerce_after_lost_password_form', function () {
    echo '<p style="text-align:center;margin-top:25px;">
            <a href="' . esc_url( home_url( TAPO_LOGIN_SLUG ) ) . '" class="button">
                &larr; ' . esc_html__( 'Takaisin kirjautumiseen', 'tapojarvi' ) . '
            </a>
          </p>';
} );

/* ================================================================
 * 9. Lyhytkoodi: [tapo_magic_notice]
 * ============================================================= */
add_shortcode( 'tapo_magic_notice', function () {

    $expired =
        ( isset( $_GET['login'] ) && $_GET['login'] === 'magic_expired' ) ||
        ( isset( $_GET['magic'] ) && $_GET['magic'] === 'expired' );

    if ( ! $expired ) {
        return '';
    }

    ob_start(); ?>
    <div style="
         margin-bottom:20px;
         padding:12px 15px;
         border:1px solid #e2401c;
         background:#fef8f7;
         color:#e2401c;
         border-radius:4px;">
        <?php echo esc_html__(
                'Kirjautumislinkkisi on vanhentunut. Tilaa uusi linkki sähköpostiisi ',
                'tapojarvi'
             ); ?>
        <a href="#tapo-emp" style="text-decoration:underline;font-weight:600;">
            <?php esc_html_e( 'tästä', 'tapojarvi' ); ?>
        </a>.
    </div>
    <script>
        history.replaceState(null, document.title,
                             location.pathname + location.hash);
    </script>
    <?php
    return ob_get_clean();
} );
/* 10. Force-redirect vanhentunut / virheellinen magic-link
 *      – suoritetaan login_init-vaiheessa ENNEN Limit Login Attemptsia
 * ----------------------------------------------------------------- */
add_action( 'login_init', function () {

    // ajetaan vain wp-login.php:ssä
    if ( basename( $_SERVER['PHP_SELF'] ) !== 'wp-login.php' ) {
        return;
    }

    // Magic-link-parametreja?
    $is_magic = isset( $_GET['token'] ) || isset( $_GET['key'] )
             || isset( $_GET['magic-login'] )
             || ( isset( $_GET['action'] ) && $_GET['action'] === 'magiclink_login' );

    if ( ! $is_magic ) {
        return;                    // ei koske
    }

    // Jos token kelpasi, käyttäjä on jo sisällä
    if ( is_user_logged_in() ) {
        return;
    }

    // Token hylättiin → ohjataan /kirjaudu/?login=magic_expired
    wp_safe_redirect(
        add_query_arg( 'login', 'magic_expired', home_url( TAPO_LOGIN_SLUG ) )
    );
    exit;
}, 0 );                            // PRIORITY 0  ➜  ennen lisäosia

