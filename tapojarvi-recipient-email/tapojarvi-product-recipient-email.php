<?php
/**
 * Plugin Name: Tapojärvi Product-Specific Recipient Emails
 * Description: Lisää tuotteelle/variaatiolle toimittajan sähköpostikentän ja lähettää tyylikkään kortti-sähköpostin kullekin toimittajalle kun tilausten hallitsija tekee lopullisen tilauksen.
 * Version:     1.5.0
 * Author:      Tapojärvi Oy
 * License:     GPLv3 or later
 * Text Domain: tapojarvi-product-recipient-email
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Lataa käännökset */
add_action( 'plugins_loaded', function() {
	load_plugin_textdomain(
		'tapojarvi-product-recipient-email',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages/'
	);
} );

/**
 * Rekisteröi oma WC_Email-luokka toimittajasähköposteille
 */
add_filter( 'woocommerce_email_classes', 'tapojarvi_register_supplier_email' );
function tapojarvi_register_supplier_email( $email_classes ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-email-supplier.php';
	$email_classes['WC_Email_Supplier'] = new WC_Email_Supplier();
	return $email_classes;
}

/* =====================================================================
 * 1. Tuotteen/variaation Toimittaja-kenttä
 * ===================================================================*/
add_filter( 'woocommerce_product_data_tabs', function ( $tabs ) {
	$tabs['tap_supplier'] = [
		'label'    => __( 'Toimittaja', 'tapojarvi-product-recipient-email' ),
		'target'   => 'tap_supplier_data',
		'class'    => [ 'show_if_simple', 'show_if_variable' ],
		'priority' => 55,
	];
	return $tabs;
} );

add_action( 'woocommerce_product_data_panels', function () { ?>
	<div id="tap_supplier_data" class="panel woocommerce_options_panel hidden">
		<?php
		woocommerce_wp_text_input( [
			'id'          => '_tap_recipient_email',
			'label'       => __( 'Vastaanottajan sähköposti', 'tapojarvi-product-recipient-email' ),
			'desc_tip'    => true,
			'description' => __( 'Syötä toimittajan sähköpostiosoite. Useita osoitteita voi erottaa puolipisteellä tai pilkulla.', 'tapojarvi-product-recipient-email' ),
			'type'        => 'text',
			'placeholder' => __( 'toimittaja@example.com', 'tapojarvi-product-recipient-email' ),
		] );
		?>
	</div>
<?php } );

add_action( 'woocommerce_admin_process_product_object', function ( $product ) {
	if ( isset( $_POST['_tap_recipient_email'] ) ) {
		$product->update_meta_data(
			'_tap_recipient_email',
			sanitize_text_field( wp_unslash( $_POST['_tap_recipient_email'] ) )
		);
	}
} );

// Variaatiokenttä
add_action( 'woocommerce_variation_options', function ( $loop, $variation_data, $variation ) {
	woocommerce_wp_text_input( [
		'id'            => '_tap_recipient_email[' . $loop . ']',
		'label'         => __( 'Vastaanottajan sähköposti', 'tapojarvi-product-recipient-email' ),
		'wrapper_class' => 'form-row full',
		'value'         => get_post_meta( $variation->ID, '_tap_recipient_email', true ),
		'placeholder'   => __( 'toimittaja@example.com', 'tapojarvi-product-recipient-email' ),
	] );
}, 10, 3 );

add_action( 'woocommerce_save_product_variation', function ( $variation_id, $loop ) {
	if ( isset( $_POST['_tap_recipient_email'][ $loop ] ) ) {
		update_post_meta(
			$variation_id,
			'_tap_recipient_email',
			sanitize_text_field( wp_unslash( $_POST['_tap_recipient_email'][ $loop ] ) )
		);
	} else {
		delete_post_meta( $variation_id, '_tap_recipient_email' );
	}
}, 10, 2 );

/* =====================================================================
 * 2. Tilauksen käsittely – oma WC_Email-luokan trigger
 * ===================================================================*/

/**
 * Lähettää toimittaja-sähköpostit.
 *
 * @param int        $order_id
 * @param WC_Order   $order          (WooCommerce välittää 8.6+)
 * @param array|null $limited_items  VAIN rajattu rivijoukko, kun halutaan
 *                                   lähettää tietyt rivit erikseen.
 */
