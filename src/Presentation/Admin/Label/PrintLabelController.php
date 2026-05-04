<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Label;

use S7codedesign\DExpress\Application\Shipment\OrderRecipientResolver;
use S7codedesign\DExpress\Domain\Address\PhoneNumber;
use S7codedesign\DExpress\Domain\Money\Grams;
use S7codedesign\DExpress\Domain\Shipment\Package;
use S7codedesign\DExpress\Domain\Shipment\PaymentType;
use S7codedesign\DExpress\Domain\Shipment\ReturnDoc;
use S7codedesign\DExpress\Domain\Shipment\Shipment;
use S7codedesign\DExpress\Infrastructure\Barcode\Code128;
use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbSenderLocationRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbShipmentRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbTownRepository;

/**
 * Standalone HTML label print (no WP admin chrome). URL:
 * /wp-admin/admin.php?page=dexpress-label&shipment_id=X&nonce=Y&layout=2|6
 */
final class PrintLabelController
{
    private const CARRIER_LEGAL_LINE = 'D Express doo, Zage Malivuk 1, Beograd';

    public function __construct(
        private readonly WpdbShipmentRepository       $shipments,
        private readonly WpdbSenderLocationRepository $locations,
        private readonly WpdbTownRepository           $towns,
        private readonly Code128                      $barcode,
        private readonly OrderRecipientResolver       $recipientResolver,
        private readonly Logger                       $logger,
    ) {}

    public function register(): void
    {
        add_action('admin_init', [$this, 'maybeServeStandalone'], 0);
        add_action('admin_menu', [$this, 'registerPage']);
        add_action('admin_init', [$this, 'ensureAdminPageTitleIsString'], 1);
    }

    /**
     * Output full document before admin menu/header so štampa ne hvata sidebar.
     */
    public function maybeServeStandalone(): void
    {
        if (!is_admin()) {
            return;
        }

        if (($GLOBALS['pagenow'] ?? '') !== 'admin.php') {
            return;
        }

        if (($_GET['page'] ?? '') !== 'dexpress-label') {
            return;
        }

        $this->render();
    }

    public function registerPage(): void
    {
        $pageTitle = (string) __('D Express Nalepnica', 'dexpress-woocommerce');

        add_submenu_page(
            '',
            $pageTitle,
            $pageTitle,
            'manage_woocommerce',
            'dexpress-label',
            [$this, 'noopRender'],
        );
    }

    /** @phpstan-ignore-next-line Empty stub — real output happens in maybeServeStandalone(). */
    public function noopRender(): void
    {
        echo '<p>' . esc_html__('Stranica nalepnice se učitava posebnim prikazom. Koristite ponovo link za štampu.', 'dexpress-woocommerce') . '</p>';
    }

    public function ensureAdminPageTitleIsString(): void
    {
        if (($GLOBALS['pagenow'] ?? '') !== 'admin.php') {
            return;
        }

        if (($_GET['page'] ?? '') !== 'dexpress-label') {
            return;
        }

        global $title;

        if (!isset($title) || $title === null || $title === '') {
            $title = (string) __('D Express Nalepnica', 'dexpress-woocommerce');
        } else {
            $title = (string) $title;
        }
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Nemate dovoljna prava.', 'dexpress-woocommerce'), '', ['response' => 403]);
        }

        $shipmentId = (int) ($_GET['shipment_id'] ?? 0);
        $nonce      = sanitize_text_field($_GET['nonce'] ?? '');
        $layoutRaw  = (string) ($_GET['layout'] ?? '2');
        $perSheet   = $layoutRaw === '6' ? 6 : 2;

        if ($shipmentId <= 0 || !wp_verify_nonce($nonce, 'dexpress_print_label_' . $shipmentId)) {
            wp_die(__('Nevažeći zahtev.', 'dexpress-woocommerce'), '', ['response' => 403]);
        }

        $shipment = $this->shipments->findById($shipmentId);

        if ($shipment === null) {
            wp_die(__('Pošiljka nije pronađena.', 'dexpress-woocommerce'), '', ['response' => 404]);
        }

        $location = $this->locations->findById($shipment->senderLocationId);
        $order    = wc_get_order($shipment->orderId);

        if (!$order instanceof \WC_Order) {
            wp_die(__('Narudžbina nije pronađena.', 'dexpress-woocommerce'), '', ['response' => 404]);
        }

