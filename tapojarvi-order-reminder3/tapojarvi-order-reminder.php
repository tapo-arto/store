<?php
/**
 * Plugin Name: Tapojärvi - Order Reminder
 * Description: Lähettää muistutuksia/tilausmuistutuksia (cron tms.)
 * Version:     1.0.0
 * Author:      Tapojärvi Oy
 * License:     GPLv3 or later
 * Text Domain: tapojarvi-order-reminder
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Lataa käännökset /languages -kansiosta */
add_action( 'plugins_loaded', function () {
	load_plugin_textdomain(
		'tapojarvi-order-reminder',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
} );

// 1) Lataa funktiot
require_once plugin_dir_path(__FILE__) . 'includes/functions.php';

// 2) Asetusten rekisteröinti
add_action('admin_init', 'tapojarvi_reminder_register_settings');
function tapojarvi_reminder_register_settings() {
    register_setting('tapojarvi_reminder_settings', 'tapojarvi_reminder_test_mode');
    register_setting('tapojarvi_reminder_settings', 'tapojarvi_reminder_test_emails');
    register_setting('tapojarvi_reminder_settings', 'tapojarvi_reminder_send_day');
    register_setting('tapojarvi_reminder_settings', 'tapojarvi_reminder_send_time');
}

// 3) WooCommerce email-luokan rekisteröinti
add_filter('woocommerce_email_classes', 'tapojarvi_register_email');
function tapojarvi_register_email( $emails ) {
    require_once plugin_dir_path(__FILE__) . 'includes/emails/class-wc-email-order-reminder.php';
    $emails['WC_Email_Order_Reminder'] = new WC_Email_Order_Reminder();
    return $emails;
}

// 4) Cron-aikataulu (tehdään näkyvä teksti käännettäväksi)
add_filter('cron_schedules', 'tapojarvi_custom_cron');
function tapojarvi_custom_cron( $schedules ) {
    $schedules['weekly'] = [
        'interval' => 7 * DAY_IN_SECONDS,
        'display'  => __( 'Joka viikko', 'tapojarvi-order-reminder' ),
    ];
    return $schedules;
}

function tapojarvi_reminder_activate() {
    wp_clear_scheduled_hook('tapojarvi_send_reminders');

    $day  = get_option('tapojarvi_reminder_send_day', 'monday');
    $time = get_option('tapojarvi_reminder_send_time', '08:00');
    $next = strtotime("next $day $time");

    if ( $next < time() ) {
        $next = strtotime("+1 week $day $time");
    }

    wp_schedule_event( $next, 'weekly', 'tapojarvi_send_reminders' );
}
register_activation_hook( __FILE__, 'tapojarvi_reminder_activate' );

// 5) Cronin uudelleenajo asetuksien muutoksessa
add_action('update_option_tapojarvi_reminder_send_day', 'tapojarvi_reminder_reschedule');
add_action('update_option_tapojarvi_reminder_send_time', 'tapojarvi_reminder_reschedule');
function tapojarvi_reminder_reschedule() {
    wp_clear_scheduled_hook('tapojarvi_send_reminders');
    tapojarvi_reminder_activate();
}

register_deactivation_hook( __FILE__, 'tapojarvi_reminder_deactivate' );
function tapojarvi_reminder_deactivate() {
    wp_clear_scheduled_hook('tapojarvi_send_reminders');
}

// 6) Muistutuslähetyksen hook
add_action('tapojarvi_send_reminders', 'tapojarvi_send_reminders');

// 7) Admin-sivu
add_action( 'admin_menu', 'tapojarvi_reminder_admin_menu' );
function tapojarvi_reminder_admin_menu() {
    add_submenu_page(
        'woocommerce',
        __( 'Tilausmuistutus', 'tapojarvi-order-reminder' ), // page_title
        __( 'Tilausmuistutus', 'tapojarvi-order-reminder' ), // menu_title
        'manage_woocommerce',
        'tapojarvi-reminder-settings',
        'tapojarvi_reminder_settings_page'
    );
}

function tapojarvi_reminder_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Tapojärvi – Tilausmuistutus', 'tapojarvi-order-reminder'); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('tapojarvi_reminder_settings');
            do_settings_sections('tapojarvi_reminder_settings');
            ?>
            <table class="form-table" role="presentation">
                <tr valign="top">
                    <th scope="row"><?php esc_html_e( 'Testimoodi', 'tapojarvi-order-reminder' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="tapojarvi_reminder_test_mode" value="1" <?php checked(1, get_option('tapojarvi_reminder_test_mode'), true); ?> />
                            <?php esc_html_e( 'Lähetä muistutukset vain testiosoitteisiin', 'tapojarvi-order-reminder' ); ?>
                        </label>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php esc_html_e( 'Testisähköpostit', 'tapojarvi-order-reminder' ); ?></th>
                    <td>
                        <input type="text" name="tapojarvi_reminder_test_emails" value="<?php echo esc_attr(get_option('tapojarvi_reminder_test_emails')); ?>" class="regular-text" />
                        <p class="description">
                            <?php esc_html_e( 'Erottele sähköpostiosoitteet pilkuilla, esim: test@example.com, toinen@example.com', 'tapojarvi-order-reminder' ); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php esc_html_e( 'Lähetyspäivä', 'tapojarvi-order-reminder' ); ?></th>
                    <td>
                        <select name="tapojarvi_reminder_send_day">
                            <?php
                            $days = [
                                'monday'    => __( 'Maanantai',   'tapojarvi-order-reminder' ),
                                'tuesday'   => __( 'Tiistai',     'tapojarvi-order-reminder' ),
                                'wednesday' => __( 'Keskiviikko', 'tapojarvi-order-reminder' ),
                                'thursday'  => __( 'Torstai',     'tapojarvi-order-reminder' ),
                                'friday'    => __( 'Perjantai',   'tapojarvi-order-reminder' ),
                            ];
                            $current_day = get_option('tapojarvi_reminder_send_day');
                            foreach ( $days as $key => $label ) {
                                printf('<option value="%s"%s>%s</option>',
                                    esc_attr($key),
                                    selected($key, $current_day, false),
                                    esc_html($label)
                                );
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php esc_html_e( 'Lähetysaika (24h)', 'tapojarvi-order-reminder' ); ?></th>
                    <td>
                        <input type="time" name="tapojarvi_reminder_send_time" value="<?php echo esc_attr( get_option('tapojarvi_reminder_send_time', '08:00') ); ?>" />
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <hr>
        <h2><?php esc_html_e('Testaa muistutusta', 'tapojarvi-order-reminder'); ?></h2>
        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
            <?php wp_nonce_field( 'tapojarvi_reminder_test', 'tapojarvi_reminder_test_nonce' ); ?>
            <input type="hidden" name="action" value="tapojarvi_reminder_test">
            <?php submit_button( __( 'Lähetä testimuistutus', 'tapojarvi-order-reminder' ) ); ?>
        </form>
        <?php if ( isset( $_GET['tested'] ) ): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e('Testimuistutus lähetetty (testitilassa).', 'tapojarvi-order-reminder'); ?></p>
            </div>
        <?php endif; ?>
    </div>
    <?php
    // Ajetaan ajastus korjauksena kerran
    tapojarvi_reminder_fix_schedule();
}

// 8) Cron-ajastuksen korjaus
function tapojarvi_reminder_fix_schedule() {
    $hook = 'tapojarvi_send_reminders';

    while ( $timestamp = wp_next_scheduled( $hook ) ) {
        wp_unschedule_event( $timestamp, $hook );
    }

    $day  = get_option('tapojarvi_reminder_send_day', 'monday');
    $time = get_option('tapojarvi_reminder_send_time', '08:00');
    $next = strtotime("next $day $time");
    if ( $next < time() ) {
        $next = strtotime("+1 week $day $time");
    }

    wp_schedule_event( $next, 'weekly', $hook );
}

// 9) Testipainikkeen käsittely
add_action( 'admin_post_tapojarvi_reminder_test', 'tapojarvi_reminder_handle_test' );
function tapojarvi_reminder_handle_test() {
    if ( ! current_user_can('manage_woocommerce') || ! check_admin_referer('tapojarvi_reminder_test','tapojarvi_reminder_test_nonce') ) {
        wp_die( esc_html__( 'Ei oikeuksia tai virheellinen pyyntö.', 'tapojarvi-order-reminder' ) );
    }
    tapojarvi_send_reminders();
    wp_safe_redirect( add_query_arg( 'tested', '1', wp_get_referer() ) );
    exit;
}