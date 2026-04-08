<?php
/**
 * Plugin Name: Tapojärvi – Checkout Labels (FI/EN)
 * Description: Siisti hallintanäkymä, jossa näet WooCommercen checkout-kentät listana ja voit määrittää labelit sekä placeholderit suomeksi (FI) ja englanniksi (EN). Yliajaa teeman/Checkout Field Editorin labelit kielikohtaisesti.
 * Version:     1.1.0
 * Author:      Tapojärvi Oy
 * License:     GPLv2 or later
 * Text Domain: tap-checkout-labels
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class Tap_Checkout_Labels {
    const OPT_KEY = 'tap_checkout_labels_options';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_init', array( $this, 'handle_form_save' ) );
        add_filter( 'woocommerce_checkout_fields', array( $this, 'apply_labels_for_language' ), 999 );
    }

    private function current_lang_slug() {
        if ( function_exists( 'pll_current_language' ) ) {
            $slug = pll_current_language( 'slug' );
            if ( $slug ) return strtolower( $slug ); // 'fi' / 'en'
        }
        $loc = get_locale(); // esim. fi_FI / en_US
        return strtolower( substr( $loc, 0, 2 ) );
    }

    public function admin_menu() {
        $cap = current_user_can('manage_woocommerce') ? 'manage_woocommerce' : 'manage_options';
        add_submenu_page(
            'woocommerce',
            __( 'Checkout Labels (FI/EN)', 'tap-checkout-labels' ),
            __( 'Checkout Labels (FI/EN)', 'tap-checkout-labels' ),
            $cap,
            'tap-checkout-labels',
            array( $this, 'render_admin_page' )
        );
    }

    public function handle_form_save() {
        if ( ! isset( $_POST['tap_checkout_labels_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['tap_checkout_labels_nonce'], 'tap_checkout_labels_save' ) ) return;
        if ( ! current_user_can('manage_woocommerce') && ! current_user_can('manage_options') ) return;

        $data = isset($_POST['tap_labels']) ? $_POST['tap_labels'] : array();
        $clean = array();
        if ( is_array( $data ) ) {
            foreach ( $data as $key => $props ) {
                $key_s = sanitize_key( $key );
                $clean[$key_s] = array( 'label'=>array(), 'placeholder'=>array() );
                foreach ( array('label','placeholder') as $prop ) {
                    if ( isset($props[$prop]) && is_array($props[$prop]) ) {
                        $fi = isset($props[$prop]['fi']) ? wp_kses_post( $props[$prop]['fi'] ) : '';
                        $en = isset($props[$prop]['en']) ? wp_kses_post( $props[$prop]['en'] ) : '';
                        $clean[$key_s][$prop]['fi'] = $fi;
                        $clean[$key_s][$prop]['en'] = $en;
                    } else {
                        $clean[$key_s][$prop]['fi'] = '';
                        $clean[$key_s][$prop]['en'] = '';
                    }
                }
            }
        }
        update_option( self::OPT_KEY, $clean );
        add_action('admin_notices', function(){
            echo '<div class="notice notice-success is-dismissible"><p>'.esc_html__('Checkout labels saved.', 'tap-checkout-labels').'</p></div>';
        });
    }

    public function apply_labels_for_language( $fields ) {
        $opts = get_option( self::OPT_KEY, array() );
        if ( ! is_array( $opts ) || empty( $opts ) ) return $fields;

        $lang = $this->current_lang_slug();
        $lang = in_array($lang, array('fi','en')) ? $lang : ( $lang ? $lang : 'en' );

        foreach ( $fields as $section => &$sec_fields ) {
            foreach ( $sec_fields as $key => &$field ) {
                if ( isset($opts[$key]) ) {
                    if ( ! empty( $opts[$key]['label'][$lang] ) ) {
                        $field['label'] = $opts[$key]['label'][$lang];
                    }
                    if ( ! empty( $opts[$key]['placeholder'][$lang] ) ) {
                        $field['placeholder'] = $opts[$key]['placeholder'][$lang];
                    }
                }
            }
        }
        return $fields;
    }

    private function get_current_checkout_fields() {
        if ( ! class_exists( 'WooCommerce' ) ) return array();
        if ( function_exists('WC') && WC() ) {
            try {
                $checkout = WC()->checkout();
                if ( $checkout ) {
                    $fields = $checkout->get_checkout_fields(); // mukana myös Checkout Field Editorin muutokset
                    if ( is_array( $fields ) ) return $fields;
                }
            } catch ( Throwable $e ) {}
        }
        return array( 'billing'=>array(), 'shipping'=>array(), 'order'=>array(), 'account'=>array(), 'custom'=>array() );
    }

    public function render_admin_page() {
        if ( ! current_user_can('manage_woocommerce') && ! current_user_can('manage_options') ) return;
        $fields = $this->get_current_checkout_fields();
        $saved  = get_option( self::OPT_KEY, array() );

        // ——— UI-tyylit: responsiivisuus & luettavuus ———
        echo '<style>
        #tapcl-page .tapcl-toolbar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:12px 0 16px}
        #tapcl-page .tapcl-toolbar select{min-width:200px}
        #tapcl-page .tapcl-table-wrap{max-width:100%;overflow-x:auto;border:1px solid #ccd0d4;border-radius:6px;background:#fff}
        #tapcl-page table.tapcl-table{min-width:1100px;table-layout:fixed;border-collapse:separate;border-spacing:0}
        #tapcl-page table.tapcl-table thead th{position:sticky;top:0;background:#f6f7f7;z-index:2;box-shadow:inset 0 -1px 0 #ccd0d4}
        #tapcl-page table.tapcl-table th, #tapcl-page table.tapcl-table td{vertical-align:top;word-wrap:break-word}
        #tapcl-page table.tapcl-table th:nth-child(1){width:110px}
        #tapcl-page table.tapcl-table th:nth-child(2){width:190px}
        #tapcl-page table.tapcl-table th:nth-child(3){width:210px}
        #tapcl-page table.tapcl-table th:nth-child(4),
        #tapcl-page table.tapcl-table th:nth-child(5),
        #tapcl-page table.tapcl-table th:nth-child(7),
        #tapcl-page table.tapcl-table th:nth-child(8){width:220px}
        #tapcl-page table.tapcl-table th:nth-child(6){width:210px}
        #tapcl-page .regular-text{width:100%;max-width:none}
        #tapcl-page code{white-space:pre-wrap}
        </style>';

        echo '<div id="tapcl-page" class="wrap">';
        echo '<h1>'.esc_html__('Checkout Labels (FI/EN)', 'tap-checkout-labels').'</h1>';
        echo '<p>'.esc_html__('Edit labels and placeholders for checkout fields per language. Saved values override theme/Checkout Field Editor labels.', 'tap-checkout-labels').'</p>';

        // Suodatin osioittain (helpottaa pitkiä listoja)
        echo '<div class="tapcl-toolbar">';
        echo '<label for="tapcl-section-filter"><strong>'.esc_html__('Filter by section:', 'tap-checkout-labels').'</strong></label> ';
        echo '<select id="tapcl-section-filter">';
        $sections = array('all'=>'All','billing'=>'Billing','shipping'=>'Shipping','order'=>'Order','account'=>'Account','custom'=>'Custom');
        foreach($sections as $val=>$label){
            echo '<option value="'.esc_attr($val).'">'.esc_html__($label, 'tap-checkout-labels').'</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<form method="post">';
        wp_nonce_field( 'tap_checkout_labels_save', 'tap_checkout_labels_nonce' );

        echo '<div class="tapcl-table-wrap">';
        echo '<table class="widefat striped tapcl-table">';
        echo '<thead><tr>';
        echo '<th>'.esc_html__('Section', 'tap-checkout-labels').'</th>';
        echo '<th>'.esc_html__('Field key', 'tap-checkout-labels').'</th>';
        echo '<th>'.esc_html__('Current label', 'tap-checkout-labels').'</th>';
        echo '<th>'.esc_html__('FI label', 'tap-checkout-labels').'</th>';
        echo '<th>'.esc_html__('EN label', 'tap-checkout-labels').'</th>';
        echo '<th>'.esc_html__('Current placeholder', 'tap-checkout-labels').'</th>';
        echo '<th>'.esc_html__('FI placeholder', 'tap-checkout-labels').'</th>';
        echo '<th>'.esc_html__('EN placeholder', 'tap-checkout-labels').'</th>';
        echo '</tr></thead><tbody>';

        $order = array('billing','shipping','order','account','custom');
        $sec_names = array(
            'billing'  => __('Billing','tap-checkout-labels'),
            'shipping' => __('Shipping','tap-checkout-labels'),
            'order'    => __('Order','tap-checkout-labels'),
            'account'  => __('Account','tap-checkout-labels'),
            'custom'   => __('Custom','tap-checkout-labels'),
        );

        foreach ( $order as $sec ) {
            if ( empty( $fields[$sec] ) || ! is_array( $fields[$sec] ) ) continue;
            foreach ( $fields[$sec] as $key => $f ) {
                $current_label = isset($f['label']) ? $f['label'] : '';
                $current_ph    = isset($f['placeholder']) ? $f['placeholder'] : '';

                $fi_label = isset($saved[$key]['label']['fi']) ? $saved[$key]['label']['fi'] : '';
                $en_label = isset($saved[$key]['label']['en']) ? $saved[$key]['label']['en'] : '';
                $fi_ph    = isset($saved[$key]['placeholder']['fi']) ? $saved[$key]['placeholder']['fi'] : '';
                $en_ph    = isset($saved[$key]['placeholder']['en']) ? $saved[$key]['placeholder']['en'] : '';

                echo '<tr data-section="'.esc_attr($sec).'">';
                echo '<td>'.esc_html( $sec_names[$sec] ).'</td>';
                echo '<td><code>'.esc_html( $key ).'</code></td>';
                echo '<td>'.esc_html( $current_label ).'</td>';
                echo '<td><input type="text" class="regular-text" name="tap_labels['.esc_attr($key).'][label][fi]" value="'.esc_attr($fi_label).'" /></td>';
                echo '<td><input type="text" class="regular-text" name="tap_labels['.esc_attr($key).'][label][en]" value="'.esc_attr($en_label).'" /></td>';
                echo '<td>'.esc_html( $current_ph ).'</td>';
                echo '<td><input type="text" class="regular-text" name="tap_labels['.esc_attr($key).'][placeholder][fi]" value="'.esc_attr($fi_ph).'" /></td>';
                echo '<td><input type="text" class="regular-text" name="tap_labels['.esc_attr($key).'][placeholder][en]" value="'.esc_attr($en_ph).'" /></td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table></div>';
        echo '<p><em>'.esc_html__('Tip: The table shows your current labels from theme/plugins. Fill in the FI/EN columns to override them per language. Leave blank to keep default.', 'tap-checkout-labels').'</em></p>';
        submit_button( __('Save labels', 'tap-checkout-labels') );
        echo '</form>';

        // Pieni JS: osiosuodatin
        echo '<script>
        (function(){
          const sel = document.getElementById("tapcl-section-filter");
          const rows = document.querySelectorAll("#tapcl-page tr[data-section]");
          function applyFilter(){
            const v = sel.value;
            rows.forEach(tr => {
              const sec = tr.getAttribute("data-section");
              tr.style.display = (v==="all" || v===sec) ? "" : "none";
            });
          }
          if(sel){ sel.addEventListener("change", applyFilter); }
        })();
        </script>';

        echo '</div>'; // #tapcl-page
    }
}

new Tap_Checkout_Labels();