<?php
/**
 * Plugin Name: Tapojärvi – WC Lang Fragments (+ Account i18n)
 * Description: WooCommercen fragmenttien kielierottelu, kielikohtaiset Cart/Checkout-URLit, en_GB “basket”→“cart”-korjaukset sekä Oma tili -sivun tekstien rekisteröinti Polylangin String translationsiin (My Account).
 * Version:     1.0.3
 * Author:      Tapojärvi Oy
 * License:     GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class Tap_WC_Lang_Fragments {
    const VERSION = '1.0.3';

    public function __construct() {
        add_action( 'plugins_loaded', array( $this, 'init' ) );
    }

    public function init() {
        // WooCommerce pakollinen
        if ( ! defined( 'WC_VERSION' ) ) {
            add_action( 'admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>Tapojärvi – WC Lang Fragments:</strong> WooCommerce vaaditaan.</p></div>';
            } );
            return;
        }

        // 0) Polylang: huomioi kieli myös AJAX-frontendissa
        add_filter( 'pll_ajax_on_front', '__return_true' );

        // 1) Erottele fragmenttiavaimet kielikohtaisesti
        add_filter( 'woocommerce_cart_fragment_name', array( $this, 'filter_fragment_name' ) );
        add_filter( 'woocommerce_cart_hash_key',    array( $this, 'filter_cart_hash_key' ) );

        // 2) Varmistus: muokkaa myös skriptin parametrit (WC 10.0.4 käyttää näitä)
        add_filter( 'woocommerce_get_script_data', array( $this, 'filter_script_data' ), 10, 2 );

        // 3) Kerta-siivous: poista vanhat avaimet selaimesta
        add_action( 'wp_footer', array( $this, 'print_cleanup_js' ), 99 );
    }

    private function lang_slug() {
        if ( function_exists( 'pll_current_language' ) ) {
            $slug = pll_current_language( 'slug' );
            if ( is_string( $slug ) && $slug !== '' ) return strtolower( $slug );
        }
        $locale = get_locale(); // esim. fi_FI / en_US
        $slug = substr( (string) $locale, 0, 2 );
        return strtolower( $slug ? $slug : 'en' );
    }

    public function filter_fragment_name( $name ) {
        return $name . '_' . $this->lang_slug();
    }

    public function filter_cart_hash_key( $key ) {
        return $key . '_' . $this->lang_slug();
    }

    public function filter_script_data( $params, $handle ) {
        if ( 'wc-cart-fragments' !== $handle ) return $params;
        if ( ! is_array( $params ) ) return $params;
        $lang = $this->lang_slug();
        if ( isset( $params['fragment_name'] ) && is_string( $params['fragment_name'] ) ) {
            $params['fragment_name'] .= '_' . $lang;
        }
        if ( isset( $params['cart_hash_key'] ) && is_string( $params['cart_hash_key'] ) ) {
            $params['cart_hash_key'] .= '_' . $lang;
        }
        return $params;
    }

public function print_cleanup_js() {
    if ( is_admin() ) return;
    $lang = esc_js( $this->lang_slug() ); ?>
    <script>
    (function(){
      try{
        var ls = window.localStorage, flag = 'tap_frag_cleaned_'+<?php echo "'{$lang}'"; ?>;
        if (!ls.getItem(flag)) {
          ['wc_fragments','wc_cart_hash'].forEach(function(k){ try{ ls.removeItem(k); }catch(e){} });
          ls.setItem(flag, '1');
        }
      }catch(e){}
    })();
    </script>
<?php }
}
new Tap_WC_Lang_Fragments();

/* ================================================================
 * Tapojärvi – kielikohtaiset Cart/Checkout URLit + turvaverkot
 * ================================================================ */

// Nykyisen kielen slug ('fi' / 'en' ...)
if (!function_exists('tap_lang_slug')) {
  function tap_lang_slug() {
    if (function_exists('pll_current_language')) {
      $slug = pll_current_language('slug');
      if ($slug) return strtolower($slug);
    }
    $loc = get_locale();
    return strtolower(substr((string)$loc, 0, 2));
  }
}

