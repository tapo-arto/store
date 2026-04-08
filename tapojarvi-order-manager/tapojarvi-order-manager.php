<?php
/**
 * Plugin Name: Tapojärvi - tilausten hallinta
 * Description: Sisältää työmaatilaukset sivun toiminnallisuudet
 * Version:     2.0
 * Author:      Tapojärvi Oy - Arto Huhta
 * Text Domain: tapojarvi  
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
add_action( 'plugins_loaded', function () {
    // lataa …/languages/fi.mo yms.
    load_plugin_textdomain(
        'tapojarvi',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );
} );
/* ------------------------------------------------------------------
 *  Lataa lisäosan CSS julkiselle puolelle
 * ----------------------------------------------------------------- */
add_action( 'wp_enqueue_scripts', function () {

	/* Ei admin-puolella */
	if ( is_admin() ) {
		return;
	}

	$handle = 'tapojarvi-order-manager';
	$path   = plugin_dir_path( __FILE__ ) . 'assets/style.css';
	$src    = plugins_url( 'assets/style.css', __FILE__ );

	/* Debug-logi jos tiedosto puuttuu */
	if ( ! file_exists( $path ) ) {
		error_log( '[Tapojarvi-Order-Mgr] style.css missing → ' . $path );
		return;
	}

	/* Ladattava versio = filemtime → välimuisti ei jää kiinni */
	wp_enqueue_style( $handle, $src, [], filemtime( $path ) );

}, 99 );
add_action(
    'tapojarvi_notify_returned',
    function ( $order_id, $msg = '' ) {

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $sender         = wp_get_current_user();
        $sender_name    = $sender->display_name ?: $sender->user_login;
        $customer_name  = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();

        $msg     = trim( $msg ) ?: __( 'Ei erillistä viestiä.', 'tapojarvi' );
        $subject = __( 'Tilauksesi tarvitsee korjauksia', 'tapojarvi' );

        // Sähköpostin HTML
        $body  = '<div style="max-width: 600px; margin: 30px auto; background: #ffffff; border: 1px solid #ddd; border-radius: 8px; padding: 30px; font-family: sans-serif; color: #333; text-align: center;">';

        // Otsikko
        $body .= '<h2 style="margin-top: 0; margin-bottom: 24px; color: #000; font-size: 22px; text-align: center;">' . esc_html( $subject ) . '</h2>';

        // Tervehdys
        $body .= '<p style="font-size: 16px; margin: 20px 0;">' . sprintf(
            __( 'Hei %1$s, tilauksesi Tapojärvi Storessa on palautettu ja vaatii korjauksia.', 'tapojarvi' ),
            esc_html( $customer_name )
        ) . '</p>';

        // Palautusviestin selitys
        $body .= '<p style="font-size: 16px; margin: 20px 0;">' . sprintf(
            __( 'Tilaus #%1$d palautettiin käsittelijältä <strong>%2$s</strong> seuraavalla viestillä:', 'tapojarvi' ),
            $order_id,
            esc_html( $sender_name )
        ) . '</p>';

        // Varsinainen viesti
        $body .= '<div style="display: inline-block; text-align: left; background: #f8f8f8; border-left: 4px solid #333; padding: 16px 20px; margin: 20px auto; border-radius: 4px; max-width: 480px;">'
              . nl2br( esc_html( $msg ) )
              . '</div>';

        // Ohje jatkotoimista
        $body .= '<p style="font-size: 16px; margin: 30px 0;">'
              . __( 'Ole hyvä ja tee uusi tilaus korjatuilla tiedoilla. Huomioithan, että alkuperäistä tilausta ei voi muokata jälkikäteen.', 'tapojarvi' )
              . '</p>';

        // Painike
        $body .= '<a href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '" style="display: inline-block; padding: 12px 24px; background: #fee000; color: #000; font-weight: bold; text-decoration: none; border-radius: 4px; margin-top: 10px; transition: background 0.2s;">'
              . __( 'Siirry kauppaan', 'tapojarvi' ) . '</a>';

        $body .= '</div>';

        WC()->mailer()->send(
            $order->get_billing_email(),
            $subject,
            $body,
            [ 'Content-Type: text/html; charset=UTF-8' ]
        );
    },
    10,
    2
);


