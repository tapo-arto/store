<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Email_Order_Reminder extends WC_Email {

    public function __construct() {
        $this->id             = 'tapojarvi_order_reminder';

        // Näkyy WooCommercen Sähköpostit-listassa
        $this->title          = __( 'Order Reminder', 'tapojarvi-order-reminder' );
        $this->description    = __( 'Reminds order managers about unapproved (pending) orders once a week.', 'tapojarvi-order-reminder' );

        // Mallit (HTML & plain)
        $this->template_html  = 'emails/email-order-reminder.php';
        $this->template_plain = 'emails/plain/email-order-reminder.php';

        // (Valinnainen) otsikko/subjectin oletukset asetuksissa
        $this->heading = __( 'Order reminder', 'tapojarvi-order-reminder' );
        $this->subject = __( 'You have pending orders to review', 'tapojarvi-order-reminder' );

        // Paikkamerkit jos haluat käyttää {count}, {worksite_code} yms. templateissa
        $this->placeholders = array(
            '{site_title}'    => $this->get_blogname(),
            '{count}'         => '',
            '{worksite_code}' => '',
        );

        parent::__construct();
    }

    /**
     * Käynnistä lähetys tietylle käyttäjälle
     *
     * @param WP_User $user
     * @param int     $count          Kuinka monta pending-tilausta
     * @param string  $tyomaa_koodi   Work-site code
     */
    public function trigger( $user, $count, $tyomaa_koodi ) {
        if ( ! $user || ! $user instanceof WP_User || $count < 1 ) {
            return;
        }

        // Vastaanottaja: käytetään käyttäjän omaa sähköpostia
        // (Testimoodi on jo suodatettu functions.php:ssä -> ei yliajeta tähän mitään.)
        $this->recipient = $user->user_email;

        // Talleta olio templateja varten
        $this->object = (object) array(
            'user'         => $user,
            'count'        => (int) $count,
            'tyomaa_koodi' => (string) $tyomaa_koodi,
            'login_url'    => wc_get_page_permalink( 'myaccount' ),
        );

        // Päivitä paikkamerkit (jos käytössä)
        $this->placeholders['{count}']         = (string) $this->object->count;
        $this->placeholders['{worksite_code}'] = (string) $this->object->tyomaa_koodi;

        // Lähetä
        $this->send(
            $this->get_recipient(),
            $this->get_subject(),
            $this->get_content(),
            $this->get_headers(),
            array()
        );
    }

    /**
     * Sähköpostin subject – lokalisoitu ja monikkomuoto
     */
    public function get_subject() {
        if ( empty( $this->object ) ) {
            return $this->subject;
        }

        // Translators: %1$d = count, %2$s = worksite code
        $fmt = _n(
            'You have %1$d pending order for worksite: %2$s',
            'You have %1$d pending orders for worksite: %2$s',
            $this->object->count,
            'tapojarvi-order-reminder'
        );

        return sprintf( $fmt, $this->object->count, $this->object->tyomaa_koodi );
    }

    /**
     * Sähköpostin "heading" (näkyy HTML-mallissa jos käytät sitä)
     */
    public function get_heading() {
        if ( empty( $this->object ) ) {
            return $this->heading;
        }

        // Translators: %d = count
        return sprintf(
            _n(
                'You have %d pending order',
                'You have %d pending orders',
                $this->object->count,
                'tapojarvi-order-reminder'
            ),
            $this->object->count
        );
    }

    public function get_content_html() {
        return wc_get_template_html(
            $this->template_html,
            array(
                'email_heading' => $this->get_heading(),
                'user'          => $this->object->user,
                'count'         => $this->object->count,
                'tyomaa_koodi'  => $this->object->tyomaa_koodi,
                'login_url'     => $this->object->login_url,
                'email'         => $this,
            ),
            '',
            // plugin root + /templates/
            plugin_dir_path( __FILE__ ) . '../../templates/'
        );
    }

    public function get_content_plain() {
        return wc_get_template_html(
            $this->template_plain,
            array(
                'email_heading' => $this->get_heading(),
                'user'          => $this->object->user,
                'count'         => $this->object->count,
                'tyomaa_koodi'  => $this->object->tyomaa_koodi,
                'login_url'     => $this->object->login_url,
                'email'         => $this,
            ),
            '',
            plugin_dir_path( __FILE__ ) . '../../templates/'
        );
    }
}