// Hae WooCommercen sivun (cart/checkout/…) ID nykykielen käännöksenä
if (!function_exists('tap_wc_tr_page_id')) {
  function tap_wc_tr_page_id($page_key) {
    if (!function_exists('wc_get_page_id')) return 0;
    $id = wc_get_page_id($page_key);
    if (!$id || !function_exists('pll_get_post')) return $id;
    $tr = pll_get_post($id, tap_lang_slug());
    return $tr ? $tr : $id;
  }
}

// A) wc_get_cart_url() → oikea kieli
add_filter('woocommerce_get_cart_url', function($url){
  $tr = tap_wc_tr_page_id('cart');
  return $tr ? get_permalink($tr) : $url;
}, 20);

// B) "Redirect to cart after add" → kunnioita WooCommercen asetusta
add_filter('woocommerce_add_to_cart_redirect', function($url){
  if ('yes' !== get_option('woocommerce_cart_redirect_after_add')) return $url;
  $tr = tap_wc_tr_page_id('cart');
  return $tr ? get_permalink($tr) : $url;
}, 20);

add_filter('woocommerce_get_checkout_url', function($url){
  $tr = tap_wc_tr_page_id('checkout');
  if (!$tr) return $url;

  $new = get_permalink($tr);

  // SALLI VAIN TURVALLISET PARAMETRIT – EI wc-ajax:ia, ei teknisiä avaimia
  $allow = ['lang', 'utm_source', 'utm_medium', 'utm_campaign'];
  $keep  = [];

  foreach ($allow as $k) {
      if (isset($_GET[$k])) {
          $keep[$k] = sanitize_text_field( wp_unslash($_GET[$k]) );
      }
  }

  if ($keep) {
      $new = add_query_arg($keep, $new);
  }

  return $new;
}, 20);

add_action('template_redirect', function(){

  // ÄLÄ puutu AJAX/REST/WC-AJAX -kutsuihin
  if ( (defined('DOING_AJAX') && DOING_AJAX) || isset($_GET['wc-ajax']) || (defined('REST_REQUEST') && REST_REQUEST) ) {
      return;
  }

  if (function_exists('is_cart') && is_cart()) {
      $tr = tap_wc_tr_page_id('cart');
      if ($tr && get_queried_object_id() !== (int)$tr) {
          wp_safe_redirect( get_permalink($tr), 302 );
          exit;
      }
  }

  if (function_exists('is_checkout') && is_checkout()) {
      if (function_exists('is_wc_endpoint_url') &&
          (is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('order-received'))) {
          return; // älä koske maksusivuun / kiitossivuun
      }
      $tr = tap_wc_tr_page_id('checkout');
      if ($tr && get_queried_object_id() !== (int)$tr) {
          wp_safe_redirect( get_permalink($tr), 302 ); // HUOM: ei add_query_arg($_GET, ...)
          exit;
      }
  }
}, 9);

// E) Korjaa myös JS-parametrit:
//    - wc-add-to-cart: "View cart" -napin URL
//    - wc-cart-fragments: wc-ajax -kielen juureen
add_filter('woocommerce_get_script_data', function($params, $handle){
  if ($handle === 'wc-add-to-cart' && is_array($params)) {
    $tr = tap_wc_tr_page_id('cart');
    if ($tr) { $params['cart_url'] = get_permalink($tr); }
  }
  if ($handle === 'wc-cart-fragments' && is_array($params)) {
    $lang = tap_lang_slug();
    if (function_exists('pll_home_url')) {
      $base = rtrim(pll_home_url($lang), '/');
    } else {
      $base = rtrim(home_url('/'), '/');
    }
    $params['wc_ajax_url'] = $base . '/?wc-ajax=%%endpoint%%';
  }
  return $params;
}, 11, 2); // prioriteetti 11 → voittaa muut muokkaajat

