<?php
defined( 'ABSPATH' ) || exit;
require_once plugin_dir_path(__FILE__) . 'tcpdf/tcpdf.php';

// 1) Laajennetaan TCPDF luokkaa alamarginaalin lisäämiseksi
class PDF_With_Footer extends TCPDF {
    /** @var string */ public $tyomaa;
    /** @var string */ public $today;

    // Alamarginaali, joka piirtyy jokaiselle sivulle
    public function Footer() {
        $this->SetY(-12);
        $this->SetFont('dejavusans','',7);
        $this->SetTextColor(100,100,100);
        $left  = "Tapojärvi Store – Tilaukset: {$this->tyomaa}    Tulostuspäivä: {$this->today}";
        $this->SetX($this->lMargin);
        $this->Cell(100, 5, $left, 0, 0, 'L');
        $this->Cell(0, 5, 'Sivu '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, 0, 'R');
    }
}

// 2) Oikeustarkistus
if ( ! is_user_logged_in() || ! current_user_can( 'print_order_pdf' ) ) {
    wp_die( 'Sinulla ei ole oikeuksia tulostaa PDF:ää.' );
}

// 3) Haetaan order-id:t GET-parametrista
$order_ids = [];
if ( isset( $_GET['order_ids'] ) ) {
    $order_ids = array_map( 'absint', explode( ',', wp_unslash( $_GET['order_ids'] ) ) );
}
if ( empty( $order_ids ) ) {
    wp_die( 'Ei valittuja tilauksia PDF:ään.' );
}

// 4) Metatiedot (työmaatunniste & tulostuspäivä)
$order0 = wc_get_order( $order_ids[0] );
$tyomaa = $order0 ? esc_html( $order0->get_meta( 'tyomaa_koodi', true ) ) : '';
$today  = date_i18n( 'd.m.Y' );

// 5) Luodaan PDF-olio
$pdf = new PDF_With_Footer( 'P', 'mm', 'A4', true, 'UTF-8', false );
$pdf->setPrintHeader( false );
$pdf->setPrintFooter( true );
$pdf->tyomaa = $tyomaa;
$pdf->today  = $today;
$pdf->SetMargins( 12, 15, 12 );
$pdf->SetAutoPageBreak( true, 20 );
$pdf->AddPage();

// Tulostuspäivä ja tulostaja yläkulmassa
$current_user = wp_get_current_user();
$print_date   = date_i18n( 'd.m.Y' );
$pdf->SetFont( 'dejavusans', '', 7 );
$pdf->SetTextColor( 100, 100, 100 );
$pdf->Cell(
    0, 
    5, 
    'Tulostettu ' . $print_date . ' | Tulostanut: ' . esc_html( $current_user->user_email ), 
    0, 
    1, 
    'R'
);
$pdf->Ln( 2 );



// —————— Muutos: lasketaan taulukon leveys heti sivulle lisäyksen jälkeen ——————
$margins = $pdf->getMargins();
$tableW  = $pdf->getPageWidth() - $margins['left'] - $margins['right'];
// ————————————————————————————————————————————————————————————————

$pdf->setCellPaddings( 2, 2, 2, 2 );
$pdf->SetDrawColor( 200, 200, 200 );
$pdf->SetLineWidth( 0.2 );

// 8) Otsikko – vasempaan “Tapojärvi Store – Tilaukset”, oikealle työmaan nimi
$pdf->setCellPaddings(6, 2, 6, 2);
$pdf->SetFont('dejavusans','B',10);
$pdf->SetFillColor(255, 204, 0);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell($tableW * 0.6, 8, 'Tapojärvi Store – Tilaukset', 0, 0, 'L', true);
$pdf->Cell($tableW * 0.4, 8, $tyomaa,                         0, 1, 'R', true);
$pdf->setCellPaddings(2, 2, 2, 2);
$pdf->Ln(4);

// 9) Taulukon sarakkeet — käytetään samaa $tableW
$wDate  = $tableW * 0.08;  
$wName  = $tableW * 0.18; 
$wProds = $tableW * 0.47;  
$wNotes = $tableW * 0.27; 


