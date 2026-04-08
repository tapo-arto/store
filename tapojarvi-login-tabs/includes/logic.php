<?php
/**
 * Plugin Name: Tapojärvi Login Tabs Logic (optimoitu)
 * Description: Tapojärven automaattinen käyttäjäluonti ja asetukset Magic Login Pron kanssa.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1. Varmistetaan, että lomake on lähetetty ennen kuin tarkistetaan sähköpostia
add_action( 'init', 'tlt_auto_create_employee_user', 9 );
function tlt_auto_create_employee_user() {
    // Varmistetaan, että tämä toimii vain etusivulla eikä muilla sivuilla
    if ( is_admin() || is_user_logged_in() ) {
        return;
    }

    // Tarkistetaan, että lomakekenttä ei ole tyhjä
    if ( isset( $_POST['log'] ) && empty( $_POST['log'] ) ) {
        // Jos kenttä on tyhjä, estetään kirjautumisen prosessi ja ohjataan virheelliseen URL:iin
        if ( ! isset( $_GET['login'] ) || $_GET['login'] !== 'empty' ) {
            if ( ! defined( 'TAPO_LOGIN_SLUG' ) ) define( 'TAPO_LOGIN_SLUG', '/kirjaudu/' );
        wp_redirect( add_query_arg( 'login', 'empty', home_url( TAPO_LOGIN_SLUG ) ) );
            exit;
        }
    }

    // Vain validi sähköposti jatkaa eteenpäin
    $email = isset( $_POST['log'] ) ? trim( $_POST['log'] ) : ''; // Varmistetaan, ettei ole null
    if ( empty( $email ) || ! is_email( $email ) ) {
        return; // Jos sähköposti on tyhjä tai ei ole validi, lopetetaan
    }

    // Sallitut domainit
    $allowed_domains = array( 'tapojarvi.fi', 'tapojarvi.com', 'hannukainenmining.fi' );
    $email_domain = substr( strrchr( $email, "@" ), 1 );

    if ( ! in_array( $email_domain, $allowed_domains ) ) {
        return; // Jos domain ei ole sallittu, ei luoda käyttäjää
    }

    if ( email_exists( $email ) ) return; // Jos sähköposti löytyy, ei luoda uutta käyttäjää

    // Jos sähköposti on validi ja domain sallittu, voidaan tehdä käyttäjän luonti
    // Käyttäjän luonti tai muu logiikka tässä

    // Vain käyttäjän kirjautuminen, ei luonti
    return;
}

// 2. Lähettäjän nimi sähköpostissa
add_filter( 'wp_mail_from_name', function( $name ) {
    return 'Tapojärvi Store';
} );
