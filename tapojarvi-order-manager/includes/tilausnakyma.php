<?php
/* ---------------------------------------------------------
 *  TILAAJAHALLINTA – tilausnakyma.php  (drop-in, idempotent)
 * --------------------------------------------------------*/

// Estä suora pääsy
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Jos tämä tiedosto on jo ajettu tässä requestissa,
 * lopetetaan heti. Näin vältetään funktioiden tuplamäärittely.
 */
if ( defined( 'TAP_TILAUSNAKYMA_INCLUDED' ) ) {
    return;
}
define( 'TAP_TILAUSNAKYMA_INCLUDED', true );

/** --------------------------------------------------------
 *  Apuri näkymätiedoston includelle
 * ------------------------------------------------------ */
if ( ! function_exists( 'tilausnakyma_include_view' ) ) {
    /**
     * Lataa näkymätiedosto parametrien kera tai näyttää virheilmoituksen jos tiedostoa ei löydy.
     *
     * @param string $file Polku ladattavaan tiedostoon.
     * @param array  $vars Muuttujat, jotka extractataan näkymään.
     */
    function tilausnakyma_include_view( $file, $vars ) {
        if ( file_exists( $file ) ) {
            extract( $vars );
            include $file;
        } else {
            echo '<div class="error">' . esc_html__( 'Virhe: näkymätiedostoa ei löytynyt.', 'tapojarvi' ) . '</div>';
        }
    }
}

/** --------------------------------------------------------
 *  Lomakäsittelijä – MASSATOIMINNOT
 *  HUOM: jätetään nimi ennalleen (kirjoitusvirhe mukaan lukien),
 *  koska koodissa viitataan tähän nimeen.
 * ------------------------------------------------------ */