function tap_send_recipient_emails( $order_id, $order = null, $limited_items = null ) {
	if ( ! $order ) {
		$order = wc_get_order( $order_id );
	}
	if ( ! $order ) {
		return;
	}

	// Älä lähetä asiakasroolille
	$user = $order->get_user();
	if ( $user && in_array( 'customer', (array) $user->roles, true ) ) {
		return;
	}

	// Ryhmittele tuotteet osastoittain ja toimittajan mukaan
	$items  = $limited_items ?: $order->get_items();
	$groups = tapojarvi_get_supplier_groups( $items );

	if ( empty( $groups ) ) {
		return;
	}

	$mailer_emails  = WC()->mailer()->get_emails();
	/** @var WC_Email_Supplier $supplier_email */
	if ( empty( $mailer_emails['WC_Email_Supplier'] ) ) {
		return;
	}
	$supplier_email = $mailer_emails['WC_Email_Supplier'];

	foreach ( $groups as $grp ) {
		$supplier_email->trigger( $order_id, $grp['email'], $grp['items'] );
	}
}

/**
 * Ryhmittelee tilauksen rivit toimittaja + osasto -avaimen perusteella
 *
 * @param WC_Order_Item[] $items
 * @return array[]
 */
function tapojarvi_get_supplier_groups( $items ) {
	$groups = [];

	foreach ( $items as $item ) {
		$product = $item->get_product();

		if ( ! $product ) {
			continue;
		}

		$emails_raw = $product->get_meta( '_tap_recipient_email', true );

		if ( ! $emails_raw && $product->is_type( 'variation' ) ) {
			$parent     = wc_get_product( $product->get_parent_id() );
			$emails_raw = $parent ? $parent->get_meta( '_tap_recipient_email', true ) : '';
		}

		if ( ! $emails_raw ) {
			continue;
		}

		$emails = array_filter(
			array_map( 'trim', preg_split( '/[;,]+/', $emails_raw ) ),
			'is_email'
		);

		if ( empty( $emails ) ) {
			continue;
		}

		$dept = $item->get_meta( '_tap_department', true ) ?: 'default';

		foreach ( $emails as $email ) {
			$email = sanitize_email( $email );
			$key   = strtolower( $email ) . '|' . $dept;

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = [
					'email' => $email,
					'dept'  => $dept,
					'items' => [],
				];
			}

			$groups[ $key ]['items'][] = $item;
		}
	}

	return $groups;
}

/* ===================================================================
 * 3. Käynnistä lähetys kun tilaus siirtyy "valmis" tilaan
 * ===================================================================*/
add_action( 'woocommerce_order_status_completed', 'tap_send_recipient_emails', 5, 2 );

/**
 * Tallentaa rivikohteelle "_tap_department" kassalla
 */
add_action( 'woocommerce_checkout_create_order_line_item', function( $item, $cart_item_key, $values, $order ) {
	// Haetaan tuote (huomioidaan variaatiot)
	$product     = $item->get_product();
	$product_id  = $product ? ( $product->get_parent_id() ?: $product->get_id() ) : 0;

	// Otetaan korkeimman tason tuoteryhmä kategoria-slugina,
	// tai fallback 'default', jos kategoriaa ei löydy
	$terms = $product_id ? get_the_terms( $product_id, 'product_cat' ) : false;
	$dept  = 'default';
	if ( $terms && ! is_wp_error( $terms ) ) {
		usort( $terms, fn( $a, $b ) => $a->parent <=> $b->parent );
		$dept = $terms[0]->slug;
	}

	// Tallennetaan order-itemin metaan
	$item->add_meta_data( '_tap_department', $dept, true );
}, 10, 4 );

/* ===================================================================
 * 4. Lähetä toimittajasähköposti uudelleen vain valitulle toimittajalle
 * ===================================================================*/

/**
 * Lähettää toimittajasähköpostin uudelleen vain yhdelle valitulle toimittajalle.
 *
 * Huom: jos samalla sähköpostilla on useita osastoryhmiä, kaikki saman
 * sähköpostin ryhmät lähetetään.
 *
 * @param int      $order_id
 * @param WC_Order $order
 * @param string   $target_email
 * @return bool
 */
