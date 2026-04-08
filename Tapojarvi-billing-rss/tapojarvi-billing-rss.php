<?php
/**
 * Plugin Name: Tapojärvi – Billing From RSS (codes + aliases)
 * Description: Hakee laskutustiedot contactlist-RSS:stä ja liimaa ne toimittajalle lähtevään sähköpostiin. Ensisijaisesti täsmää tilauksen työmaatunnisteen <tap:worksite_code>:en. Ellei löydy, käyttää asetusten alias-JSONia (esim. "Kevitsa" => "TAP-FI").
 * Version: 1.4.0
 * Author: Tapojärvi
 */

if (!defined('ABSPATH')) exit;

/* =========================================================
 * ASETUKSET
 * ======================================================= */

/** RSS-syötteen URL (kotisivut, contactlist-CPT) */
function tap_billing_rss_url() {
    return get_option('tap_billing_rss_url', '');
}

/**
 * Työmaatunniste-aliakset (JSON)
 * - Avain: verkkokaupan puolella käytetty koodi (esim. "Kevitsa", "Kemin kaivos")
 * - Arvo: RSS:ssä oleva <tap:worksite_code> (esim. "TAP-FI", "TAP-IT", "TAP-GR")
 */
function tap_billing_code_aliases() : array {
    $raw = get_option('tap_billing_code_aliases', '');
    $arr = json_decode($raw, true);
    return is_array($arr) ? $arr : [];
}

/** Admin-kentät: RSS + Aliakset */
add_action('admin_init', function(){
    register_setting('general', 'tap_billing_rss_url', [
        'type' => 'string',
        'sanitize_callback' => 'esc_url_raw'
    ]);
    add_settings_field('tap_billing_rss_url', 'Tapojärvi Contactlist RSS', function(){
        $val = esc_url(get_option('tap_billing_rss_url',''));
        echo '<input type="url" name="tap_billing_rss_url" value="'.$val.'" class="regular-text ltr" placeholder="https://tapojarvi.com/feed/?post_type=contactlist" />';
        echo '<p class="description">Kotisivujen contactlist-RSS. Pakollinen.</p>';
    }, 'general');

    register_setting('general', 'tap_billing_code_aliases', [
        'type' => 'string',
        'sanitize_callback' => function($v){
            json_decode($v, true);
            return (json_last_error() === JSON_ERROR_NONE) ? $v : '';
        }
    ]);
    add_settings_field('tap_billing_code_aliases', 'Työmaatunniste-aliaset (JSON)', function(){
        $val = esc_textarea(get_option('tap_billing_code_aliases',''));
        $ph  = "{\n  \"Kevitsa\": \"TAP-FI\",\n  \"Kemin kaivos\": \"TAP-FI\",\n  \"Tapojarvi Hellas\": \"TAP-GR\"\n}";
        echo '<textarea name="tap_billing_code_aliases" class="large-text code" rows="8" placeholder="'.esc_attr($ph).'">'.$val.'</textarea>';
        echo '<p class="description">Avain = verkkokaupan puolen työmaatunniste (esim. ”Kevitsa”), arvo = RSS:n &lt;tap:worksite_code&gt; (esim. ”TAP-FI”).</p>';
    }, 'general');
});

/* =========================================================
 * UTILIT
 * ======================================================= */

/** Hae tilauksen työmaatunniste prioriteetilla: order meta → alt-metat → user meta */
function tap_get_order_worksite_code(WC_Order $order): ?string {
    foreach ([
        'tyomaa_koodi',          // teillä käytössä tilauksella
        '_tap_worksite_code',    // vaihtoehtoinen
        'billing_tyomaa_koodi',  // joskus tallennettu billing_-prefiksillä
    ] as $key) {
        $v = $order->get_meta($key);
        if ($v) return trim((string)$v);
    }
    $uid = $order->get_user_id();
    if ($uid) {
        $u = get_user_meta($uid, 'tyomaa_koodi', true);
        if ($u) return trim((string)$u);
    }
    return null;
}

/** Parsitaan RSS ja rakennetaan indeksi (mukana vain itemit, joilla on jokin worksite_code) */
function tap_fetch_contactlist_index_from_rss(): array {
    $cache_key = 'tap_contactlist_rss_index_codes_v3';
    $cached = get_transient($cache_key);
    if ($cached !== false) return is_array($cached) ? $cached : [];

    $url = tap_billing_rss_url();
    if (!$url) return [];

    $resp = wp_remote_get($url, [
        'timeout' => 12,
        'headers' => ['Accept' => 'application/rss+xml, application/xml']
    ]);
    if (is_wp_error($resp)) {
        set_transient($cache_key, [], 15 * MINUTE_IN_SECONDS);
        return [];
    }

    $body = wp_remote_retrieve_body($resp);
    if (!$body) {
        set_transient($cache_key, [], 15 * MINUTE_IN_SECONDS);
        return [];
    }

    $xml = @simplexml_load_string($body);
    if (!$xml || !$xml->channel || !$xml->channel->item) {
        set_transient($cache_key, [], 15 * MINUTE_IN_SECONDS);
        return [];
    }

    $index = [
        'by_code'  => [],  // canonical code (upper) => entry
    ];

    foreach ($xml->channel->item as $item) {
        $title = trim((string)$item->title);
        $link  = rtrim(trim((string)$item->link), '/');

        // Tapojärvi-namespacen elementit
        $ns = $item->children('https://tapojarvi.com/ns/tapojarvi');

        // Kerää koodit (yksittäinen ja/tai lista)
        $codes = [];
        if (isset($ns->worksite_code)) {
            $cv = strtoupper(trim((string)$ns->worksite_code));
            if ($cv !== '') $codes[] = $cv;
        }
        if (isset($ns->worksite_codes->code)) {
            foreach ($ns->worksite_codes->code as $c) {
                $cv = strtoupper(trim((string)$c));
                if ($cv !== '') $codes[] = $cv;
            }
        }

        // Ei worksite-koodia → ei kelpaa tähän käyttöön
        if (empty($codes)) continue;

        $entry = [
            'title'       => $title,
            'link'        => $link,
            'tiedot_html' => isset($ns->tiedot_contactlist_html) ? (string)$ns->tiedot_contactlist_html : '',
            'osoite_text' => isset($ns->osoite_contactlist) ? (string)$ns->osoite_contactlist : '',
            'osoite_html' => isset($ns->osoite_contactlist_html) ? (string)$ns->osoite_contactlist_html : '',
            'codes'       => $codes,
        ];

        foreach ($codes as $cv) {
            // Viimeisin voittaa; oletusfeedissä koodit ovat yksikäsitteisiä
            $index['by_code'][$cv] = $entry;
        }
    }

    set_transient($cache_key, $index, 6 * HOUR_IN_SECONDS);
    return $index;
}