// 10) Taulukon otsikkorivi (vain alareuna), tekstit vasempaan
$pdf->SetFont('dejavusans','B',7);
$pdf->SetTextColor(17,24,39);
$pdf->SetFillColor(243,244,246);
$pdf->Cell($wDate,  6, 'Pvm',       'B', 0, 'C', true);
$pdf->Cell($wName,  6, 'Tilaaja',   'B', 0, 'L', true);
$pdf->Cell($wProds, 6, 'Tuotteet',  'B', 0, 'L', true);
$pdf->Cell($wNotes, 6, 'Lisätiedot','B', 1, 'L', true);

// 11) Sisältörivit
$pdf->SetFont('dejavusans','',7);
$pdf->SetTextColor(0,0,0);
$fill = false;

foreach ( $order_ids as $oid ) {
    $order = wc_get_order( $oid );
    if ( ! $order ) continue;

    $raw_date = $order->get_date_created()->date_i18n('d.m.Y');
    list($d,$m,$y) = explode('.', $raw_date);
    $date_display = sprintf("%s.%s.\n%s", $d, $m, $y);

    $name     = trim($order->get_billing_first_name().' '.$order->get_billing_last_name());
$products = implode("\n", array_map(fn($i) => $i->get_name() . ' × ' . $i->get_quantity(), $order->get_items()));

    $notes    = $order->get_customer_note();

    $pdf->SetFillColor($fill?249:255,250,251);

    $lineH      = 5;
    $dateLines  = $pdf->getNumLines($date_display, $wDate);
    $nameLines  = $pdf->getNumLines($name,         $wName);
    $prodsLines = $pdf->getNumLines($products,     $wProds);
    $notesLines = $pdf->getNumLines($notes,        $wNotes);
    $maxLines   = max($dateLines,$nameLines,$prodsLines,$notesLines,1);
    $rowH       = $lineH * $maxLines;

    $startX = $pdf->GetX(); $startY = $pdf->GetY();
    $xDate  = $startX;
    $xName  = $xDate  + $wDate;
    $xProds = $xName  + $wName;
    $xNotes = $xProds + $wProds;

    $valign  = 'M'; $autoPad = true;

$pdf->SetXY($xDate, $startY);
$pdf->MultiCell($wDate, $rowH, $date_display, 0, 'C', $fill, 0, $xDate, $startY, true, 0, false, $autoPad, 0, $valign, false);

$pdf->SetXY($xName, $startY);
$pdf->MultiCell($wName, $rowH, $name, 0, 'L', $fill, 0, $xName, $startY, true, 0, false, $autoPad, 0, $valign, false);

$pdf->SetXY($xProds, $startY);
$pdf->MultiCell($wProds, $rowH, $products, 0, 'L', $fill, 0, $xProds, $startY, true, 0, false, $autoPad, 0, $valign, false);

// Piirrä harmaa viiva
$pdf->SetDrawColor(220, 220, 220);
$lineX = $xNotes + 1.0;
$lineY1 = $startY + 1.5;
$lineY2 = $startY + $rowH - 1.5;
$pdf->Line($lineX, $lineY1, $lineX, $lineY2);

// Aseta väri riville
$pdf->SetFillColor($fill ? 249 : 255, 250, 251);

// Piirrä notes-teksti viivan viereen
$textIndent = 2.0;
$pdf->SetXY($xNotes + $textIndent, $startY);
$pdf->MultiCell($wNotes - $textIndent, $rowH, $notes, 0, 'L', $fill, 0, $xNotes + $textIndent, $startY, true, 0, false, $autoPad, 0, $valign, false);


    $endY = $startY + $rowH;
    $pdf->Line($startX, $endY, $startX + $tableW, $endY);
    $pdf->SetY($endY);
    if ($pdf->GetY() > 260) $pdf->AddPage();
    $fill = !$fill;
}

// 12) Lähetetään PDF selaimeen
$pdf->Output('tilaukset_'.date('Y-m-d_H-i-s').'.pdf','I');
exit;