function tap_send_recipient_email_to_single_supplier( $order_id, $order = null, $target_email = '' ) {
	if ( ! $order ) {
		$order = wc_get_order( $order_id );
	}

	if ( ! $order || empty( $target_email ) || ! is_email( $target_email ) ) {
		return false;
	}

	$groups = tapojarvi_get_supplier_groups( $order->get_items() );

	if ( empty( $groups ) ) {
		$order->add_order_note(
			__( 'Toimittajasähköpostin uudelleenlähetys epäonnistui: toimittajaryhmiä ei löytynyt tilaukselta.', 'tapojarvi-product-recipient-email' )
		);
		return false;
	}

	$mailer_emails = WC()->mailer()->get_emails();

	if ( empty( $mailer_emails['WC_Email_Supplier'] ) ) {
		$order->add_order_note(
			__( 'Toimittajasähköpostin uudelleenlähetys epäonnistui: WC_Email_Supplier ei ole käytettävissä.', 'tapojarvi-product-recipient-email' )
		);
		return false;
	}

	$supplier_email = $mailer_emails['WC_Email_Supplier'];
	$target_email   = strtolower( trim( $target_email ) );
	$matched        = false;
	$sent_success   = false;

	foreach ( $groups as $grp ) {
		if ( strtolower( trim( $grp['email'] ) ) !== $target_email ) {
			continue;
		}

		$matched = true;

		$result = $supplier_email->trigger( $order_id, $grp['email'], $grp['items'] );

		if ( $result ) {
			$sent_success = true;
		}
	}

	if ( ! $matched ) {
		$order->add_order_note(
			sprintf(
				__( 'Toimittajasähköpostin uudelleenlähetys epäonnistui: valittua toimittajaa ei löytynyt tilaukselta (%s).', 'tapojarvi-product-recipient-email' ),
				sanitize_email( $target_email )
			)
		);
		return false;
	}

	if ( $sent_success ) {
		$order->add_order_note(
			sprintf(
				__( 'Toimittajasähköposti lähetetty uudelleen valitulle toimittajalle: %s', 'tapojarvi-product-recipient-email' ),
				sanitize_email( $target_email )
			)
		);
		return true;
	}

	$order->add_order_note(
		sprintf(
			__( 'Toimittajasähköpostin uudelleenlähetys epäonnistui vastaanottajalle: %s', 'tapojarvi-product-recipient-email' ),
			sanitize_email( $target_email )
		)
	);

	return false;
}

/**
 * Hakee tilaukselta uniikit toimittajasähköpostit.
 *
 * @param WC_Order $order
 * @return array
 */
function tapojarvi_get_order_supplier_email_options( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return [];
	}

	$groups = tapojarvi_get_supplier_groups( $order->get_items() );
	$emails = [];

	foreach ( $groups as $grp ) {
		if ( empty( $grp['email'] ) || ! is_email( $grp['email'] ) ) {
			continue;
		}

		$email = sanitize_email( $grp['email'] );
		$emails[ strtolower( $email ) ] = $email;
	}

	return array_values( $emails );
}

/**
 * Lisää tilauksen sivupalkkiin laatikon valitun toimittajan uudelleenlähetykselle.
 */
add_action( 'add_meta_boxes', function() {
	$screen = wc_get_page_screen_id( 'shop-order' );

	add_meta_box(
		'tapojarvi_resend_single_supplier_email',
		__( 'Lähetä toimittajalle uudelleen', 'tapojarvi-product-recipient-email' ),
		'tapojarvi_render_resend_single_supplier_metabox',
		$screen,
		'side',
		'default'
	);
} );

/**
 * Renderöi metaboxin sisällön.
 *
 * @param WP_Post $post
 * @return void
 */