/**
 * Ratkaise entry annetulle työmaatunnisteelle.
 * 1) Suora osuma: tilauksen koodi == feedin <tap:worksite_code>
 * 2) Alias-osuma: alias[tilauksen koodi] == feedin <tap:worksite_code>
 */
function tap_resolve_entry_for_worksite(string $worksite_code): ?array {
    $index = tap_fetch_contactlist_index_from_rss();
    if (!$index) return null;

    // 1) Suora osuma
    $code_uc = strtoupper(trim($worksite_code));
    if (isset($index['by_code'][$code_uc])) {
        return $index['by_code'][$code_uc];
    }

    // 2) Alias-osuma
    $aliases = tap_billing_code_aliases();
    if (isset($aliases[$worksite_code])) {
        $canonical = strtoupper(trim((string)$aliases[$worksite_code]));
        if ($canonical !== '' && isset($index['by_code'][$canonical])) {
            return $index['by_code'][$canonical];
        }
    }

    return null;
}

/** Renderöi HTML-blokin sähköpostiin */
function tap_render_billing_block_from_entry(array $e): string {
    $html_top = trim((string)($e['tiedot_html'] ?? ''));
    $addr_html= trim((string)($e['osoite_html'] ?? ''));
    $addr_txt = trim((string)($e['osoite_text'] ?? ''));

    if ($addr_html === '' && $addr_txt !== '') {
        $addr_html = '<p>'.esc_html($addr_txt).'</p>';
    }

    $inner = '';
    if ($html_top !== '') {
        $inner .= '<tr><td style="padding:2px 0;">'.wp_kses_post($html_top).'</td></tr>';
    }
    if ($addr_html !== '') {
        $inner .= '<tr><td style="padding:2px 0;"><strong>'.esc_html__('Toimitusosoite', 'tapojarvi').':</strong> '.wp_kses_post($addr_html).'</td></tr>';
    }
    if ($inner === '') return '';

    return '
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:16px 0;border:1px solid #eee;border-radius:8px;">
      <tr><td style="background:#f7f7f7;padding:10px 12px;font-weight:700;">'.esc_html__('Laskutustiedot (virallinen – RSS)', 'tapojarvi').'</td></tr>
      <tr><td style="padding:10px 12px;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
          '.$inner.'
        </table>
      </td></tr>
    </table>';
}

/* =========================================================
 * LIIMAUS TOIMITTAJASÄHKÖPOSTIIN
 * ======================================================= */
/**
 * Odottaa, että toimittajalle lähtevän sähköpostin runko kulkee tämän filtterin kautta:
 * apply_filters('tapojarvi_supplier_email_extra', $html, $order)
 */
add_filter('tapojarvi_supplier_email_extra', function($html, $order) {
    if (!$order instanceof WC_Order) return $html;

    $code = tap_get_order_worksite_code($order);
    if (!$code) return $html;

    $entry = tap_resolve_entry_for_worksite($code);
    if (!$entry) return $html;

    $block = tap_render_billing_block_from_entry($entry);
    if ($block === '') return $html;

    return $html . $block;
}, 10, 2);

/* =========================================================
 * DEBUG / TESTI
 * ======================================================= */

/** Tyhjennä RSS-välimuisti: lisää URL:iin ?tap_clear_rss_index=1 (admin) */
add_action('init', function(){
    if ( current_user_can('manage_options') && isset($_GET['tap_clear_rss_index']) ) {
        delete_transient('tap_contactlist_rss_index_codes_v3');
        wp_die('OK: RSS-indeksi tyhjennetty.');
    }
});

/**
 * Testi-shortcode:
 * [tap_billing_test code="Kevitsa"]  → alias "Kevitsa" => "TAP-FI"
 * [tap_billing_test code="TAP-GR"]   → suora osuma Kreikan laskutuspostaukseen
 */
add_shortcode('tap_billing_test', function($atts){
    $a = shortcode_atts(['code'=> ''], $atts, 'tap_billing_test');
    $code = trim((string)$a['code']);
    if ($code === '') return '<p><em>Anna attribute code="…"</em></p>';

    $e = tap_resolve_entry_for_worksite($code);
    if (!$e) {
        return '<p style="color:#b00;">Ei vastaavaa contactlist-kohdetta koodille <strong>'.esc_html($code).'</strong> (ei suoraa worksite_code-osumaa eikä alias-osumaa).</p>';
    }
    $block = tap_render_billing_block_from_entry($e);
    $debug = '<p><small>Match: <code>'.esc_html($e['title']).'</code> — <a href="'.esc_url($e['link']).'" target="_blank" rel="noopener">avaa</a></small></p>';
    return $debug.$block;
});