        $packages = $shipment->packages;
        if ($packages === []) {
            wp_die(__('Nema paketa za štampu.', 'dexpress-woocommerce'), '', ['response' => 404]);
        }

        $recipient = $this->recipientResolver->resolve($order);
        $townRow   = ((int) $recipient['town_id']) > 0
            ? $this->towns->findPostalDisplay((int) $recipient['town_id'])
            : null;

        $rName   = $recipient['name'];
        $rStreet = trim($recipient['street'] . ' ' . $recipient['street_number']);
        $rDesc   = $recipient['address_desc'];
        $rPhone  = $this->formatPhoneForLabel(
            $recipient['phone'] !== '' ? $recipient['phone'] : (string) $order->get_billing_phone(),
        );

        $recipientCityLine = '';
        if ($townRow !== null) {
            $pc = $townRow['postal_code'];
            $recipientCityLine = ($pc !== null && $pc > 0 ? (string) $pc . ' ' : '')
                . $townRow['display_name'];
        }
        if ($recipientCityLine === '') {
            $recipientCityLine = trim((string) $recipient['city']);
        }

        $senderTownId = (int) ($location['town_id'] ?? 0);
        $senderTown   = $senderTownId > 0 ? $this->towns->findPostalDisplay($senderTownId) : null;
        $sName        = (string) ($location['name'] ?? '');
        $sStreet      = trim((string) ($location['street_name'] ?? '') . ' ' . (string) ($location['street_number'] ?? ''));
        $sCityLine    = '';
        if ($senderTown !== null) {
            $pc = $senderTown['postal_code'];
            $sCityLine = ($pc !== null && $pc > 0 ? (string) $pc . ' ' : '')
                . $senderTown['display_name'];
        }

        $sPhone = $this->formatPhoneForLabel((string) ($location['contact_phone'] ?? ''));

        $hasCod = !$shipment->codAmount->isZero();
        $codRsd = number_format($shipment->codAmount->toRsd(), 2, ',', '.') . ' RSD';

        $referenceDisplay = $shipment->referenceId !== ''
            ? $shipment->referenceId
            : (string) $order->get_order_number();

        $paymentLine   = $this->paymentLineLabel($shipment);
        $returnDocLine = $this->returnDocShortLabel($shipment->returnDoc);

        $printTime = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Belgrade')))
            ->format('d.m.Y H:i');

        $compact = $perSheet === 6;
        $bw      = $compact ? 1 : 2;
        $bh      = $compact ? 44 : 56;

        $packageLabels = [];
        foreach ($packages as $pkg) {
            $code = $pkg->code->value();
            try {
                $barcodeSvg = $this->barcode->svg($code, $bw, $bh);
            } catch (\Throwable $e) {
                $this->logger->error(
                    '[LABEL] Barcode SVG failed for package ' . $code . ' shipment ' . $shipmentId . ': ' . $e->getMessage(),
                );
                $barcodeSvg = '<p style="text-align:center;font-weight:bold;">' . esc_html($code) . '</p>';
            }

            $pkgContentNote = ($pkg->contentNote !== null && $pkg->contentNote !== '') ? $pkg->contentNote : '';

            $packageLabels[] = [
                'code'           => $code,
                'svg'            => $barcodeSvg,
                'ordinal'        => $pkg->ordinal,
                'massKg'         => $this->massKgLabel($pkg->mass),
                'contentNote'    => $pkgContentNote,
            ];
        }

        $totalPkgs = count($packageLabels);
        $docTitle  = $totalPkgs > 1
            ? sprintf(__('Nalepnice — porudžbina #%s', 'dexpress-woocommerce'), $order->get_order_number())
            : $packageLabels[0]['code'];

        $basePrintUrl = add_query_arg(
            [
                'page'        => 'dexpress-label',
                'shipment_id' => $shipmentId,
                'nonce'       => $nonce,
            ],
            admin_url('admin.php'),
        );