/* --------------------------------------------------
 * 2)  LUO “Tilausten Hallitsija” -rooli
 * -------------------------------------------------- */
add_action( 'init', function () {

    if ( ! get_role( 'tilausten_hallitsija' ) ) {
        add_role(
            'tilausten_hallitsija',
            'Tilausten Hallitsija',
            [
                'read'              => true,
                'edit_posts'        => false,
                'manage_woocommerce'=> true,
            ]
        );
    }

} );

/* --------------------------------------------------
 * 3)  TYÖMAAKOODI PROFIILIIN
 * -------------------------------------------------- */
function tom_user_tyomaa_koodi( $user ) {
    // Haetaan työmaat asetuksista
    $raw = get_option('tapojarvi_tyomaat_lista', '');
    $options = array_filter(array_map('trim', explode("\n", $raw)));
    sort($options);

    $current = get_user_meta( $user->ID, 'tyomaa_koodi', true );

    echo '<h3>Työmaakoodi</h3>
          <table class="form-table">
              <tr>
                <th><label for="tyomaa_koodi">Valitse työmaa</label></th>
                <td>
                   <select name="tyomaa_koodi" id="tyomaa_koodi">';

    foreach ( $options as $opt ) {
        printf(
            '<option value="%1$s" %2$s>%1$s</option>',
            esc_attr( $opt ),
            selected( $current, $opt, false )
        );
    }

    echo       '</select>
                   <p class="description">Tämä työmaakoodi yhdistää työntekijöiden tilaukset tähän käyttäjään.</p>
                </td>
              </tr>
          </table>';
}
add_action( 'show_user_profile',        'tom_user_tyomaa_koodi' );
add_action( 'edit_user_profile',        'tom_user_tyomaa_koodi' );
add_action( 'personal_options_update',  'tom_save_tyomaa_koodi' );
add_action( 'edit_user_profile_update', 'tom_save_tyomaa_koodi' );

function tom_save_tyomaa_koodi( $user_id ) {

    if ( isset( $_POST['tyomaa_koodi'] ) && current_user_can( 'edit_user', $user_id ) ) {
        update_user_meta(
            $user_id,
            'tyomaa_koodi',
            sanitize_text_field( $_POST['tyomaa_koodi'] )
        );
    }
}

/* --------------------------------------------------
 * 4)  MY ACCOUNT -LOMAKKEEN TALLENNUS
 * -------------------------------------------------- */
add_action( 'woocommerce_save_account_details', function ( $user_id ) {

    if ( isset( $_POST['tyomaa_koodi'] ) ) {
        update_user_meta(
            $user_id,
            'tyomaa_koodi',
            sanitize_text_field( wp_unslash( $_POST['tyomaa_koodi'] ) )
        );
    }

}, 12 );

/* --------------------------------------------------
 * 5)  ASIAKKAAN MAKSUN JÄLKEINEN STATUS -> PENDING
 * -------------------------------------------------- */
add_filter( 'woocommerce_payment_complete_order_status', function ( $status, $order_id, $order ) {

    $user = get_user_by( 'id', $order->get_user_id() );

    if ( $user && in_array( 'customer', (array) $user->roles, true ) ) {
        return 'pending';
    }
    return $status;

}, 10, 3 );
/* --------------------------------------------------
 * 6)  SHORTCODE [hallitsijan_tilaukset]
 * -------------------------------------------------- */
add_shortcode( 'hallitsijan_tilaukset', function () {

    ob_start();
    require_once plugin_dir_path( __FILE__ ) . 'includes/tilausnakyma.php';
    return ob_get_clean();

} );
// Rekisteröi AJAX-toiminto
add_action( 'wp_ajax_pdf_export', 'handle_pdf_export' );

function handle_pdf_export() {
    // Salli adminit ja Hallitsija-rooli (hallinnoivat WooCommercea)
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        error_log( 'ROLES: ' . json_encode( wp_get_current_user()->roles ) );
        error_log( 'CAN manage_woocommerce? ' . ( current_user_can( 'manage_woocommerce' ) ? 'yes' : 'no' ) );
        wp_die( esc_html__( 'Sinulla ei ole oikeuksia tulostaa PDF:ää.', 'tapojarvi' ) );
    }

    include_once plugin_dir_path( __FILE__ ) . 'includes/pdf-export.php';
    exit;
}
