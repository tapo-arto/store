<?php
if ( ! defined('ABSPATH') ) exit;

function tapojarvi_send_reminders() {
    // Estetään kaksoissuoritus
    if ( defined('TAPOJARVI_REMINDER_RUNNING') && TAPOJARVI_REMINDER_RUNNING ) return;
    define('TAPOJARVI_REMINDER_RUNNING', true);

    error_log('[tapojarvi] Käynnistetään muistutuslähetys...');

    $test_mode   = get_option('tapojarvi_reminder_test_mode');
    $test_emails = array_map('trim', explode(',', get_option('tapojarvi_reminder_test_emails')));

    // Hae tilausten hallitsijat
    $users = get_users([
        'role__in' => ['shop_manager', 'administrator', 'tilausten_hallitsija']
    ]);
    error_log('[tapojarvi] Tilausten hallitsijoita löytyi: ' . count($users));
error_log('[tapojarvi] Ajoaika: ' . current_time('mysql'));
    foreach ( $users as $user ) {
        $code = get_user_meta( $user->ID, 'tyomaa_koodi', true );
        error_log('[tapojarvi] Käyttäjä: ' . $user->user_email . ' | Työmaakoodi: ' . $code);

        if ( ! $code ) {
            error_log('[tapojarvi] → Ohitetaan, koska työmaakoodi puuttuu.');
            continue;
        }

        $orders = wc_get_orders([
            'status'     => 'pending',
            'return'     => 'ids',
            'limit'      => -1,
            'meta_query' => [
                [
                    'key'     => 'tyomaa_koodi',
                    'value'   => $code,
                    'compare' => '='
                ]
            ]
        ]);

        $count = count($orders);
        error_log('[tapojarvi] → Tilauksia työmaakoodilla "' . $code . '": ' . $count);

        if ( $count < 1 ) {
            error_log('[tapojarvi] → Ei hyväksymättömiä tilauksia, ohitetaan.');
            continue;
        }

        // Jos testimoodi päällä, ohita kaikki muut kuin testisähköpostit
        if ( $test_mode && ! in_array($user->user_email, $test_emails) ) {
            error_log('[tapojarvi] → Testimoodi päällä, ei lähetetä: ' . $user->user_email);
            continue;
        }

        $emails = WC()->mailer()->get_emails();
        if ( isset($emails['WC_Email_Order_Reminder']) ) {
            $emails['WC_Email_Order_Reminder']->trigger( $user, $count, $code );
            error_log('[tapojarvi] → Sähköposti lähetetty käyttäjälle: ' . $user->user_email);
        } else {
            error_log('[tapojarvi] ⚠ WC_Email_Order_Reminder ei ole käytettävissä.');
        }
    }
}