if ( ! function_exists( 'datahanlder_tilausnakyma_handle_bulk_actions' ) ) {
function datahanlder_tilausnakyma_handle_bulk_actions( $tyomaa_koodi ) {
    // Hyväksy JA SIIRRÄ OSTOSKORIIN
    if ( isset( $_POST['bulk_approve_submit'], $_POST['bulk_approve_ids'] ) && is_array( $_POST['bulk_approve_ids'] ) ) {
        $count = 0;
        foreach ( array_map( 'intval', $_POST['bulk_approve_ids'] ) as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order || ! in_array( $order->get_status(), [ 'pending', 'on-hold' ], true ) ) {
                continue;
            }
            foreach ( $order->get_items() as $item ) {
                $vars = [];
                foreach ( $item->get_meta_data() as $m ) {
                    if ( taxonomy_exists( $m->key ) || str_starts_with( $m->key, 'pa_' ) ) {
                        $vars[ $m->key ] = $m->value;
                    }
                }
                WC()->cart->add_to_cart(
                    $item->get_product_id(),
                    $item->get_quantity(),
                    $item->get_variation_id(),
                    $vars
                );
            }
            $order->update_status( 'manager-approved', __( 'Hyväksytty, siirretty ostoskoriin', 'tapojarvi' ), true );
            $order->update_meta_data( '_manager_approved', '1' );
            $order->save();
            $count++;
        }

        wc_add_notice(
            "&#x2705; {$count} tilaus" . ( $count === 1 ? '' : 'ta' ) . " hyväksyttiin ja siirrettiin ostoskoriin.",
            'success'
        );
        wp_safe_redirect( wc_get_cart_url() );
        exit;
    }

    // Hylkää (peru tilaus)
    if ( isset( $_POST['bulk_reject_submit'], $_POST['bulk_approve_ids'] ) && is_array( $_POST['bulk_approve_ids'] ) ) {
        $msg   = sanitize_textarea_field( $_POST['manager_message'] ?? '' );
        $count = 0;

        foreach ( array_map( 'intval', $_POST['bulk_approve_ids'] ) as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order || ! in_array( $order->get_status(), [ 'pending', 'on-hold' ], true ) ) {
                continue;
            }
            $order->update_status( 'cancelled', __( 'Hylätty hallitsijan toimesta', 'tapojarvi' ), true );
            $order->update_meta_data( '_manager_approved', 'rejected' );
            if ( $msg ) {
                $order->add_order_note( $msg, true );
            }
            $order->save();
            $count++;
        }

        wp_safe_redirect( add_query_arg( 'success', 'rejected_' . $count, $_SERVER['REQUEST_URI'] ) );
        exit;
    }

    // Palauta tilaajalle
    if ( isset( $_POST['bulk_return_submit'], $_POST['bulk_approve_ids'] ) && is_array( $_POST['bulk_approve_ids'] ) ) {
        $msg   = sanitize_textarea_field( $_POST['manager_message'] ?? '' );
        $count = 0;

        foreach ( array_map( 'intval', $_POST['bulk_approve_ids'] ) as $id ) {
            $order = wc_get_order( $id );
            if ( ! $order || ! in_array( $order->get_status(), [ 'pending', 'on-hold' ], true ) ) {
                continue;
            }

            $order->update_status( 'manager-returned', __( 'Palautettu tilaajalle', 'tapojarvi' ), true );

            if ( $msg ) {
                $order->add_order_note( $msg, true );
                do_action( 'tapojarvi_notify_returned', $id, $msg );
            }

            $order->update_meta_data( '_manager_approved', 'returned' );
            $order->save();
            $count++;
        }

        wp_safe_redirect( add_query_arg( 'success', 'returned_' . $count, $_SERVER['REQUEST_URI'] ) );
        exit;
    }

    // Lisää hyväksytyt ostoskoriin
    if ( isset( $_POST['bulk_to_cart_submit'], $_POST['bulk_approve_ids'] ) && is_array( $_POST['bulk_approve_ids'] ) ) {
        $count = 0;

        foreach ( array_map( 'intval', $_POST['bulk_approve_ids'] ) as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order || $order->get_status() !== 'manager-approved' ) {
                continue;
            }

            foreach ( $order->get_items() as $item ) {
                $vars = [];
                foreach ( $item->get_meta_data() as $m ) {
                    if ( taxonomy_exists( $m->key ) || str_starts_with( $m->key, 'pa_' ) ) {
                        $vars[ $m->key ] = $m->value;
                    }
                }

                WC()->cart->add_to_cart(
                    $item->get_product_id(),
                    $item->get_quantity(),
                    $item->get_variation_id(),
                    $vars
                );
            }

            $count++;
        }

        wc_add_notice(
            "🛒 {$count} tilaus" . ( $count === 1 ? '' : 'ta' ) . " lisättiin ostoskoriin. 
Voit halutessasi tulostaa tilauslistan PDF-muodossa hyväksytyistä tilauksista työmaatilaukset-sivulta: 
<a href='" . esc_url( home_url( '/tilausten-hallinta/?tab=approved' ) ) . "' 
class='button wc-forward' target='_blank' style='margin-left: 10px;'>Näytä hyväksytyt tilaukset</a>",
            'success'
        );

        wp_safe_redirect( wc_get_cart_url() );
        exit;
    }

    // Poista (vain _manager_approved = 1)
    if ( isset( $_POST['bulk_delete_submit'], $_POST['bulk_delete_ids'] ) ) {
        $ids   = explode( ',', sanitize_text_field( $_POST['bulk_delete_ids'] ) );
        $count = 0;
        foreach ( $ids as $order_id ) {
            $order = wc_get_order( (int) $order_id );
            if ( $order && $order->get_meta( '_manager_approved' ) == '1' ) {
                $order->delete( true );
                $count++;
            }
        }
        wp_safe_redirect( add_query_arg( 'success', 'deleted_' . $count, $_SERVER['REQUEST_URI'] ) );
        exit;
    }

    // Hyväksy vain (ei siirtoa ostoskoriin)
    if ( isset( $_POST['bulk_approve_only_submit'] ) && $_POST['bulk_approve_only_submit'] == '1' ) {
        $approved_order_ids = isset( $_POST['bulk_approve_ids'] ) ? (array) $_POST['bulk_approve_ids'] : [];
        $approved_count     = 0;

        foreach ( $approved_order_ids as $order_id ) {
            $order = wc_get_order( (int) $order_id );
            if ( $order ) {
                if ( $order->get_status() === 'manager-approved' ) {
                    continue; // jo hyväksytty
                }
                $order->update_status( 'manager-approved', __( 'Hyväksytty hallitsijan toimesta', 'tapojarvi' ), true );
                $order->update_meta_data( '_manager_approved', '1' );
                $order->save();
                $approved_count++;
            }
        }

        wp_safe_redirect(
            add_query_arg(
                'success',
                'approved_' . $approved_count,
                add_query_arg( 'tab', 'approved', $_SERVER['REQUEST_URI'] )
            )
        );
        exit;
    }

    // Arkistoi
    if ( isset( $_POST['bulk_archive_submit'], $_POST['bulk_approve_ids'] ) && is_array( $_POST['bulk_approve_ids'] ) ) {
        $count = 0;

        foreach ( array_map( 'intval', $_POST['bulk_approve_ids'] ) as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order || $order->get_status() !== 'manager-approved' ) {
                continue;
            }

            $order->update_status( 'archived', __( 'Tilaus arkistoitu massatoiminnolla.', 'tapojarvi' ), true );
            $count++;
        }

        wp_safe_redirect( add_query_arg( 'success', 'archived_' . $count, $_SERVER['REQUEST_URI'] ) );
        exit;
    }
}
} // function_exists-guard päättyy

/** --------------------------------------------------------
 *  Arkistointipainike yksittäiselle tilaukselle (POST)
 * ------------------------------------------------------ */
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['archive_order_id'] ) ) {
    $order_id = intval( $_POST['archive_order_id'] );
    $order    = wc_get_order( $order_id );

    if ( $order && $order->get_status() === 'manager-approved' ) {
        $order->update_status( 'archived', __( 'Tilaus arkistoitu.', 'tapojarvi' ) );
    }
}

/** --------------------------------------------------------
 *  Tabin valinta + oikeustarkistukset + listaukset
 * ------------------------------------------------------ */