/* ================================================================
 * Elementor Pro Mini Cart: "View basket" → "View cart" (en_GB)
 * ================================================================ */

add_filter('gettext', function($translated, $text, $domain){
    if (is_admin()) return $translated;
    if (function_exists('get_locale') && get_locale() !== 'en_GB') return $translated;

    $domains = array('elementor-pro','elementor','woocommerce','default');
    if (!in_array($domain, $domains, true)) return $translated;

    if ($translated === 'View basket' || $translated === 'View Basket') return 'View cart';
    if ($text === 'View cart' || $text === 'View Cart') return 'View cart';

    if ($translated === 'Basket totals') return 'Cart totals';
    if ($translated === 'View basket →') return 'View cart →';
    if ($translated === 'Basket') return 'Cart';

    return $translated;
}, 9999, 3);

add_filter('gettext_with_context', function($translated, $text, $context, $domain){
    if (is_admin()) return $translated;
    if (function_exists('get_locale') && get_locale() !== 'en_GB') return $translated;

    $domains = array('elementor-pro','elementor','woocommerce','default');
    if (!in_array($domain, $domains, true)) return $translated;

    if ($translated === 'View basket' || $translated === 'View Basket') return 'View cart';
    if ($text === 'View cart' || $text === 'View Cart') return 'View cart';
    if ($translated === 'Basket totals') return 'Cart totals';
    if ($translated === 'Basket') return 'Cart';

    return $translated;
}, 9999, 4);

/* ================================================================
 * Oma tili – i18n helperit + lauseiden rekisteröinti Polylangiin
 * ================================================================ */

// Helperit templaateille (esim. for-edit-account.php)
if (!function_exists('tap_t')) {
  function tap_t($fi, $en) {
    // Jos Polylang käytössä ja FI-teksti on rekisteröity, käytä sen käännöstä
    if (function_exists('pll__')) {
      $tr = pll__($fi);
      if ($tr !== $fi) return $tr;
    }
    // Fallback ilman Polylangia: valitse kieli localesta
    return tap_lang_slug()==='fi' ? $fi : $en;
  }
}
if (!function_exists('tap_e')) {
  function tap_e($fi, $en) { echo esc_html( tap_t($fi, $en) ); }
}

// Rekisteröi Oma tili -sivun FI-lauseet Polylangin String translationsiin (ryhmä: My Account).
// Ajetaan varmasti sekä adminissa että frontissa, Polylangin jälkeen.
add_action('plugins_loaded', 'tap_register_account_strings', 20);
add_action('admin_init',    'tap_register_account_strings', 20);
add_action('wp',            'tap_register_account_strings', 20);

