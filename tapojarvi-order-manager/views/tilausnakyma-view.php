<?php
/**
 * View file: tilausnakyma-view.php
 * -------------------------------------------------
 *  • Näyttää työmaatilaukset välilehdissä (uudet / hyväksytyt / arkistoidut)
 *  • Mahdollistaa bulk-toiminnot: hyväksy, palauta, arkistoi…
 *  • Käyttää JS:ää nappien aktivointiin ja modaalien hallintaan
 * -------------------------------------------------
 */

/* =================================================
 * 1. PHP-ESIVALMISTELUT
 * =================================================*/
$tab                        = $_GET['tab'] ?? 'new';
$uudet_tilaukset_maara      = $uudet_tilaukset_maara      ?? 0;
$hyvaksytyt_tilaukset_maara = $hyvaksytyt_tilaukset_maara ?? 0;

// -------------------------------------------------
// Päivämääräsuodattimen oletukset
// -------------------------------------------------

$today       = current_time( 'timestamp' );                 // WP-aikavyöhykkeen aikaleima
$year_start  = date_i18n( 'Y-01-01', $today );              // kuluvan vuoden 1.1.
$default_end = isset( $_GET['tap_end_date'] )
              ? sanitize_text_field( $_GET['tap_end_date'] )
              : date_i18n( 'Y-m-d', $today );

$default_start = isset( $_GET['tap_start_date'] )
               ? sanitize_text_field( $_GET['tap_start_date'] )
               : $year_start;                               // <-- UUSI OLETUS

/* Tyhjien näkymien viestit */
switch ( $tab ) {
  case 'new'      : $empty_msg = __( 'Ei uusia tilauksia työmaaltasi.', 'tapojarvi' ); break;
  case 'approved' : $empty_msg = __( 'Ei hyväksyttyjä tilauksia työmaaltasi.', 'tapojarvi' ); break;
  default         : $empty_msg = ''; break;               // archived
}

/* Kerää hyväksyttyjen ID:t (approved-välilehteä varten) */
$approved_order_ids = [];
foreach ( $orders as $order ) {
  $val = $order->get_meta( '_manager_approved', true );
  if ( in_array( $val, ['1','approved'], true ) ) {
    $approved_order_ids[] = $order->get_id();
  }
}
?>

<!-- =================================================
     2. NÄKYMÄN PÄÄRAKENNE
=====================================================-->

<div class="worksite-code">
 <strong><?php esc_html_e( 'Work-site code:', 'tapojarvi' ); ?></strong> <?php echo esc_html( $tyomaa_koodi ); ?>
</div>
<hr>

<!-- ---------- 2.1 Tabs ---------- -->
<!-- ---------- 2.1 Tabs ---------- -->
<div class="tom-tabs">
  <div class="tab-links">
    <a href="?tab=new" class="<?php echo $tab==='new' ? 'active' : ''; ?>">
      <i class="fas fa-inbox"></i>
      <?php /* Translators: %d = number of new orders */ 
        printf( esc_html__( 'New (%d)', 'tapojarvi' ), $uudet_tilaukset_maara ); ?>
    </a>

    <a href="?tab=approved" class="<?php echo $tab==='approved' ? 'active' : ''; ?>">
      <i class="fas fa-check-circle"></i>
      <?php /* Translators: %d = number of approved orders */ 
        printf( esc_html__( 'Approved (%d)', 'tapojarvi' ), $hyvaksytyt_tilaukset_maara ); ?>
    </a>

    <a href="?tab=archived" class="<?php echo $tab==='archived' ? 'active' : ''; ?>">
      <i class="fas fa-archive"></i>
      <?php esc_html_e( 'Archived', 'tapojarvi' ); ?>
    </a>
  </div>
</div>

