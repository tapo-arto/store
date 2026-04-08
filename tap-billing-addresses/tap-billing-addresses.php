<?php
/**
 * Plugin Name: Tapojärvi – Worksite Billing Addresses
 * Description: Lisää Asetukset-sivun, jossa voi määrittää useita laskutusosoiteblokkeja ja liittää ne valittuihin työmaihin. Liimaa oikean blokin toimittajalle lähtevään sähköpostiin tilauksen työmaatunnisteen perusteella.
 * Version: 1.2.2
 * Author: Tapojärvi
 */

if (!defined('ABSPATH')) exit;

const TAP_BILLING_OPTION_KEY    = 'tap_billing_address_sets';
const TAP_BILLING_SECTION_TITLE = 'tap_billing_section_title';

/* -----------------------------------------------------------
 * UTIL: Lue työmaat listana (Asetukset → Työmaat -optio)
 * --------------------------------------------------------- */
function tap_get_all_worksites(): array {
    $raw  = get_option('tapojarvi_tyomaat_lista', '');
    $rows = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$raw)));
    if (empty($rows)) {
        // Fallbackit (voi poistaa jos ei tarvita)
        $rows = [
            'Keminmaan keskuskorjaamo','Siilinjärvi','Tornio / Röyttän työmaa','Kemin kaivos',
            'Kittilän kaivos','Raahe','Oulunsalo','Kevitsa','Hallinto','EIR Center',
            'Tapojarvi Italia S.r.l','Tapojarvi Hellas','Tapojarvi Sverige AB Pajala',
            'Hannukainen Mining','Sotkamo',
        ];
    }
    // Poista duplikaatit, säilytä järjestys
    $uniq = [];
    foreach ($rows as $r) {
        if ($r === '') continue;
        $k = mb_strtolower($r);
        if (!isset($uniq[$k])) $uniq[$k] = $r;
    }
    return array_values($uniq);
}

/* -----------------------------------------------------------
 * UTIL: Hae tilauksen työmaatunniste (order meta → user meta)
 * --------------------------------------------------------- */
