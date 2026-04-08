<?php
add_action( 'wp_enqueue_scripts', function () {

    /* CSS */
    wp_enqueue_style(
        'tlt-login-tabs-css',
        plugin_dir_url( __DIR__ ) . 'assets/login-tabs.css',
        [],
        filemtime( plugin_dir_path( __DIR__ ) . 'assets/login-tabs.css' )
    );

    /* JS – ei riippuvuutta jQueryyn */
    $handle = 'tlt-login-tabs-js';

    wp_enqueue_script(
        $handle,
        plugin_dir_url( __DIR__ ) . 'assets/login-tabs.js',
        [],
        filemtime( plugin_dir_path( __DIR__ ) . 'assets/login-tabs.js' ),
        true // footer
    );

    // Julkaise nykyinen kielisluug fronttiin (fi/en/…)
    $curr_lang = function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : '';
    wp_add_inline_script(
        $handle,
        'window.TLT_LANG = ' . wp_json_encode( $curr_lang ) . ';',
        'before'
    );

    // Lokalisoidut merkkijonot JS:lle
    wp_localize_script( $handle, 'TLT_i18n', [
        'domainError' => __( 'Please use a @tapojarvi.fi or @tapojarvi.com address.', 'tapojarvi-login-tabs' ),
    ] );
});