<?php
/* ---------- 2.2 Success-viesti yläpalkissa ---------- */
if ( isset( $_GET['success'] ) ) :
  [ $action, $cnt ] = array_pad(
    explode( '_', sanitize_text_field( $_GET['success'] ), 2 ), 2, 0 );
  $cnt = (int) $cnt;

  switch ( $action ) {
    case 'approved' : $msg = sprintf( _n('✅ %d tilaus hyväksyttiin.',
                                        '✅ %d tilausta hyväksyttiin.', $cnt, 'tapojarvi'), $cnt ); break;
    case 'rejected' : $msg = sprintf( _n('🚫 %d tilaus hylättiin.',
                                        '🚫 %d tilausta hylättiin.', $cnt, 'tapojarvi'), $cnt ); break;
    case 'archived' : $msg = sprintf( _n('🗄️ %d tilaus arkistoitiin.',
                                        '🗄️ %d tilausta arkistoitiin.', $cnt, 'tapojarvi'), $cnt ); break;
    case 'deleted'  : $msg = sprintf( _n('🗑️ %d hyväksytty tilaus poistettiin.',
                                        '🗑️ %d hyväksyttyä tilausta poistettiin.', $cnt,'tapojarvi'), $cnt ); break;
    case 'returned' : $msg = sprintf( _n('↩️ %d tilaus palautettiin tilaajalle.',
                                        '↩️ %d tilausta palautettiin tilaajalle.', $cnt,'tapojarvi'), $cnt ); break;
    default         : $msg = __( 'Toiminto suoritettu.', 'tapojarvi' );
  }
  echo '<div class="success-message">'.esc_html($msg).'</div>';
endif;

/* ---------- 2.3 Päivämääräväli­suodatin (arkisto) ---------- */
if ( $tab === 'archived' ) : ?>
<div class="toolbar-filter tap-date-filter">
  <form id="tap-date-filter" method="get">
    <input type="hidden" name="tab" value="archived">

    <div class="date-field">
      <label for="tap_start_date"><?php esc_html_e( 'Start date', 'tapojarvi' ); ?></label>

      <input type="date" id="tap_start_date" name="tap_start_date"
             value="<?php echo esc_attr( $default_start ); ?>">
    </div>

    <div class="date-field">
<label for="tap_end_date"><?php esc_html_e( 'End date', 'tapojarvi' ); ?></label>
      <input type="date" id="tap_end_date" name="tap_end_date"
             value="<?php echo esc_attr( $default_end ); ?>">
    </div>

    <button type="submit" class="button filter-btn">
<span class="btn-label"><?php esc_html_e( 'Filter', 'tapojarvi' ); ?></span>
      <span class="btn-spinner" aria-hidden="true"></span>
    </button>

    <?php if ( isset( $_GET['tap_start_date'], $_GET['tap_end_date'] ) ) : ?>
      <a href="<?php echo esc_url( remove_query_arg( [ 'tap_start_date', 'tap_end_date' ] ) ); ?>"
         class="button secondary"><?php esc_html_e( 'Clear', 'tapojarvi' ); ?></a>
    <?php endif; ?>
  </form>
</div>
<?php endif; ?>

<!-- =================================================
     3. PÄÄLOMAKE & TOOLBAR
=====================================================-->
<form method="post" id="order-form">
<?php if ( in_array( $tab, ['new','approved','archived'], true ) ) : ?>
  <div class="toolbar">
    <div class="toolbar-actions">
      <?php if ( $tab === 'new' ) : ?>
<button type="button" id="approve-button" class="approve-btn" disabled>
  <i class="fas fa-check-circle"></i>
  <?php esc_html_e( 'Approve', 'tapojarvi' ); ?>
</button>
<button type="button" id="return-button" class="btn-return" disabled>
  <i class="fas fa-undo"></i>
  <?php esc_html_e( 'Return / Reject', 'tapojarvi' ); ?>
</button>
      <?php elseif ( $tab === 'approved' ) : ?>
        <?php if ( $approved_order_ids ) : ?>
<button type="button" id="to-cart-button" class="approve-btn" disabled>
  <i class="fas fa-cart-plus"></i>
  <?php esc_html_e( 'Add to cart', 'tapojarvi' ); ?>
</button>
        <?php endif; ?>
<button type="button" id="archive-button" class="btn-archive" disabled>
  <i class="fas fa-archive"></i>
  <?php esc_html_e( 'Archive', 'tapojarvi' ); ?>
</button>
      <?php endif; ?>

<?php if ( in_array( $tab, ['approved','archived'], true ) ) : ?>
  <button type="button" name="pdf_export_selected" class="pdf-button" disabled>
    <i class="fas fa-file-pdf"></i>
    <?php esc_html_e( 'Print PDF', 'tapojarvi' ); ?>
  </button>
<?php endif; ?>
    </div>

    <div class="toolbar-checkbox">
      <label>
  <input type="checkbox" id="select-all-orders">
  <?php esc_html_e( 'Select all', 'tapojarvi' ); ?>
</label>
    </div>
  </div>
<?php endif; ?>

  <!-- ---------- 3.1 Tilaukset ---------- -->
<div class="order-header">
  <div class="orderdate"><?php esc_html_e( 'Order date', 'tapojarvi' ); ?></div>
  <div class="orderemail"><?php esc_html_e( 'Customer',   'tapojarvi' ); ?></div>
  <div class="order-details-column"><?php esc_html_e( 'Items', 'tapojarvi' ); ?></div>