function tap_register_account_strings() {
  static $done = false;
  if ($done) return; $done = true;
  if (!function_exists('pll_register_string')) return; // Polylang ei aktiivinen

  $strings = [
    // Ylätason ilmoitukset ja otsikot
    'Tervetuloa!',
    'Täytä alla olevat tiedot – niiden avulla voit selata verkkokauppaa ja tehdä tilauksia työmaasi nimissä.',
    'Tilaajan tiedot',
    'määrittävät, mille työmaalle tilauksesi liitetään. Työmaatunnisteen perusteella näet',
    'tilausten hallinnassa',
    'työmaasi työntekijöiden tilaukset ja voit tilata ne edelleen yhdistetysti. Voit vaihtaa työmaatunnistetta, jos työmaasi vaihtuu tai käsittelet useamman työmaan tilauksia.',
    // Kenttien labelit/placeholderit
    'Etunimi','Sukunimi','Sähköpostiosoite','Puhelin','Titteli',
    'Työmaatunnisteesi','— Valitse työmaa —',
    'Toimitusosoite',
    'Lisää tähän toimitusosoite jonne tilaukset yleensä toimitetaan. Tilauksen vastaanottaja voi olla joku muukin kuin tilaaja.',
    'Katuosoite','Muu osoitetieto','Postinumero','Kaupunki','Maa','— Valitse maa —',
    'Vastaanottajan puhelin',
    'Tallenna muutokset',
  ];

  foreach ($strings as $s) {
    pll_register_string('Account: '.$s, $s, 'My Account');
  }
}
// My Account root -> ohjaa aina "edit-account" -endpointtiin (FI/EN), turvallisesti
add_action('template_redirect', function () {
  if ( headers_sent() ) return;
  if ( ! function_exists('is_account_page') || ! is_account_page() ) return;
  if ( ! is_user_logged_in() ) return;

  // Jos jokin WC-endpoint on jo URLissa, ei ohjata
  if ( function_exists('WC') && WC() && function_exists('is_wc_endpoint_url') ) {
    global $wp;
    $wc_qvars   = array_keys( WC()->query->get_query_vars() );
    $present_ep = array_intersect( array_keys( (array) $wp->query_vars ), $wc_qvars );
    if ( ! empty( $present_ep ) ) return;
  }

  // Selvitä nykykielen My Account -sivun ID
  if ( ! function_exists('wc_get_page_id') ) return;
  $myacc_id = absint( wc_get_page_id('myaccount') );

  // wc_get_page_id voi palauttaa -1 jos sivu ei ole asetettu
  if ( $myacc_id <= 0 ) return;

  // Käännä ID nykykielelle
  if ( function_exists('pll_get_post') ) {
    $lang = function_exists('tap_lang_slug') ? tap_lang_slug() : substr(get_locale(),0,2);
    $tr   = pll_get_post( $myacc_id, $lang );
    if ( $tr ) $myacc_id = (int) $tr;
  }

  // Ohjataan vain, jos ollaan JUURI My Account -sivulla (ei muilla sivuilla)
  if ( get_queried_object_id() !== $myacc_id ) return;

  // Rakenna base & kohde
  $base = get_permalink( $myacc_id );
  if ( ! $base ) return;

  $target = function_exists('wc_get_endpoint_url')
    ? wc_get_endpoint_url( 'edit-account', '', $base )
    : trailingslashit($base) . 'edit-account/';

  // Estä turhat/palautuvat ohjaukset ja varmistetaan sama host
  $current = home_url( add_query_arg( [], $_SERVER['REQUEST_URI'] ?? '' ) );
  if ( untrailingslashit( $target ) === untrailingslashit( $current ) ) return;

  $host_ok = ( parse_url($target, PHP_URL_HOST) === parse_url(home_url('/'), PHP_URL_HOST) );
  if ( ! $host_ok ) return;

  if ( defined('WP_DEBUG') && WP_DEBUG ) {
    error_log('Tap MyAccount redirect: current='.$current.' target='.$target.' myacc_id='.$myacc_id);
  }

  wp_safe_redirect( $target, 302 );
  exit;
}, 0);
// 1) My Account -sivun ID aina nykykielen käännökseksi (vaikuttaa myös valikon linkkien base-URLiin)
add_filter('woocommerce_get_myaccount_page_id', function($page_id){
  $pid = absint($page_id);
  if ($pid <= 0) return $pid;
  if (function_exists('pll_get_post')) {
    $lang = function_exists('tap_lang_slug') ? tap_lang_slug() : substr(get_locale(),0,2);
    $tr   = pll_get_post($pid, $lang);
    if ($tr) return (int)$tr;
  }
  return $pid;
}, 20);

