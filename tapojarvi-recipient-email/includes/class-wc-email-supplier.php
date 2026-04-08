<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WC_Email_Supplier
 *
 * Lähettää toimittajille korttilayoutilla varustetun ilmoitussähköpostin.
 * Templatena käytetään /views/email-supplier.php -tiedostoa.
 */
class WC_Email_Supplier extends WC_Email {

    /**
     * Säilyttää tilausrivit templaten käyttöä varten
     * @var WC_Order_Item_Product[]
     */
    protected $line_items = array();

    public function __construct() {
        $this->id             = 'supplier_email';
        $this->title          = __( 'Supplier Notification', 'tapojarvi-product-recipient-email' );
        $this->description    = __( 'Sends a notification email to each supplier with styled card layout.', 'tapojarvi-product-recipient-email' );

        // Käytä pluginin views-kansion HTML-templaten tiedostopolkua
        $this->template_html  = dirname( __DIR__ ) . '/views/email-supplier.php';
        $this->template_plain = '';

        $this->enabled        = 'yes';
        $this->recipient      = '';
        $this->email_type     = 'html'; // varmista HTML-tyyppi

        parent::__construct();
    }

    /**
     * Trigger – kutsutaan tilauksen yhteydessä
     *
     * @param int                     $order_id
     * @param string                  $recipient_email
     * @param WC_Order_Item_Product[] $line_items
     */
    public function trigger( $order_id, $recipient_email, $line_items = array() ) {
    if ( ! $this->is_enabled() ) {
        return false;
    }

    if ( empty( $recipient_email ) || ! is_email( $recipient_email ) ) {
        return false;
    }

    $order = wc_get_order( $order_id );

    if ( ! $order ) {
        return false;
    }

    // Estä toimittajaviestit, jos kyseessä on työmaan yhteistilaus (preorder)
    if ( $order->get_meta( '_worksite_preorder' ) === 'yes' ) {
        return false;
    }

    $this->object     = $order;
    $this->recipient  = $recipient_email;
    $this->line_items = $line_items;

    $mailer  = WC()->mailer();
    $message = $this->get_content_html();

    $subject = sprintf(
        __( 'Tilaus #%s', 'tapojarvi-product-recipient-email' ),
        $order->get_order_number()
    );

    $sent = $mailer->send(
        $this->get_recipient(),
        $subject,
        $message,
        $this->get_headers(),
        $this->get_attachments()
    );

    $logger  = wc_get_logger();
    $context = array( 'source' => 'supplier-email' );

    if ( $sent ) {
        $logger->info(
            sprintf(
                __( 'Supplier email sent to %1$s for order %2$s', 'tapojarvi-product-recipient-email' ),
                $this->recipient,
                $order_id
            ),
            $context
        );
    } else {
        $logger->error(
            sprintf(
                __( 'Supplier email FAILED to %1$s for order %2$s', 'tapojarvi-product-recipient-email' ),
                $this->recipient,
                $order_id
            ),
            $context
        );
    }

    return (bool) $sent;
}

    /**
     * Palauttaa HTML-sisällön (sis. templaten ajon)
     */
    public function get_content_html() {
        if ( ! file_exists( $this->template_html ) ) {
            return '';
        }

        ob_start();

        /** @var WC_Order $order */
        $order = $this->object;
        $items = $this->line_items;

        // 🔗 Hae Worksite-lisäosan tuottama laskutuslohko valmiina HTML:nä
        $extra_html = apply_filters( 'tapojarvi_supplier_email_extra', '', $order );

        include $this->template_html;

        return ob_get_clean();
    }

    /**
     * Ei käytössä (vain HTML)
     */
    public function get_content_plain() {
        return '';
    }
}