</div>

  <div class="order-grid">
  <?php foreach ( $orders as $order ) :
    $cls  = $tab === 'approved' ? 'processed' : 'pending';
    $note = $order->get_customer_note();
  ?>
    <div class="order-card <?php echo esc_attr($cls); ?>" id="order-<?php echo $order->get_id(); ?>">
      <div class="order-columns">
        <div class="orderdate"><?php echo esc_html($order->get_date_created()->date_i18n('j.n.Y')); ?></div>
        <div class="orderemail"><?php echo esc_html($order->get_billing_email()); ?></div>

        <div class="order-details-column">
          <ul class="orderitems">
            <?php foreach ( $order->get_items() as $item ) :
              $var = $item->get_variation_id()
                   ? strip_tags( wc_get_formatted_variation( $item->get_product(), true, false, true ) )
                   : '';
              echo '<li>'.esc_html( $item->get_name() . ($var ? " ({$var})" : '')
                                 . ' × ' . $item->get_quantity() ).'</li>';
            endforeach; ?>
          </ul>
          <?php if ( $note ) : ?>
            <div class="order-note">
  <strong><?php esc_html_e( 'Additional note:', 'tapojarvi' ); ?></strong>
  <?php echo esc_html( $note ); ?>
</div>
          <?php endif; ?>
        </div>

        <div class="order-checkbox">
          <input type="checkbox" name="bulk_approve_ids[]"
                 value="<?php echo esc_attr($order->get_id()); ?>">
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>

<?php if ( empty( $orders ) ) : ?>
  <div class="order-empty">
    <?php
    if ( $tab === 'archived' ) {
        // Rakennetaan dynaaminen aikaväli‑teksti lomakkeen arvoista
        $start = ! empty( $_GET['tap_start_date'] ) ? date_i18n( 'j.n.Y', strtotime( sanitize_text_field( $_GET['tap_start_date'] ) ) ) : '—';
        $end   = ! empty( $_GET['tap_end_date'] )   ? date_i18n( 'j.n.Y', strtotime( sanitize_text_field( $_GET['tap_end_date'] ) ) )   : '—';

        if ( $start === '—' && $end === '—' ) {
            // Mitään päivämääriä ei annettu → yleinen viesti
            _e( 'Ei arkistoituja tilauksia.', 'tapojarvi' );
        } else {
            /* Esim. “Valitulla aikavälillä 1.7.2025 – 31.7.2025 ei löytynyt arkistoituja tilauksia.” */
            printf(
                esc_html__( 'Valitulla aikavälillä %1$s – %2$s ei löytynyt arkistoituja tilauksia.', 'tapojarvi' ),
                esc_html( $start ),
                esc_html( $end )
            );
        }
    } else {
        echo esc_html( $empty_msg );
    }
    ?>
  </div>
<?php endif; ?>

</form>

<!-- =================================================
     4. YHTEISET ELEMENTIT (overlay, modalit)
=====================================================-->

<!-- Lataus-overlay -->
<div id="loading-overlay" class="loading-hidden">
  <div class="spinner"></div>
  <p><?php esc_html_e( 'Processing, please wait…', 'tapojarvi' ); ?></p>
</div>

<!-- ---------- 4.1 Modaalit ---------- -->
<?php /* Jokainen modal sisältää .modal-title -elementin,
         jonka openModal() päivittää (N)-lukemalla. */ ?>

<!-- Hyväksyntä -->
<!-- Approve -->
<div id="confirm-approve-modal" class="modal" style="display:none;">
  <div class="modal-content">
    <p class="modal-title">
      <strong><?php esc_html_e( 'Approve selected orders?', 'tapojarvi' ); ?></strong>
    </p>

    <div class="modal-buttons">
      <div class="button-row">
        <button id="confirm-approve-yes">
          <?php esc_html_e( 'Approve & add to cart', 'tapojarvi' ); ?>
        </button>
      </div>

      <div class="button-row">
        <button id="confirm-approve-only">
          <?php esc_html_e( 'Approve', 'tapojarvi' ); ?>
        </button>
        <button id="cancel-approve" type="button"
                onclick="closeModal('confirm-approve-modal')">
          <?php esc_html_e( 'Cancel', 'tapojarvi' ); ?>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Archive -->