function tap_get_order_worksite_code(WC_Order $order): ?string {
    foreach (['tyomaa_koodi','billing_tyomaa_koodi','_tap_worksite_code'] as $key) {
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

/* -----------------------------------------------------------
 * DATA: Lue ja tallenna osoite-setit
 * Rakenne optionille:
 *   [ ['name'=>'Suomi-laskutus','html'=>'<p>..</p>','sites'=>['Kevitsa','Kemin kaivos']], ... ]
 * --------------------------------------------------------- */
function tap_get_address_sets(): array {
    $sets = get_option(TAP_BILLING_OPTION_KEY, []);
    return is_array($sets) ? $sets : [];
}
function tap_save_address_sets(array $sets): void {
    update_option(TAP_BILLING_OPTION_KEY, $sets);
}

/* -----------------------------------------------------------
 * MATCH: Palauta setti, joka sisältää annetun työmaan (case-insensitive)
 * --------------------------------------------------------- */
function tap_find_set_by_worksite(string $worksite): ?array {
    $needle = mb_strtolower(trim($worksite));
    if ($needle === '') return null;
    foreach (tap_get_address_sets() as $set) {
        $sites = isset($set['sites']) && is_array($set['sites']) ? $set['sites'] : [];
        foreach ($sites as $s) {
            if (mb_strtolower(trim((string)$s)) === $needle) {
                return $set;
            }
        }
    }
    return null;
}

/* -----------------------------------------------------------
 * FORMAT: Varmista rivitykset ja marginaalit emailiin
 *  - Pelkkä teksti: escapetaan ja \n → <br>
 *  - HTML: normalisoidaan <p>/<br> ja lisätään riviväli
 *  (EI käytetä white-space CSS:ää, jotta Outlook ym. toimivat varmasti)
 * --------------------------------------------------------- */
function tap_format_for_email_html($html_or_text){
    $s = (string)$html_or_text;
    $has_tags = (strpos($s, '<') !== false);

    if (!$has_tags) {
        // Pelkkä teksti → escapetaan ja \n → <br>
        $s = nl2br( esc_html( $s ) );
    } else {
        // HTML → sallittu subsetti, lisää p/br rivivälit
        $s = wp_kses_post( $s );

        // Lisää kappaleisiin marginaali + line-height
        $s = preg_replace(
            '/<p(\s+[^>]*)?>/i',
            '<p$1 style="margin:0 0 6px 0; line-height:1.4;">',
            $s
        );

        // Normalisoi <br>-tagit (ja lisää varmuuden vuoksi line-height)
        $s = preg_replace(
            '/<br\s*\/?>/i',
            '<br style="line-height:1.4;">',
            $s
        );

        // Jos mukana on vielä raakaa rivinvaihtoa, käännetään ne eksplisiittisiksi <br>
        if (strpos($s, "\n") !== false) {
            $s = str_replace(["\r\n", "\r", "\n"], '<br style="line-height:1.4;">', $s);
        }
    }

    return $s;
}

/* -----------------------------------------------------------
 * RENDER: Emailiin liimattava HTML-blokki
 * --------------------------------------------------------- */
function tap_render_billing_block_html(string $inner_html): string {
    $title     = get_option(TAP_BILLING_SECTION_TITLE, 'Laskutustiedot (verkkokauppa)');
    $formatted = tap_format_for_email_html($inner_html);

    if (trim(strip_tags($formatted)) === '') return '';

    return '
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:16px 0;border:1px solid #eee;border-radius:8px;">
      <tr><td style="background:#f7f7f7;padding:10px 12px;font-weight:700;">'.esc_html($title).'</td></tr>
      <tr><td style="padding:10px 12px;">'.$formatted.'</td></tr>
    </table>';
}

/* -----------------------------------------------------------
 * EMAIL-HOOK: Liimaa blokki toimittajasähköpostiin
 * VAATII että lähetyspolku käyttää filtteriä:
 *   apply_filters('tapojarvi_supplier_email_extra', $html, $order)
 * --------------------------------------------------------- */
add_filter('tapojarvi_supplier_email_extra', function($html, $order){
    if (!$order instanceof WC_Order) return $html;

    $code = tap_get_order_worksite_code($order);
    if (!$code) return $html;

    $set = tap_find_set_by_worksite($code);
    if (!$set || empty($set['html'])) return $html;

    return $html . tap_render_billing_block_html($set['html']);
}, 10, 2);

/* ==========================================================
 * ADMIN: Asetussivu – useita osoite-settejä + WYSIWYG
 * ======================================================== */
add_action('admin_menu', function(){
    add_options_page(
        __('Laskutusosoitteet', 'tapojarvi'),
        __('Laskutusosoitteet', 'tapojarvi'),
        'manage_options',
        'tap_billing_address_sets',
        'tap_billing_render_admin_page'
    );
});

function tap_billing_render_admin_page() {
    if (!current_user_can('manage_options')) return;

    // Lataa editori-assetit
    if (function_exists('wp_enqueue_editor')) {
        wp_enqueue_editor();
    }

    // Tallennus
    if (isset($_POST['tap_billing_save'])) {
        check_admin_referer('tap_billing_save_sets');

        $incoming = [];
        if (isset($_POST['sets']) && is_array($_POST['sets'])) {
            foreach ($_POST['sets'] as $row) {
                $name  = isset($row['name'])  ? sanitize_text_field($row['name']) : '';
                $html  = isset($row['html'])  ? (string)$row['html'] : '';
                $sites = isset($row['sites']) && is_array($row['sites']) ? array_map('sanitize_text_field', $row['sites']) : [];

                // Siivoa tyhjät
                $sites_clean = [];
                foreach ($sites as $s) {
                    $s = trim($s);
                    if ($s !== '') $sites_clean[] = $s;
                }

                if ($html !== '' && !empty($sites_clean)) {
                    $incoming[] = [
                        'name'  => $name,
                        'html'  => wp_kses_post($html),
                        'sites' => array_values(array_unique($sites_clean)),
                    ];
                }
            }
        }

        tap_save_address_sets($incoming);

        // Otsikko
        $title = isset($_POST['section_title']) ? sanitize_text_field($_POST['section_title']) : 'Laskutustiedot (verkkokauppa)';
        update_option(TAP_BILLING_SECTION_TITLE, $title);

        echo '<div class="updated notice"><p>'.esc_html__('Asetukset tallennettu.', 'tapojarvi').'</p></div>';
    }

    $sets  = tap_get_address_sets();
    $title = get_option(TAP_BILLING_SECTION_TITLE, 'Laskutustiedot (verkkokauppa)');
    $sites = tap_get_all_worksites();
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Laskutusosoitteet työmaittain', 'tapojarvi'); ?></h1>

        <form method="post" action="">
            <?php wp_nonce_field('tap_billing_save_sets'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="section_title"><?php esc_html_e('Osiotsikko sähköpostissa', 'tapojarvi'); ?></label></th>
                    <td>
                        <input type="text" id="section_title" name="section_title" class="regular-text" value="<?php echo esc_attr($title); ?>" />
                        <p class="description"><?php esc_html_e('Näkyy sähköpostilohkon otsikkona.', 'tapojarvi'); ?></p>
                    </td>
                </tr>
            </table>

            <h2 style="margin-top:20px;"><?php esc_html_e('Osoite-setit', 'tapojarvi'); ?></h2>
            <p class="description"><?php esc_html_e('Lisää useita rivejä. Jokaisella rivillä valitse ne työmaat, jotka käyttävät kyseistä laskutusosoitetta. HTML-kenttä on WYSIWYG-muokattava.', 'tapojarvi'); ?></p>

            <div id="tap-billing-sets">
                <?php
                foreach ($sets as $i => $row) {
                    tap_billing_render_set_row($i, $row, $sites);
                }
                if (empty($sets)) {
                    tap_billing_render_set_row(0, ['name'=>'','html'=>'','sites'=>[]], $sites);
                }
                ?>
            </div>

            <p>
                <button type="button" class="button" id="tap-add-row"><?php esc_html_e('Lisää rivi', 'tapojarvi'); ?></button>
            </p>

            <p class="submit">
                <button type="submit" name="tap_billing_save" class="button-primary"><?php esc_html_e('Tallenna', 'tapojarvi'); ?></button>
            </p>
        </form>
    </div>

    <style>
      .tap-row { border:1px solid #e5e5e5; padding:12px; margin:12px 0; background:#fff; }
      .tap-row .row-actions { text-align:right; margin-top:6px; }
      .tap-flex { display:flex; gap:16px; flex-wrap:wrap; }
      .tap-flex > .col { flex:1 1 320px; min-width:280px; }
      .tap-sites { min-width:260px; height:180px; }
      .tap-editor { min-height:220px; }
    </style>

    <script>
    (function(){
        let nextIndex = <?php echo (int)max(1, count($sets)); ?>;

        function escAttr(s){ return String(s).replace(/"/g,'&quot;'); }
        function escHtml(s){ return String(s).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]); }

        window.tapNewRowTemplate = function(i, sites){
            const editorId = 'tap_billing_html_' + i;
            let options = '';
            for (let s of sites) {
                options += '<option value="'+escAttr(s)+'">'+escHtml(s)+'</option>';
            }
            return '' +
            '<div class="tap-row" data-index="'+i+'">' +
              '<div class="tap-flex">' +
                '<div class="col">' +
                    '<label><strong><?php echo esc_js(__('Nimi (valinnainen)', 'tapojarvi')); ?></strong></label><br>' +
                    '<input type="text" name="sets['+i+'][name]" class="regular-text" />' +
                    '<p class="description"><?php echo esc_js(__('Vain hallinnollinen nimi helpottamaan listaa.', 'tapojarvi')); ?></p>' +
                </div>' +
                '<div class="col">' +
                    '<label><strong><?php echo esc_js(__('Työmaat (pidä Ctrl / Cmd pohjassa monivalintaan)', 'tapojarvi')); ?></strong></label><br>' +
                    '<select name="sets['+i+'][sites][]" class="tap-sites" multiple>' + options + '</select>' +
                </div>' +
              '</div>' +
              '<div style="margin-top:8px;">' +
                '<label><strong><?php echo esc_js(__('Laskutuslohkon sisältö (WYSIWYG)', 'tapojarvi')); ?></strong></label>' +
                '<textarea id="'+editorId+'" name="sets['+i+'][html]" class="wp-editor-area tap-editor"></textarea>' +
              '</div>' +
              '<div class="row-actions">' +
                '<a href="#" class="tap-remove" style="color:#b32d2e;"><?php echo esc_js(__('Poista rivi', 'tapojarvi')); ?></a>' +
              '</div>' +
            '</div>';
        }

        // Lisää rivi
        document.getElementById('tap-add-row').addEventListener('click', function(){
            const container = document.getElementById('tap-billing-sets');
            const html = tapNewRowTemplate(nextIndex, <?php echo wp_json_encode($sites); ?>);
            const temp = document.createElement('div');
            temp.innerHTML = html;
            const row = temp.firstElementChild;
            container.appendChild(row);

            // Initoi WYSIWYG uudelle textarea:lle
            const ta = row.querySelector('textarea.wp-editor-area');
            if (ta && window.wp && wp.editor && wp.editor.initialize) {
                wp.editor.initialize(ta.id, {
                    tinymce: true,
                    quicktags: true,
                    mediaButtons: false
                });
            }
            nextIndex++;
        });

        // Poista rivi (ja TinyMCE-instanssi siististi)
        document.addEventListener('click', function(e){
            if (e.target && e.target.classList.contains('tap-remove')) {
                e.preventDefault();
                const wrap = e.target.closest('.tap-row');
                if (wrap) {
                    const ta = wrap.querySelector('textarea.wp-editor-area');
                    if (ta && window.tinyMCE && tinyMCE.get(ta.id)) tinyMCE.get(ta.id).remove();
                    wrap.remove();
                }
            }
        });

        // Ennen submitia: pakota TinyMCE → textarea
        document.addEventListener('submit', function(){
            if (typeof tinyMCE !== 'undefined' && tinyMCE.triggerSave) {
                tinyMCE.triggerSave();
            }
        }, true);
    })();
    </script>
    <?php
}

/* Yhden setti-rivin renderöinti (olemassa oleville) */
function tap_billing_render_set_row(int $i, array $row, array $sites_all) {
    $name  = isset($row['name']) ? (string)$row['name'] : '';
    $html  = isset($row['html']) ? (string)$row['html'] : '';
    $sites = isset($row['sites']) && is_array($row['sites']) ? $row['sites'] : [];
    $editor_id = 'tap_billing_html_'.$i;
    ?>
    <div class="tap-row" data-index="<?php echo esc_attr($i); ?>">
        <div class="tap-flex">
            <div class="col">
                <label><strong><?php esc_html_e('Nimi (valinnainen)', 'tapojarvi'); ?></strong></label><br>
                <input type="text" name="sets[<?php echo esc_attr($i); ?>][name]" class="regular-text" value="<?php echo esc_attr($name); ?>" />
                <p class="description"><?php esc_html_e('Vain hallinnollinen nimi helpottamaan listaa.', 'tapojarvi'); ?></p>
            </div>
            <div class="col">
                <label><strong><?php esc_html_e('Työmaat (pidä Ctrl / Cmd pohjassa monivalintaan)', 'tapojarvi'); ?></strong></label><br>
                <select name="sets[<?php echo esc_attr($i); ?>][sites][]" class="tap-sites" multiple>
                    <?php foreach ($sites_all as $opt): ?>
                        <option value="<?php echo esc_attr($opt); ?>" <?php selected(in_array($opt, $sites, true)); ?>>
                            <?php echo esc_html($opt); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div style="margin-top:8px;">
            <label><strong><?php esc_html_e('Laskutuslohkon sisältö (WYSIWYG)', 'tapojarvi'); ?></strong></label>
            <?php
            wp_editor(
                $html,
                $editor_id,
                [
                    'textarea_name' => 'sets['.$i.'][html]',
                    'media_buttons' => false,
                    'textarea_rows' => 10,
                    'teeny'         => false,
                    'tinymce'       => [
                        'toolbar1' => 'formatselect,bold,italic,underline,bullist,numlist,link,unlink,removeformat',
                        'toolbar2' => '',
                    ],
                    'quicktags'     => true,
                ]
            );
            ?>
        </div>
        <div class="row-actions">
            <a href="#" class="tap-remove" style="color:#b32d2e;"><?php esc_html_e('Poista rivi', 'tapojarvi'); ?></a>
        </div>
    </div>
    <?php
}

/* ==========================================================
 * TESTI: [tap_billing_test code="Kevitsa"]
 * ======================================================== */
add_shortcode('tap_billing_test', function($atts){
    $a = shortcode_atts(['code'=> ''], $atts, 'tap_billing_test');
    $code = trim((string)$a['code']);
    if ($code === '') return '<p><em>Anna attribute code="…"</em></p>';

    $set = tap_find_set_by_worksite($code);
    if (!$set) {
        return '<p style="color:#b00;">Ei settiä työmaalle <strong>'.esc_html($code).'</strong>. Lisää Asetukset → Laskutusosoitteet -sivulla setti ja valitse kyseinen työmaa.</p>';
    }
    $name  = !empty($set['name']) ? ' <small style="opacity:.7;">('.esc_html($set['name']).')</small>' : '';
    $block = tap_render_billing_block_html($set['html']);
    return '<p><strong>Match:</strong> '.esc_html($code).$name.'</p>'.$block;
});