        $this->outputHtml(
            docTitle:           $docTitle,
            packageLabels:      $packageLabels,
            shipmentContent:    $shipment->content,
            shipmentNote:       $shipment->note,
            printTime:          $printTime,
            referenceDisplay:   $referenceDisplay,
            paymentLine:        $paymentLine,
            returnDocLine:      $returnDocLine,
            codLine:            $codRsd,
            hasCod:             $hasCod,
            rName:              $rName,
            rStreet:            $rStreet,
            rDesc:              $rDesc,
            rCityLine:          $recipientCityLine,
            rPhone:             $rPhone,
            sName:              $sName,
            sStreet:            $sStreet,
            sCityLine:          $sCityLine,
            sPhone:             $sPhone,
            perSheet:           $perSheet,
            urlLayout2:         add_query_arg('layout', '2', $basePrintUrl),
            urlLayout6:         add_query_arg('layout', '6', $basePrintUrl),
            compact:            $compact,
        );
    }

    private function paymentLineLabel(Shipment $shipment): string
    {
        $by = $shipment->paymentBy->label();
        $how = match ($shipment->paymentType) {
            PaymentType::Invoice => __('virman', 'dexpress-woocommerce'),
            PaymentType::Cash    => __('gotovina', 'dexpress-woocommerce'),
        };

        return $by . ' — ' . $how;
    }

    private function returnDocShortLabel(ReturnDoc $doc): string
    {
        return match ($doc) {
            ReturnDoc::None      => '—',
            ReturnDoc::Documents => __('Povraćaj dokumenata', 'dexpress-woocommerce'),
            ReturnDoc::Pod       => __('Potvrda o isporuci (POD)', 'dexpress-woocommerce'),
        };
    }

    private function massKgLabel(?Grams $mass): string
    {
        if ($mass === null) {
            return '—';
        }

        $kg = $mass->value() / 1000;

        return number_format($kg, 1, ',', '') . ' kg';
    }

    private function formatPhoneForLabel(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        try {
            $canonical = PhoneNumber::fromString($raw)->canonical();
            $rest      = substr($canonical, 3);
            if (strlen($rest) === 9) {
                return sprintf(
                    '0%s/%s-%s',
                    substr($rest, 0, 2),
                    substr($rest, 2, 3),
                    substr($rest, 5),
                );
            }

            return PhoneNumber::fromString($raw)->forDisplay();
        } catch (\Throwable) {
            return $raw;
        }
    }

    /**
     * @param list<array{code: string, svg: string, ordinal: int, massKg: string, contentNote: string}> $packageLabels
     */
    private function outputHtml(
        string $docTitle,
        array $packageLabels,
        string $shipmentContent,
        string $shipmentNote,
        string $printTime,
        string $referenceDisplay,
        string $paymentLine,
        string $returnDocLine,
        string $codLine,
        bool $hasCod,
        string $rName,
        string $rStreet,
        string $rDesc,
        string $rCityLine,
        string $rPhone,
        string $sName,
        string $sStreet,
        string $sCityLine,
        string $sPhone,
        int $perSheet,
        string $urlLayout2,
        string $urlLayout6,
        bool $compact,
    ): void {
        $layoutClass = $perSheet === 6 ? 'sheet--6' : 'sheet--2';
        $innerClass  = $compact ? 'label-card label-card--compact' : 'label-card';

        ?>
<!DOCTYPE html>
<html lang="sr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html($docTitle); ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: Arial, Helvetica, sans-serif;
    background: #e8e8e8;
    color: #000;
  }

  .toolbar.no-print {
    padding: 12px;
    background: #1d2327;
    color: #fff;
    text-align: center;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: center;
    align-items: center;
  }

  .toolbar.no-print a,
  .toolbar.no-print button {
    color: #fff;
    background: #2271b1;
    border: none;
    padding: 8px 14px;
    cursor: pointer;
    font-size: 13px;
    text-decoration: none;
    display: inline-block;
  }

  .toolbar.no-print a.secondary,
  .toolbar.no-print button.secondary {
    background: #50575e;
  }

  .toolbar.no-print .active-layout {
    outline: 2px solid #fff;
  }

  .sheet {
    width: 210mm;
    min-height: 297mm;
    margin: 0 auto;
    background: #fff;
    padding: 6mm;
    display: grid;
    gap: 5mm;
  }

  .sheet--2 {
    grid-template-columns: 1fr;
    grid-template-rows: repeat(2, 1fr);
  }

  .sheet--6 {
    grid-template-columns: 1fr 1fr;
    grid-template-rows: repeat(3, 1fr);
  }

  .label-card {
    border: 1.2pt solid #000;
    padding: 3mm;
    display: flex;
    flex-direction: column;
    page-break-inside: avoid;
    background: #fff;
    min-height: 0;
  }

  .sheet--2 .label-card {
    min-height: 132mm;
  }

  .sheet--6 .label-card {
    min-height: 88mm;
  }

  .carrier-line {
    text-align: center;
    font-size: 8.5pt;
    font-weight: 700;
    border-bottom: 1pt solid #000;
    padding-bottom: 1.5mm;
    margin-bottom: 2mm;
  }

  .top-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 3mm;
    margin-bottom: 2mm;
  }

  .sender-block {
    font-size: 7.5pt;
    line-height: 1.25;
    max-width: 62%;
    color: #000;
  }

  .sender-block strong {
    font-size: 7.5pt;
  }

  .pkg-count {
    font-size: 22pt;
    font-weight: 800;
    line-height: 1;
    flex-shrink: 0;
  }

  .label-card--compact .pkg-count {
    font-size: 16pt;
  }

  .barcode-wrap {
    text-align: center;
    margin: 1mm 0;
  }

  .barcode-wrap svg {
    max-width: 100%;
    height: auto;
    display: block;
    margin: 0 auto;
  }

  .human-code {
    text-align: center;
    font-size: 13pt;
    font-weight: 800;
    letter-spacing: 0.12em;
    margin-bottom: 2mm;
  }

  .label-card--compact .human-code {
    font-size: 10pt;
  }

  .recipient-block {
    border-top: 1pt solid #000;
    border-bottom: 1pt solid #000;
    padding: 2mm 0;
    margin-bottom: 2mm;
  }

  .recipient-block .tag {
    font-size: 9pt;
    font-weight: 700;
    margin-bottom: 1mm;
  }

  .recipient-block .r-name {
    font-size: 15pt;
    font-weight: 800;
    line-height: 1.15;
    margin-bottom: 1mm;
  }

  .label-card--compact .recipient-block .r-name {
    font-size: 11pt;
  }

  .recipient-block .r-line {
    font-size: 12pt;
    font-weight: 700;
    line-height: 1.2;
  }

  .label-card--compact .recipient-block .r-line {
    font-size: 9pt;
  }

  .recipient-block .r-phone {
    font-size: 11pt;
    font-weight: 700;
    margin-top: 1mm;
  }

  .label-card--compact .recipient-block .r-phone {
    font-size: 9pt;
  }

  .details-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1mm 4mm;
    font-size: 8pt;
    line-height: 1.35;
    flex: 1;
  }

  .label-card--compact .details-grid {
    font-size: 6.5pt;
    gap: 0.5mm 2mm;
  }

  .details-grid .full {
    grid-column: 1 / -1;
  }

  .details-grid dt {
    font-weight: 700;
    margin: 0;
  }

  .details-grid dd {
    margin: 0 0 1mm 0;
  }

  .note-print {
    font-size: 7.5pt;
    margin-top: 2mm;
    padding-top: 1mm;
    border-top: 0.5pt dashed #000;
  }

  .label-card--compact .note-print {
    font-size: 6.5pt;
  }

  .print-time {
    text-align: center;
    font-size: 7pt;
    margin-top: auto;
    padding-top: 2mm;
  }

  .label-card--compact .print-time {
    font-size: 6pt;
  }

  @media print {
    body { background: #fff; }
    .no-print { display: none !important; }
    .sheet {
      margin: 0;
      padding: 4mm;
      width: 210mm;
      min-height: 297mm;
      page-break-after: always;
    }
    .sheet:last-child { page-break-after: auto; }
    @page {
      size: A4 portrait;
      margin: 0;
    }
  }
</style>
</head>
<body>

<div class="toolbar no-print">
  <button type="button" onclick="window.print()"><?php esc_html_e('Štampaj', 'dexpress-woocommerce'); ?></button>
  <button type="button" class="secondary" onclick="window.close()"><?php esc_html_e('Zatvori', 'dexpress-woocommerce'); ?></button>
  <a href="<?php echo esc_url($urlLayout2); ?>" class="<?php echo $perSheet === 2 ? 'active-layout' : ''; ?>"><?php esc_html_e('A4 — 2 nalepnice', 'dexpress-woocommerce'); ?></a>
  <a href="<?php echo esc_url($urlLayout6); ?>" class="secondary <?php echo $perSheet === 6 ? 'active-layout' : ''; ?>"><?php esc_html_e('A4 — 6 nalepnica', 'dexpress-woocommerce'); ?></a>
</div>

        <?php
        $totalLabels = count($packageLabels);
        $chunks      = array_chunk($packageLabels, $perSheet);

        foreach ($chunks as $chunk) {
            echo '<div class="sheet ' . esc_attr($layoutClass) . '">';
            foreach ($chunk as $pl) {
                $idx    = (int) $pl['ordinal'];
                $frac   = (string) $idx . '/' . (string) $totalLabels;
                $noteTx = trim($shipmentNote . ($pl['contentNote'] !== '' ? ($shipmentNote !== '' ? ' · ' : '') . $pl['contentNote'] : ''));
                ?>
<div class="<?php echo esc_attr($innerClass); ?>">
  <div class="carrier-line"><?php echo esc_html(self::CARRIER_LEGAL_LINE); ?></div>
  <div class="top-row">
    <div class="sender-block">
      <strong><?php esc_html_e('Pošiljalac:', 'dexpress-woocommerce'); ?></strong><br>
      <?php echo esc_html($sName); ?><br>
      <?php echo esc_html(trim($sStreet . ($sCityLine !== '' ? ', ' . $sCityLine : ''))); ?>
            <?php if ($sPhone !== '') : ?>
      <br><?php echo esc_html($sPhone); ?>
            <?php endif; ?>
    </div>
    <div class="pkg-count"><?php echo esc_html($frac); ?></div>
  </div>
  <div class="barcode-wrap"><?php echo $pl['svg']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
  <div class="human-code"><?php echo esc_html($pl['code']); ?></div>
  <div class="recipient-block">
    <div class="tag"><?php esc_html_e('Primalac:', 'dexpress-woocommerce'); ?></div>
    <div class="r-name"><?php echo esc_html($rName); ?></div>
    <div class="r-line"><?php echo esc_html($rStreet); ?><?php echo $rDesc !== '' ? ' · ' . esc_html($rDesc) : ''; ?></div>
            <?php if ($rCityLine !== '') : ?>
    <div class="r-line"><?php echo esc_html($rCityLine); ?></div>
            <?php endif; ?>
            <?php if ($rPhone !== '') : ?>
    <div class="r-phone"><?php echo esc_html($rPhone); ?></div>
            <?php endif; ?>
  </div>
  <dl class="details-grid">
    <div><dt><?php esc_html_e('Referentni broj', 'dexpress-woocommerce'); ?></dt><dd><?php echo esc_html($referenceDisplay); ?></dd></div>
    <div><dt><?php esc_html_e('Uslugu plaća', 'dexpress-woocommerce'); ?></dt><dd><?php echo esc_html($paymentLine); ?></dd></div>
    <div><dt><?php esc_html_e('Povratna dokumentacija', 'dexpress-woocommerce'); ?></dt><dd><?php echo esc_html($returnDocLine); ?></dd></div>
    <div><dt><?php esc_html_e('Otkupnina', 'dexpress-woocommerce'); ?></dt><dd><?php echo esc_html($hasCod ? $codLine : '0,00 RSD'); ?></dd></div>
    <div class="full"><dt><?php esc_html_e('Sadržaj', 'dexpress-woocommerce'); ?></dt><dd><?php echo esc_html($shipmentContent !== '' ? $shipmentContent : '—'); ?></dd></div>
    <div><dt><?php esc_html_e('Masa', 'dexpress-woocommerce'); ?></dt><dd><?php echo esc_html($pl['massKg']); ?></dd></div>
  </dl>
            <?php if ($noteTx !== '') : ?>
  <div class="note-print"><strong><?php esc_html_e('Napomena', 'dexpress-woocommerce'); ?>:</strong> <?php echo esc_html($noteTx); ?></div>
            <?php endif; ?>
  <div class="print-time"><?php esc_html_e('vreme štampe:', 'dexpress-woocommerce'); ?> <?php echo esc_html($printTime); ?></div>
</div>
                <?php
            }
            echo '</div>';
        }
        ?>

</body>
</html>
        <?php
        exit;
    }
}