$valid_tabs = [ 'new', 'approved', 'archived' ];
$tab        = isset( $_GET['tab'] ) && in_array( $_GET['tab'], $valid_tabs, true ) ? $_GET['tab'] : 'new';

if ( ! is_user_logged_in() ) {
    echo esc_html__( 'Kirjaudu sisään nähdäksesi tilaukset.', 'tapojarvi' );
    return;
}

$user = wp_get_current_user();
if ( ! array_intersect( [ 'tilausten_hallitsija', 'administrator' ], $user->roles ) ) {
    echo esc_html__( 'Sinulla ei ole oikeuksia nähdä tätä sivua.', 'tapojarvi' );
    return;
}

$tyomaa_koodi = get_user_meta( $user->ID, 'tyomaa_koodi', true );
if ( ! $tyomaa_koodi ) {
    echo esc_html__( 'Työmaakoodi puuttuu käyttäjältä.', 'tapojarvi' );
    return;
}

/* ---------- Päivämääräväli­suodatus (arkisto) ---------- */
$start_date_raw = isset( $_GET['tap_start_date'] ) ? sanitize_text_field( $_GET['tap_start_date'] ) : '';
$end_date_raw   = isset( $_GET['tap_end_date'] )   ? sanitize_text_field( $_GET['tap_end_date'] )   : '';

$start_date = ( $start_date_raw && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date_raw ) )
    ? DateTime::createFromFormat( 'Y-m-d', $start_date_raw )
    : false;

$end_date = ( $end_date_raw && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date_raw ) )
    ? DateTime::createFromFormat( 'Y-m-d', $end_date_raw )
    : false;

$date_query = [];
if ( $start_date || $end_date ) {
    $range = [
        'column'    => 'post_date',
        'inclusive' => false,
    ];
    if ( $start_date ) {
        $range['after'] = $start_date->format( 'Y-m-d' );
    }
    if ( $end_date ) {
        $end_plus_one   = clone $end_date;
        $end_plus_one->modify( '+1 day' );
        $range['before'] = $end_plus_one->format( 'Y-m-d' );
    }
    $date_query[] = $range;
}

/* ===== Käsittele massatoiminnot (kutsutaan kerran) ===== */
datahanlder_tilausnakyma_handle_bulk_actions( $tyomaa_koodi );

/* ===== Tilauslistaus ===== */
if ( $tab === 'new' ) {
    $statuses = [ 'pending', 'on-hold' ];
} elseif ( $tab === 'approved' ) {
    $statuses = [ 'wc-manager-approved' ];
} else { // archived
    $statuses = [ 'wc-archived' ];
}

$meta_query = ( $tab === 'new' )
    ? [
        [
            'key'     => 'tyomaa_koodi',
            'value'   => $tyomaa_koodi,
            'compare' => '=',
        ],
    ]
    : [
        'relation' => 'AND',
        [
            'key'     => 'tyomaa_koodi',
            'value'   => $tyomaa_koodi,
            'compare' => '=',
        ],
        [
            'key'     => '_manager_approved',
            'value'   => [ '1', 'approved' ],
            'compare' => 'IN',
        ],
    ];

$args = [
    'limit'      => -1,
    'status'     => $statuses,
    'meta_query' => $meta_query,
];

if ( $tab === 'archived' ) {
    $args['date_query'] = $date_query;
}

$orders = wc_get_orders( $args );

/* Tyhjäviesti (vain esimerkki yhdelle tapille) */
$empty_msg = '';
if ( empty( $orders ) && $tab === 'new' ) {
    $empty_msg = sprintf(
    esc_html__( 'Ei uusia tilauksia työmaaltasi (%s).', 'tapojarvi' ),
    $tyomaa_koodi
);
}

/* Laske määrät välilehden labeleihin */
$uudet_tilaukset = wc_get_orders( [
    'status'     => [ 'pending', 'on-hold' ],
    'meta_key'   => 'tyomaa_koodi',
    'meta_value' => $tyomaa_koodi,
    'limit'      => -1,
    'return'     => 'ids',
] );
$uudet_tilaukset_maara = count( $uudet_tilaukset );

$hyvaksytyt_tilaukset = wc_get_orders( [
    'status'     => [ 'wc-manager-approved' ],
    'meta_query' => [
        'relation' => 'AND',
        [ 'key' => 'tyomaa_koodi',      'value' => $tyomaa_koodi, 'compare' => '=' ],
        [ 'key' => '_manager_approved', 'value' => '1',           'compare' => '=', 'type' => 'CHAR' ],
    ],
    'limit'      => -1,
    'return'     => 'ids',
] );
$hyvaksytyt_tilaukset_maara = count( $hyvaksytyt_tilaukset );

/* Lataa varsinainen näkymä */
tilausnakyma_include_view(
    __DIR__ . '/../views/tilausnakyma-view.php',
    [
        'orders'                     => $orders,
        'tab'                        => $tab,
        'tyomaa_koodi'               => $tyomaa_koodi,
        'empty_msg'                  => $empty_msg,
        'uudet_tilaukset_maara'      => $uudet_tilaukset_maara,
        'hyvaksytyt_tilaukset_maara' => $hyvaksytyt_tilaukset_maara,
    ]
);