<div id="confirm-archive-modal" class="modal" style="display:none;">
  <div class="modal-content">
    <p class="modal-title">
      <strong><?php esc_html_e( 'Archive selected orders?', 'tapojarvi' ); ?></strong>
    </p>
    <p class="modal-description">
      <?php esc_html_e(
        'Archived orders move to a separate list and will no longer appear here.',
        'tapojarvi'
      ); ?>
    </p>
    <div class="modal-buttons">
      <button id="confirm-archive-yes">
        <?php esc_html_e( 'Yes, archive', 'tapojarvi' ); ?>
      </button>
      <button id="cancel-archive" type="button"
              onclick="closeModal('confirm-archive-modal')">
        <?php esc_html_e( 'Cancel', 'tapojarvi' ); ?>
      </button>
    </div>
  </div>
</div>

<!-- Add to cart -->
<div id="confirm-to-cart-modal" class="modal" style="display:none;">
  <div class="modal-content">
    <p class="modal-title">
      <strong><?php esc_html_e( 'Add selected orders to cart?', 'tapojarvi' ); ?></strong>
    </p>
    <p class="modal-description">
      <?php esc_html_e(
        'Adds the products of the approved orders to the manager’s cart.',
        'tapojarvi'
      ); ?>
    </p>
    <div class="modal-buttons">
      <button id="confirm-to-cart-yes">
        <?php esc_html_e( 'Yes, add', 'tapojarvi' ); ?>
      </button>
      <button id="cancel-to-cart" type="button"
              onclick="closeModal('confirm-to-cart-modal')">
        <?php esc_html_e( 'Cancel', 'tapojarvi' ); ?>
      </button>
    </div>
  </div>
</div>

<!-- Return / Reject -->
<div id="confirm-return-modal" class="modal" style="display:none;">
  <div class="modal-content">
    <p class="modal-title">
      <strong><?php esc_html_e( 'Return selected orders?', 'tapojarvi' ); ?></strong>
    </p>
    <p class="modal-description">
      <?php esc_html_e(
        'Returning moves the orders to “returned”. The employee must place a new, corrected order.',
        'tapojarvi'
      ); ?>
    </p>

    <textarea id="return-message" rows="4"
      placeholder="<?php esc_attr_e( 'Write a clear reason – this message will be emailed to the employee', 'tapojarvi' ); ?>"></textarea>

    <div class="modal-buttons">
      <button id="confirm-return-yes">
        <?php esc_html_e( 'Yes, return', 'tapojarvi' ); ?>
      </button>
      <button type="button" onclick="closeModal('confirm-return-modal')">
        <?php esc_html_e( 'Cancel', 'tapojarvi' ); ?>
      </button>
    </div>
  </div>
</div>

<!-- =================================================
     5. SKRIPTIT (JS)
=====================================================-->
<script>
/* ===== Util-funktiot ===== */
function getSelectedCount () {
  return document.querySelectorAll('input[name="bulk_approve_ids[]"]:checked').length;
}
function openModal (id) {
  const modal  = document.getElementById(id);
  const count  = getSelectedCount();
  modal.style.display = 'flex';
  const title = modal.querySelector('.modal-title');
  if (title) {
    title.innerHTML = title.innerHTML.replace(/\s\(\d+\)$/, '');
    if (count) title.innerHTML += ` (${count})`;
  }
}
function closeModal(id){ document.getElementById(id).style.display='none'; }
function showLoading(){ document.getElementById('loading-overlay')?.classList.remove('loading-hidden'); }

