<?php
/*
Plugin Name: Tapojärvi – Worksite Pre-order
Description: Lisää kassalle tilausten hallitsijalle painikkeen, jolla voi valita, meneekö tilaus työmaatilaukset-sivulle vai toimittajalle.
Version:     1.3.2
Author:      Tapojärvi Oy - Arto Huhta
Text Domain: tapojarvi-preorder
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) exit;

/** Lataa gettext-käännökset /languages-kansiosta (fallback Polylangille) */
add_action( 'plugins_loaded', function () {
	load_plugin_textdomain(
		'tapojarvi-preorder',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
} );

/* -----------------------------------------------------------------
 * Polylang-apurit + lauseiden rekisteröinti String translationsiin
 * -----------------------------------------------------------------*/

/**
 * Hae käännetty lause: Polylang → gettext → alkuperäinen.
 */
if ( ! function_exists('tap_pre_tr') ) {
	function tap_pre_tr( $text ) {
		if ( function_exists('pll__') ) {
			$tr = pll__($text);
			if ( is_string($tr) && $tr !== '' ) return $tr;
		}
		return __($text, 'tapojarvi-preorder');
	}
}

/** Rekisteröi näkyvät lauseet Polylangille */
add_action('plugins_loaded', function () {
	if ( ! function_exists('pll_register_string') ) return;

	$strings = [
		// Toggle-label kassalla
		'Lisää tämä tilaus työmaan yhteistilaukseen',

		// Status-otsikko
		'Nykyinen tilaustapa:',

		// Status-sisällöt
		'Tilaus lisätään työmaan keräyslistaan ja toimitetaan myöhemmin osana yhteistilausta.',
		'Suositeltava vaihtoehto, kun tilaus halutaan yhdistää muiden kanssa.',
		'Tilaus lähetetään suoraan toimittajille, eikä jää työmaan keräyslistaan.',
		'Suositellaan esimerkiksi turvallisuusmateriaalien tai laatikossa toimitettavien tuotteiden tilaamiseen.',

		// Modaalit – suora tilaus
		'Valittu toimitustapa: <br>Suora tilaus toimittajalta',
		'Tämä tilaustapa lähettää tilauksen suoraan tuotteiden toimittajalle eikä se tallennu työmaan yhteistilaukseen.',
		'Tätä tilaustapaa tulee käyttää esimerkiksi turvallisuusmateriaaleihin tai laatikkotuotteisiin, joita ei voi tilata yksittäin.',
		'Jos haluat kerätä tilauksen yhteistilaukseen ja lähettää myöhemmin muiden tilausten kanssa, sulje tämä ikkuna ja aktivoi “Lisää tämä tilaus työmaan yhteistilaukseen”.',
		'Haluatko varmasti jatkaa?',

		// Modaalit – yhteistilaus
		'Valittu toimitustapa: <br>Työmaan yhteistilaus',
		'Tämä tilaustapa lisää tilauksen työmaan keräyslistaan työmaatilaukset-sivulle, eikä tilausta vielä toimiteta toimittajille.',
		'Keräyslista on tarkoitettu vaatekokojen ja mallien keräämiseen yhteistilausta varten. Jos haluat tilata turvallisuusmateriaaleja tai laatikkotavaroita suoraan toimittajalta, sulje tämä ikkuna ja poista edellä mainittu valinta.',

		// Nappitekstit
		'Kyllä, jatka',
		'Peruuta',
	];

	foreach ( $strings as $s ) {
		pll_register_string( 'Preorder: '.$s, $s, 'Preorder' );
	}
}, 20);


/* ============================
 *   Varsinainen lisäosa
 * ============================*/

final class Tapojarvi_Preorder {

	const META_KEY = '_worksite_preorder';

	public function __construct() {
		/* 1) kenttä kassalle */
		add_action( 'woocommerce_review_order_before_submit', [ $this, 'render_toggle_field' ] );

		/* 2) status heti tilauksen synnyttyä */
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'set_status_after_checkout' ], 10, 3 );

		/* 3) estä payment_complete-auto-finish */
		add_filter( 'woocommerce_payment_complete_order_status', [ $this, 'filter_payment_complete_status' ], 10, 3 );

		/* 4) viimeinen turvaverkko – jos jokin muu pakottaa Completed-tilaan */
		add_action( 'woocommerce_order_status_changed', [ $this, 'maybe_revert_completed' ], 10, 4 );

		/* 5) inline-CSS + JS (ladataan varmasti) */
		add_action( 'wp_enqueue_scripts', [ $this, 'inline_css' ], 99 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_js' ], 100 );
	}

	/* ---------- util ---------- */

	private function is_manager() : bool {
		return current_user_can( 'administrator' )
		    || current_user_can( 'tilausten_hallitsija' );
	}

	/* ---------- field ---------- */

	public function render_toggle_field() {

		if ( ! $this->is_manager() ) {
			return;
		}

		echo '
<div id="tapojarvi-preorder-wrap" class="form-row form-row-wide">
	<div class="tapo-toggle-line">
		<label class="tapo-toggle-wrap">
			<!-- aina jokin arvo POSTissa -->
			<input type="hidden" name="worksite_preorder" value="0" />
			<input type="checkbox"
				   id="worksite_preorder"
				   name="worksite_preorder"
				   value="1"
				   class="tapojarvi-toggle" />
			<span class="tapo-toggle" aria-hidden="true"></span>
		</label>
		<span class="tapo-label">' .
			esc_html( tap_pre_tr('Lisää tämä tilaus työmaan yhteistilaukseen') ) .
		'</span>
	</div>
	<div class="tapo-text-block">
		<div id="toggle-status-message" class="tapo-status-message"></div>
	</div>
</div>';
	}

	/* ---------- status 1/3 ---------- */

	public function set_status_after_checkout( $order_id, $posted_data, $order ) {

		$is_manager  = $this->is_manager();
		$is_preorder = isset( $_POST['worksite_preorder'] )
			&& wp_unslash( $_POST['worksite_preorder'] ) === '1'
			&& $is_manager;

		if ( $is_preorder ) {
			$order->update_meta_data( self::META_KEY, 'yes' );
			$order->set_status( 'pending' );
		} elseif ( $is_manager ) {
			$order->update_meta_data( self::META_KEY, 'no' );
			$order->set_status( 'completed' );
		}
		$order->save();
	}

	/* ---------- status 2/3 ---------- */

	public function filter_payment_complete_status( $status, $order_id, $order ) {
		return ( 'yes' === $order->get_meta( self::META_KEY ) ) ? 'pending' : $status;
	}

	/* ---------- status 3/3 ---------- */

	public function maybe_revert_completed( $order_id, $old_status, $new_status, $order ) {
		if ( 'completed' === $new_status && 'yes' === $order->get_meta( self::META_KEY ) ) {
			$order->update_status( 'pending' );
		}
	}

	/* ---------- inline CSS ---------- */

	public function inline_css() {

		if ( ! $this->is_manager() ) return;

$css = "
#tapojarvi-preorder-wrap {
	padding: 1.5rem;
	background: #fef9c3;
	border: 2px solid #fee000;
	border-radius: 8px;
	margin-bottom: 1.5rem;
	font-size: 1.1rem;
	box-shadow: 0 0 4px rgba(0,0,0,0.05);
	text-align: center;
}
.tapo-text-block { text-align: center; }
.tapo-toggle-line {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 1rem;
	margin-bottom: 0.75rem;
}
input.tapojarvi-toggle {
	position: absolute;
	opacity: 0;
	width: 1px;
	height: 1px;
}
.tapo-toggle {
	position: relative;
	display: inline-block;
	width: 54px;
	height: 30px;
	background: #d1d5db;
	border-radius: 9999px;
	cursor: pointer;
	transition: background .25s;
}
.tapo-toggle::after {
	content: '';
	position: absolute;
	top: 3px;
	left: 3px;
	width: 24px;
	height: 24px;
	border-radius: 50%;
	background: #fff;
	box-shadow: 0 1px 3px rgba(0,0,0,.3);
	transition: transform .25s;
}
input.tapojarvi-toggle:checked + .tapo-toggle { background: #10b981; }
input.tapojarvi-toggle:checked + .tapo-toggle::after { transform: translateX(24px); }
.tapo-label {
	font-size: 1.25rem;
	font-weight: 600;
	line-height: 1;
	margin: 0;
}
.tapo-toggle-wrap { display: inline-block; position: relative; }
.tapo-desc { display: block; font-size: 0.95rem; color: #333; margin-top: 0.5rem; }
#tapojarvi-preorder-wrap label { line-height: 1 !important; }
.tapo-status-message { margin-top: 0.75rem; font-size: 1rem; color: #1f2937; font-weight: 500; }
";
		wp_register_style( 'tapojarvi-preorder-inline', false );
		wp_enqueue_style(  'tapojarvi-preorder-inline' );
		wp_add_inline_style( 'tapojarvi-preorder-inline', $css );
	}

	/* ---------- JS + lokalisaatio ---------- */

	public function enqueue_js() {
		if ( ! $this->is_manager() ) return;
		if ( ! function_exists('is_checkout') || ! is_checkout() ) return;

		// Varmistetaan riippuvuudet: jQuery + SweetAlert2 + wc-checkout
		wp_register_script( 'tapojarvi-preorder-js', false, [ 'jquery', 'sweetalert2', 'wc-checkout' ], '1.3.2', true );

		// Käännettävät tekstit JS:lle (Polylang → gettext → fallback)
		wp_localize_script( 'tapojarvi-preorder-js', 'TAPO_PREORDER', [
			'statusPrefix' => tap_pre_tr('Nykyinen tilaustapa:'),

			// Status-tekstit
			'statusPreorderHtml' => sprintf(
				'<strong>%1$s</strong> %2$s<br>%3$s',
				esc_html( tap_pre_tr('Nykyinen tilaustapa:') ),
				esc_html( tap_pre_tr('Tilaus lisätään työmaan keräyslistaan ja toimitetaan myöhemmin osana yhteistilausta.') ),
				esc_html( tap_pre_tr('Suositeltava vaihtoehto, kun tilaus halutaan yhdistää muiden kanssa.') )
			),
			'statusDirectHtml' => sprintf(
				'<strong>%1$s</strong> %2$s<br>%3$s',
				esc_html( tap_pre_tr('Nykyinen tilaustapa:') ),
				esc_html( tap_pre_tr('Tilaus lähetetään suoraan toimittajille, eikä jää työmaan keräyslistaan.') ),
				esc_html( tap_pre_tr('Suositellaan esimerkiksi turvallisuusmateriaalien tai laatikossa toimitettavien tuotteiden tilaamiseen.') )
			),

			// Modal: suora tilaus
			'modalDirectTitle' => tap_pre_tr('Valittu toimitustapa: <br>Suora tilaus toimittajalta'),
			'modalDirectHtml'  =>
				'<p>' . esc_html( tap_pre_tr('Tämä tilaustapa lähettää tilauksen suoraan tuotteiden toimittajalle eikä se tallennu työmaan yhteistilaukseen.') ) . '</p>'
				. '<p>' . esc_html( tap_pre_tr('Tätä tilaustapaa tulee käyttää esimerkiksi turvallisuusmateriaaleihin tai laatikkotuotteisiin, joita ei voi tilata yksittäin.') ) . '</p>'
				. '<p>' . esc_html( tap_pre_tr('Jos haluat kerätä tilauksen yhteistilaukseen ja lähettää myöhemmin muiden tilausten kanssa, sulje tämä ikkuna ja aktivoi “Lisää tämä tilaus työmaan yhteistilaukseen”.') ) . '</p>'
				. '<p><strong>' . esc_html( tap_pre_tr('Haluatko varmasti jatkaa?') ) . '</strong></p>',

			// Modal: yhteistilaus
			'modalPreorderTitle' => tap_pre_tr('Valittu toimitustapa: <br>Työmaan yhteistilaus'),
			'modalPreorderHtml'  =>
				'<p>' . esc_html( tap_pre_tr('Tämä tilaustapa lisää tilauksen työmaan keräyslistaan työmaatilaukset-sivulle, eikä tilausta vielä toimiteta toimittajille.') ) . '</p>'
				. '<p><em>' . esc_html( tap_pre_tr('Keräyslista on tarkoitettu vaatekokojen ja mallien keräämiseen yhteistilausta varten. Jos haluat tilata turvallisuusmateriaaleja tai laatikkotavaroita suoraan toimittajalta, sulje tämä ikkuna ja poista edellä mainittu valinta.') ) . '</em></p>'
				. '<p><strong>' . esc_html( tap_pre_tr('Haluatko varmasti jatkaa?') ) . '</strong></p>',

			'confirm' => tap_pre_tr('Kyllä, jatka'),
			'cancel'  => tap_pre_tr('Peruuta'),
		] );

		wp_enqueue_script( 'tapojarvi-preorder-js' );

		// Varsinainen logiikka (lukee lokalisoidut tekstit)
		$inline = <<<'JS'
jQuery(function($) {
	let submitting = false;

	function updateToggleMessage() {
		const $toggle = $('#worksite_preorder');
		const $status = $('#toggle-status-message');
		if (!$toggle.length || !$status.length) return;

		if ($toggle.is(':checked')) {
			$status.html(TAPO_PREORDER.statusPreorderHtml);
		} else {
			$status.html(TAPO_PREORDER.statusDirectHtml);
		}
	}

	$(document.body).on('updated_checkout', updateToggleMessage);
	$(document).on('change', '#worksite_preorder', updateToggleMessage);
	setTimeout(updateToggleMessage, 150);

	$('form.checkout').on('submit.prevent-modal', function(e) {
		if (submitting) return true;

		var $form = $(this);
		var isChecked = $('#worksite_preorder').is(':checked');
		var hasSwal = (typeof Swal !== 'undefined');

		// Jos SweetAlert ei ole saatavilla, älä estä lähetystä
		if (!hasSwal) return true;

		e.preventDefault();
		e.stopImmediatePropagation();

		const modalOptions = isChecked ? {
			title: TAPO_PREORDER.modalPreorderTitle,
			html:  TAPO_PREORDER.modalPreorderHtml,
			icon: 'info',
			showCancelButton: true,
			confirmButtonText: TAPO_PREORDER.confirm,
			cancelButtonText:  TAPO_PREORDER.cancel,
			buttonsStyling: false
		} : {
			title: TAPO_PREORDER.modalDirectTitle,
			html:  TAPO_PREORDER.modalDirectHtml,
			icon: 'warning',
			showCancelButton: true,
			confirmButtonText: TAPO_PREORDER.confirm,
			cancelButtonText:  TAPO_PREORDER.cancel,
			buttonsStyling: false
		};

		Swal.fire(modalOptions).then(function(result){
			if (result.isConfirmed) {
				submitting = true;
				// Poista vain tämän lisäosan oma submit-estäjä ja käynnistä wc-checkoutin oma polku
				$form.off('submit.prevent-modal');
				$form.find('#place_order').trigger('click');
			}
		});
	});
});
JS;
		wp_add_inline_script( 'tapojarvi-preorder-js', $inline );
	}
}

/* Käynnistä */
new Tapojarvi_Preorder();

/* SweetAlert2 vain kassalla hallitsijalle tai adminille */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! ( current_user_can( 'administrator' ) || current_user_can( 'tilausten_hallitsija' ) ) ) return;
	if ( ! function_exists('is_checkout') || ! is_checkout() ) return;

	// Voit korvata CDN:n paikallisella tiedostolla tarvittaessa
	wp_enqueue_script( 'sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], null, true );
}, 5 );

/* Estä asiakkaan Completed- ja Processing-sähköpostit preorder-tilauksilta */
add_filter( 'woocommerce_email_enabled_customer_completed_order', function( $enabled, $order ) {
	if ( is_a( $order, 'WC_Order' ) && $order->get_meta('_worksite_preorder') === 'yes' ) {
		return false;
	}
	return $enabled;
}, 10, 2 );

add_filter( 'woocommerce_email_enabled_customer_processing_order', function( $enabled, $order ) {
	if ( is_a( $order, 'WC_Order' ) && $order->get_meta('_worksite_preorder') === 'yes' ) {
		return false;
	}
	return $enabled;
}, 10, 2 );

/* (Valinnainen mutta suositus)
 * Tyhjennä ostoskori heti, jos tilaus merkittiin preorderiksi (pending) */
add_action('woocommerce_checkout_order_processed', function($order_id, $posted_data, $order){
	$flag = $order->get_meta('_worksite_preorder');
	$is_preorder = in_array($flag, ['yes','1',1,true], true);
	if ( $is_preorder && function_exists('WC') && WC()->cart ) {
		WC()->cart->empty_cart();
	}
}, 100, 3 );