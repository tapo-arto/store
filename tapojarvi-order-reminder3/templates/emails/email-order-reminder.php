<?php if ( ! defined('ABSPATH') ) exit; ?>

<?php
$heading = $email_heading ?? __( 'Order reminder', 'tapojarvi-order-reminder' );
echo $heading . "\n\n";

printf(
  /* translators: %s = recipient display name */
  __( 'Hello, %s!', 'tapojarvi-order-reminder' ),
  $user->display_name ?? ''
);
echo "\n\n";

printf(
  /* translators: 1: number of pending orders, 2: worksite code */
  _n(
    'You have %1$d pending order to approve for worksite: %2$s',
    'You have %1$d pending orders to approve for worksite: %2$s',
    (int) $count,
    'tapojarvi-order-reminder'
  ),
  (int) $count,
  $tyomaa_koodi
);
echo "\n\n";

printf( __( 'Log in: %s', 'tapojarvi-order-reminder' ), $login_url );
echo "\n\n";

_e( 'If you have any questions or need assistance, please contact Tapojärvi communications.', 'tapojarvi-order-reminder' ); echo "\n";
_e( 'This is an automated message from the webshop. Please do not reply to this message.', 'tapojarvi-order-reminder' ); echo "\n\n";

_e( 'If your worksite has only one or two orders during the week, you may wait until the next week before approving them. This helps avoid unnecessary small shipments because orders approved by the manager are sent directly to suppliers. Placing larger batches improves logistics efficiency and saves resources.', 'tapojarvi-order-reminder' ); echo "\n";

echo "\n© " . date('Y') . " Tapojärvi Oy\n";