// 2) My Account -juuri → aina edit-account nykykielellä (ilman ID-vertailua)
add_action('template_redirect', function(){
  if (headers_sent()) return;
  if (!function_exists('is_account_page') || !is_account_page()) return;
  if (!is_user_logged_in()) return;

  // Jos URLissa on jo WC-endpoint (orders, edit-account, tms.), älä ohjaa
  if (function_exists('WC') && WC()) {
    global $wp;
    $wc_qvars   = array_keys( WC()->query->get_query_vars() );
    $present_ep = array_intersect( array_keys( (array) $wp->query_vars ), $wc_qvars );
    if (!empty($present_ep)) return;
  }

  // Rakenna kohde nykykielen My Account -permalinkistä
  if (!function_exists('wc_get_page_permalink')) return;
  $base = wc_get_page_permalink('myaccount'); // ← huom: tämä käyttää yllä olevaa ID-filtteriä
  if (!$base) return;

  $target = function_exists('wc_get_endpoint_url')
              ? wc_get_endpoint_url('edit-account', '', $base)
              : trailingslashit($base) . 'edit-account/';

  // Estä turha/looppaava ohjaus
  $current = home_url( add_query_arg([], $_SERVER['REQUEST_URI'] ?? '') );
  if (untrailingslashit($target) === untrailingslashit($current)) return;

  wp_safe_redirect($target, 302);
  exit;
}, 0);
// === Empty cart -nappi (kielitietoinen) + käsittelijä nonce-turvalla ===
// === Empty cart -nappi samalla tyylillä kuin "Päivitä ostoskori" ===
add_action('woocommerce_cart_actions', function () {
    if (!function_exists('wc_get_cart_url')) return;
    if (function_exists('WC') && WC()->cart && WC()->cart->is_empty()) return;

    // Kieliversiot
    $label   = function_exists('tap_t') ? tap_t('Tyhjennä ostoskori', 'Empty cart')
              : ( substr(get_locale(),0,2)==='fi' ? 'Tyhjennä ostoskori' : 'Empty cart' );
    $confirm = function_exists('tap_t') ? tap_t('Haluatko varmasti tyhjentää ostoskorin?', 'Are you sure you want to empty the cart?')
              : ( substr(get_locale(),0,2)==='fi' ? 'Haluatko varmasti tyhjentää ostoskorin?' : 'Are you sure you want to empty the cart?' );

    $url = add_query_arg('tapojarvi_empty_cart', '1', wc_get_cart_url());
    $url = wp_nonce_url($url, 'tap_empty_cart');

    // HUOM: type="button" (ei submit), niin se ei lähetä cart-formia
    printf(
        '<button type="button" class="button wp-element-button tapojarvi-empty-cart-button" data-url="%1$s">%2$s</button>',
        esc_url($url),
        esc_html($label)
    );

    // Pieni JS, joka hoitaa confirmin ja siirtymän
    ?>
    <script>
    (function(){
      var root = document.currentScript && document.currentScript.parentNode ? document.currentScript.parentNode : document;
      root.addEventListener('click', function(e){
        var btn = e.target.closest('.tapojarvi-empty-cart-button');
        if(!btn) return;
        var msg = <?php echo wp_json_encode($confirm); ?>;
        if (confirm(msg)) {
          window.location.href = btn.dataset.url;
        }
      }, { once:false });
    })();
    </script>
    <?php
}, 20);
// Käsittelijä: ?tapojarvi_empty_cart=1 -> tyhjennä kori ja palaa cartiin (nonce-suojattuna)
add_action('template_redirect', function () {
    if (empty($_GET['tapojarvi_empty_cart'])) return;

    // Nonce-tarkistus (wp_nonce_url luotiin napissa avaimella 'tap_empty_cart')
    if (empty($_GET['_wpnonce']) || ! wp_verify_nonce($_GET['_wpnonce'], 'tap_empty_cart')) {
        return; // epäkelpo pyyntö, ei tehdä mitään
    }

    if (function_exists('WC') && WC()->cart) {
        WC()->cart->empty_cart();

        // Kielitietoinen ilmoitus
        $notice = function_exists('tap_t')
            ? tap_t('Ostoskori on tyhjennetty.', 'Your cart has been emptied.')
            : ( substr(get_locale(),0,2)==='fi' ? 'Ostoskori on tyhjennetty.' : 'Your cart has been emptied.' );

        wc_add_notice($notice, 'success');
    }

    // Ohjaa takaisin oikeankieliseen ostoskoriin ja poista query-parametrit
    $redirect = remove_query_arg(['tapojarvi_empty_cart','_wpnonce'], wc_get_cart_url());
    wp_safe_redirect($redirect);
    exit;
}, 0);