/* ===== Pää-skripti ===== */
document.addEventListener('DOMContentLoaded', () => {

  /* --- referenssit --- */
  const form        = document.getElementById('order-form'),
        approveBtn  = document.getElementById('approve-button'),
        rejectBtn   = document.getElementById('reject-button'),   // ei vielä käytössä
        toCartBtn   = document.getElementById('to-cart-button'),
        archiveBtn  = document.getElementById('archive-button'),
        returnBtn   = document.getElementById('return-button'),
        pdfBtn      = document.querySelector('button[name="pdf_export_selected"]'),
        deleteBtn   = document.getElementById('delete-button'),   // ei vielä käytössä
        allCB       = document.getElementById('select-all-orders'),
        checkboxes  = document.querySelectorAll('input[name="bulk_approve_ids[]"]');

  /* --- napit päälle/pois --- */
  const syncBtns = () => {
    const any = !!getSelectedCount();
    [approveBtn,rejectBtn,returnBtn,toCartBtn,pdfBtn,deleteBtn,archiveBtn]
      .filter(Boolean)
      .forEach(btn => btn.disabled = !any);
  };
  syncBtns();
  checkboxes.forEach(cb=>cb.addEventListener('change', syncBtns));
  allCB?.addEventListener('change', e=>{
    checkboxes.forEach(cb=>cb.checked = e.target.checked);
    syncBtns();
  });

  /* --- HYVÄKSY --- */
  approveBtn?.addEventListener('click', ()=>{
    syncBtns(); openModal('confirm-approve-modal');
  });
  document.getElementById('confirm-approve-yes')?.addEventListener('click', ()=>{
    form.insertAdjacentHTML('beforeend','<input type="hidden" name="bulk_approve_submit" value="1">');
    showLoading(); form.submit();
  });
  document.getElementById('confirm-approve-only')?.addEventListener('click', ()=>{
    if(!getSelectedCount()){ alert('Valitse ensin tilaukset.'); return; }
    form.insertAdjacentHTML('beforeend','<input type="hidden" name="bulk_approve_only_submit" value="1">');
    showLoading(); form.submit();
  });

  /* --- ARKISTOI --- */
  archiveBtn?.addEventListener('click', ()=>{
    if(!getSelectedCount()){ alert('Valitse ensin tilaukset.'); return; }
    syncBtns(); openModal('confirm-archive-modal');
  });
  document.getElementById('confirm-archive-yes')?.addEventListener('click', ()=>{
    form.insertAdjacentHTML('beforeend','<input type="hidden" name="bulk_archive_submit" value="1">');
    showLoading(); form.submit();
  });

  /* --- OSTOSKORIIN --- */
  toCartBtn?.addEventListener('click', ()=>{
    if(!getSelectedCount()){ alert('Valitse ensin tilaukset.'); return; }
    syncBtns(); openModal('confirm-to-cart-modal');
  });
  document.getElementById('confirm-to-cart-yes')?.addEventListener('click', ()=>{
    form.insertAdjacentHTML('beforeend','<input type="hidden" name="bulk_to_cart_submit" value="1">');
    showLoading(); form.submit();
  });

  /* --- PALAUTA / HYLKÄÄ --- */
  returnBtn?.addEventListener('click', ()=>{
    syncBtns(); openModal('confirm-return-modal');
  });
  document.getElementById('confirm-return-yes')?.addEventListener('click', ()=>{
    const msg = document.getElementById('return-message').value.trim();
    if(!msg){ alert('Kirjoita palautteen syy.'); return; }
    form.insertAdjacentHTML('beforeend',
      '<input type="hidden" name="bulk_return_submit" value="1">'+
      `<input type="hidden" name="manager_message" value="${msg.replace(/"/g,'&quot;')}">`);
    showLoading(); form.submit();
  });

  /* --- PDF --- */
  pdfBtn?.addEventListener('click', e=>{
    e.preventDefault();
    if(!getSelectedCount()){ alert('Valitse ensin tilaukset.'); return; }
    const ids=[...checkboxes].filter(cb=>cb.checked).map(cb=>cb.value).join(',');
    const url=new URL('<?php echo admin_url('admin-ajax.php'); ?>');
    url.searchParams.set('action','pdf_export');
    url.searchParams.set('order_ids',ids);
    window.open(url,'_blank');
  });

  /* --- success-viesti pois 5 s jälkeen --- */
  setTimeout(()=>document.querySelector('.success-message')?.remove(), 5000);
});
/* --- Suodata-painike: vaihda teksti ja näytä spinner --- */
document.getElementById('tap-date-filter')?.addEventListener('submit', function () {
  const btn = this.querySelector('.filter-btn');
  if (!btn) return;
  const lbl = btn.querySelector('.btn-label');
if (lbl) lbl.textContent = '<?php echo esc_js( __( 'Please wait…', 'tapojarvi' ) ); ?>';
  btn.classList.add('loading');                // näyttää spinnerin
  btn.setAttribute('aria-busy', 'true');       // a11y
  btn.disabled = true;                         // estää tuplaklikkaukset
});
/* --- Tee koko date-field klikkautuvaksi --- */
document.querySelectorAll('.tap-date-filter .date-field').forEach(field => {
  field.addEventListener('click', e => {
    const input = field.querySelector('input[type="date"]');
    if (!input) return;

    /* Chrome / Edge 97+ */
    if (typeof input.showPicker === 'function') {
      input.showPicker();
    } else {
      /* Firefox, Safari, vanhemmat Edge‑versiot */
      input.focus();   // aseta kursori kenttään
      input.click();   // laukaise selaimen natiivi date‑picker
    }
  });
});
</script>

<!-- =================================================
     6. LOPPU
=====================================================-->