<?php
/**
 * Toimittaja-sähköposti – korttilayout
 *
 * @var WC_Order                $order
 * @var WC_Order_Item_Product[] $items
 * @var string                  $extra_html  // laskutuslohko Worksite-lisäosasta
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$header_bg   = '#242424';
$border_gray = '#e2e2e2';
$font_base   = 'font-family:Arial,Helvetica,sans-serif;';
$logo_url    = 'https://store.tapojarvi.online/wp-content/uploads/2025/03/Tapojarvi-Juhlalogo-2025-valkoinen.png';

/** @var WC_Countries $countries */
$countries = new WC_Countries();
?>
<table width="100%" cellpadding="0" cellspacing="0" style="<?= $font_base ?>background:#f5f7fa;padding:20px 0;">
  <tr><td align="center">

    <!-- kortti -->
    <table width="600" cellpadding="0" cellspacing="0"
           style="background:#fff;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.06);overflow:hidden;">

      <!-- ─── HEADER ───────────────────────────────────────────── -->
      <tr>
        <td style="background:<?= $header_bg ?>;text-align:center;padding:24px;">
          <img src="<?= esc_url( $logo_url ) ?>" width="180" alt="<?= esc_attr__( 'Tapojärvi', 'tapojarvi-product-recipient-email' ); ?>" style="display:block;border:0;">
        </td>
      </tr>

      <!-- ─── RUNKO ────────────────────────────────────────────── -->
      <tr><td style="padding:32px;<?= $font_base ?>color:#333;font-size:14px;line-height:1.5;">

        <!-- otsikko -->
        <h2 style="margin:0 0 22px;font-size:20px;color:#111;">
          <?php
          printf(
            /* translators: 1 = order no, 2 = date */
            esc_html__( 'Tilaus #%1$s – %2$s', 'tapojarvi-product-recipient-email' ),
            $order->get_order_number(),
            $order->get_date_created()->date_i18n( 'd.m.Y H:i' )
          );
          ?>
        </h2>

        <!-- tilaaja -->
        <?php
        $name  = $order->get_formatted_billing_full_name();
        $title = $order->get_meta( 'titteli_', true );
        if ( ! $title && $order->get_user_id() ) {
          $title = get_user_meta( $order->get_user_id(), 'titteli_', true );
        }
        $buyer_line = $title ? "$name, $title" : $name;
        ?>

        <p style="margin:0 0 4px;">
          <strong><?php esc_html_e( 'Tilaaja', 'tapojarvi-product-recipient-email' ); ?>:</strong>
          <?= esc_html( $buyer_line ); ?>
        </p>

        <p style="margin:0 0 4px;">
          <strong><?php esc_html_e( 'Puhelin', 'tapojarvi-product-recipient-email' ); ?>:</strong>
          <?= esc_html( $order->get_billing_phone() ?: '—' ); ?>
        </p>

        <p style="margin:0 0 22px;">
          <strong><?php esc_html_e( 'Sähköposti', 'tapojarvi-product-recipient-email' ); ?>:</strong>
          <?= esc_html( $order->get_billing_email() ); ?>
        </p>

        <!-- työmaa (jos saatavilla) -->
        <?php
        $site = $order->get_meta( 'tyomaa_koodi' )
             ?: $order->get_meta( 'billing_tyomaa_koodi' )
             ?: $order->get_meta( '_tap_worksite_code' );
        if ( $site ) : ?>
          <p style="margin:0 0 22px;">
            <strong><?php esc_html_e( 'Työmaa', 'tapojarvi-product-recipient-email' ); ?>:</strong>
            <?= esc_html( $site ); ?>
          </p>
        <?php endif; ?>

        <!-- ── TUOTELISTA ─────────────────────────────────────── -->
        <table width="100%" cellpadding="0" cellspacing="0"
               style="border:1px solid <?= $border_gray ?>;border-collapse:collapse;">
          <thead>
            <tr style="background:#fafafa;">
              <th align="left"
                  style="padding:8px 12px;border-bottom:1px solid <?= $border_gray ?>;">
                <?php esc_html_e( 'Tuote', 'tapojarvi-product-recipient-email' ); ?>
              </th>
              <th align="center"
                  style="padding:8px 12px;border-bottom:1px solid <?= $border_gray ?>;">
                <?php esc_html_e( 'Määrä', 'tapojarvi-product-recipient-email' ); ?>
              </th>
            </tr>
          </thead>

          <tbody>
            <?php
              $last = end( $items ); // jotta viimeiseltä riviltä voidaan poistaa raja
              foreach ( $items as $item ) :
            ?>
              <tr style="border-bottom:1px solid #e5e5e5;<?= $item === $last ? 'border-bottom:none;' : ''; ?>">
                <!-- tuote & variaatiot -->
                <td style="padding:8px 12px;">
                  <?php
                    echo esc_html( $item->get_name() );

                    // variaatioattribuutit
                    if ( $item->get_variation_id() ) {
                      $prod  = $item->get_product();
                      $attrs = $prod ? wc_get_formatted_variation( $prod, true, false, true ) : '';
                      if ( $attrs ) {
                        echo '<br><span style="font-size:1em;font-weight:600;color:#333;">'
                             . wp_kses_post( $attrs )
                             . '</span>';
                      }
                    }

                    // SKU
                    $sku = $item->get_product() ? $item->get_product()->get_sku() : '';
                    if ( $sku ) {
                      echo '<br><small style="color:#666;">'
                           . esc_html__( 'SKU', 'tapojarvi-product-recipient-email' )
                           . ': ' . esc_html( $sku )
                           . '</small>';
                    }

                    // osasto
                    $dept = $item->get_meta( '_tap_department', true ) ?: '';
                    if ( $dept ) {
                      echo '<br><small style="color:#666;">'
                           . esc_html__( 'Osasto', 'tapojarvi-product-recipient-email' )
                           . ': ' . esc_html( $dept )
                           . '</small>';
                    }
                  ?>
                </td>

                <!-- määrä -->
                <td align="center" style="padding:8px 12px;">
                  <?= esc_html( $item->get_quantity() ); ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <!-- ── TOIMITUSOSOITE ─────────────────────────────────── -->
        <?php
          $addr = $order->get_address( 'shipping' ) ?: $order->get_address( 'billing' );
          if ( ! empty( $addr['address_1'] ) ) :
            if ( $order->get_shipping_address_2() ) {
              $addr['address_2'] = $order->get_shipping_address_2();
            }
        ?>
          <h3 style="margin:26px 0 6px;font-size:16px;color:#111;">
            <?php esc_html_e( 'Toimitusosoite', 'tapojarvi-product-recipient-email' ); ?>
          </h3>
          <p style="margin:0;">
            <?= wp_kses_post( $countries->get_formatted_address( $addr ) ); ?>

            <?php
              // 1) yritetään shipping-puhelinta, muussa tapauksessa billing-puhelin
              $ship_phone = $order->get_meta( '_shipping_phone', true ) ?: $order->get_billing_phone();

              if ( $ship_phone ) {
                echo '<br><br>' .
                     esc_html__( 'Vastaanottajan puhelin', 'tapojarvi-product-recipient-email' ) .
                     ': ' . esc_html( $ship_phone );
              }
            ?>
          </p>
        <?php endif; ?>

        <!-- ── LISÄHUOMAUTUS ──────────────────────────────────── -->
        <?php if ( $note = $order->get_customer_note() ) : ?>
          <h3 style="margin:26px 0 6px;font-size:16px;color:#111;">
            <?php esc_html_e( 'Asiakkaan lisätiedot', 'tapojarvi-product-recipient-email' ); ?>
          </h3>
          <p style="margin:0;"><?= nl2br( esc_html( $note ) ); ?></p>
        <?php endif; ?>

        <!-- 🔗 LASKUTUSLOHKO (Worksite-lisäosa) – SIIRRETTY ALIMMALLE -->
        <?php if ( ! empty( $extra_html ) ) : ?>
          <div style="margin-top:22px;">
            <?= $extra_html; // sisältää valmiin <table>-lohkon otsikoineen ?>
          </div>
        <?php endif; ?>

      </td></tr>

      <!-- ─── FOOTER ──────────────────────────────────────────── -->
      <tr>
        <td style="background:#fafafa;text-align:center;padding:16px;<?= $font_base ?>font-size:12px;color:#666;">
          <?php esc_html_e( 'Tämä viesti on lähetetty Tapojärvi Store -järjestelmästä.', 'tapojarvi-product-recipient-email' ); ?>
        </td>
      </tr>
    </table>

  </td></tr>
</table>