function tapojarvi_render_resend_single_supplier_metabox( $post_or_order ) {
	$order = false;

	if ( $post_or_order instanceof WC_Order ) {
		$order = $post_or_order;
	} elseif ( is_object( $post_or_order ) && ! empty( $post_or_order->ID ) ) {
		$order = wc_get_order( $post_or_order->ID );
	}

	if ( ! $order && isset( $_GET['id'] ) ) {
		$order = wc_get_order( absint( $_GET['id'] ) );
	}

	if ( ! $order && isset( $_GET['post'] ) ) {
		$order = wc_get_order( absint( $_GET['post'] ) );
	}

	if ( ! $order ) {
		echo '<p>' . esc_html__( 'Tilausta ei löytynyt.', 'tapojarvi-product-recipient-email' ) . '</p>';
		return;
	}

	$emails = tapojarvi_get_order_supplier_email_options( $order );

	if ( empty( $emails ) ) {
		echo '<p>' . esc_html__( 'Tälle tilaukselle ei löytynyt toimittajasähköposteja.', 'tapojarvi-product-recipient-email' ) . '</p>';
		return;
	}

	$action_url = admin_url( 'admin-post.php' );

	echo '<p>' . esc_html__( 'Valitse toimittaja, jolle sähköposti lähetetään uudelleen.', 'tapojarvi-product-recipient-email' ) . '</p>';

	echo '<input type="hidden" name="tapojarvi_resend_action" value="1">';
	echo '<input type="hidden" name="action" value="tapojarvi_resend_single_supplier_email">';
	echo '<input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
	wp_nonce_field( 'tapojarvi_resend_single_supplier_email_' . $order->get_id() );

	echo '<select name="tap_supplier_email" style="width:100%; margin-bottom:10px;">';

	foreach ( $emails as $email ) {
		echo '<option value="' . esc_attr( $email ) . '">' . esc_html( $email ) . '</option>';
	}

	echo '</select>';

	echo '<button type="submit" class="button button-primary" style="width:100%;" formaction="' . esc_url( $action_url ) . '" formmethod="post">';
	echo esc_html__( 'Lähetä valitulle toimittajalle', 'tapojarvi-product-recipient-email' );
	echo '</button>';
}

/**
 * Käsittelee metaboxin lähetyksen.
 */
add_action( 'admin_post_tapojarvi_resend_single_supplier_email', function() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'Sinulla ei ole oikeuksia tähän toimintoon.', 'tapojarvi-product-recipient-email' ) );
	}

	$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

	if ( ! $order_id ) {
		wp_die( esc_html__( 'Virheellinen tilaus-ID.', 'tapojarvi-product-recipient-email' ) );
	}

	check_admin_referer( 'tapojarvi_resend_single_supplier_email_' . $order_id );

	$target_email = isset( $_POST['tap_supplier_email'] )
		? sanitize_email( wp_unslash( $_POST['tap_supplier_email'] ) )
		: '';

	$order = wc_get_order( $order_id );

	if ( ! $order ) {
		wp_die( esc_html__( 'Tilausta ei löytynyt.', 'tapojarvi-product-recipient-email' ) );
	}

	$success = tap_send_recipient_email_to_single_supplier( $order_id, $order, $target_email );

	$redirect_url = wp_get_referer();

	if ( ! $redirect_url ) {
		$redirect_url = add_query_arg(
			[
				'post'   => $order_id,
				'action' => 'edit',
			],
			admin_url( 'post.php' )
		);
	}

	$redirect_url = add_query_arg(
		[
			'tap_supplier_resend_status' => $success ? 'success' : 'failed',
		],
		$redirect_url
	);

	wp_safe_redirect( $redirect_url );
	exit;
} );

/**
 * Näyttää onnistumis-/virheilmoituksen tilauksen muokkaussivulla.
 */
add_action( 'admin_notices', function() {
	if ( empty( $_GET['tap_supplier_resend_status'] ) ) {
		return;
	}

	$status = sanitize_text_field( wp_unslash( $_GET['tap_supplier_resend_status'] ) );

	if ( 'success' === $status ) {
		echo '<div class="notice notice-success is-dismissible"><p>'
			. esc_html__( 'Toimittajasähköposti lähetettiin uudelleen valitulle toimittajalle.', 'tapojarvi-product-recipient-email' )
			. '</p></div>';
	} elseif ( 'failed' === $status ) {
		echo '<div class="notice notice-error is-dismissible"><p>'
			. esc_html__( 'Toimittajasähköpostin uudelleenlähetys epäonnistui.', 'tapojarvi-product-recipient-email' )
			. '</p></div>';
	}
} );