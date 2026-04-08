<?php if ( ! defined('ABSPATH') ) exit; ?>

<?php
// Tervehdys
printf(
    /* translators: %s = recipient display name */
    esc_html__( 'Hello %s,', 'tapojarvi-order-reminder' ),
    esc_html( $user->display_name )
);
echo "\n\n";

// Monikko riville "You have X pending order(s)..."
printf(
    /* translators: %d = number of pending orders */
    _n(
        'You have %d pending order awaiting approval.',
        'You have %d pending orders awaiting approval.',
        (int) $count,
        'tapojarvi-order-reminder'
    ),
    (int) $count
);
echo "\n\n";

// Kirjautumislinkki
printf(
    /* translators: %s = login URL */
    esc_html__( 'Log in: %s', 'tapojarvi-order-reminder' ),
    esc_url( $login_url )
);
echo "\n";