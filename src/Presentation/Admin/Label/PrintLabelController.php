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
 * /wp-admin/admin.php?page=dexpress-label&shipment_id=X&nonce=Y&layout=1|2|4
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

    // Grupna štampa: shipment_ids=1,2,3 + nonce za dexpress_bulk_print
    if (isset($_GET['shipment_ids'])) {
      $this->renderMultiple();
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
    // Backward compatibility: old "6-up" layout maps to the new 4-up A4 grid.
    if ($layoutRaw === '6') {
      $layoutRaw = '4';
    }
    $perSheet = in_array($layoutRaw, ['1', '2', '4'], true)
      ? (int) $layoutRaw
      : 2;

    if ($shipmentId <= 0 || !wp_verify_nonce($nonce, 'dexpress_print_label_' . $shipmentId)) {
      wp_die(__('Nevažeći zahtev.', 'dexpress-woocommerce'), '', ['response' => 403]);
    }

    $d = $this->buildLabelData($shipmentId, $perSheet);

    if ($d === null) {
      wp_die(__('Pošiljka, narudžbina ili paketi nisu pronađeni.', 'dexpress-woocommerce'), '', ['response' => 404]);
    }

    $totalPkgs = count($d['packageLabels']);
    $docTitle  = $totalPkgs > 1
      ? sprintf(__('Nalepnice — porudžbina #%s', 'dexpress-woocommerce'), $d['orderNumber'])
      : $d['packageLabels'][0]['code'];

    $basePrintUrl = add_query_arg(
      [
        'page'        => 'dexpress-label',
        'shipment_id' => $shipmentId,
        'nonce'       => $nonce,
      ],
      admin_url('admin.php'),
    );

    $this->outputHtml(
      docTitle: $docTitle,
      packageLabels: $d['packageLabels'],
      shipmentContent: $d['shipmentContent'],
      shipmentNote: $d['shipmentNote'],
      printTime: $d['printTime'],
      referenceDisplay: $d['referenceDisplay'],
      paymentLine: $d['paymentLine'],
      returnDocLine: $d['returnDocLine'],
      codLine: $d['codLine'],
      hasCod: $d['hasCod'],
      recipientPrimary: $d['recipientPrimary'],
      recipientStreet: $d['recipientStreet'],
      recipientDesc: $d['recipientDesc'],
      recipientCityLine: $d['recipientCityLine'],
      rPhone: $d['rPhone'],
      recipientSecondary: $d['recipientSecondary'],
      packageShopBadge: $d['packageShopBadge'],
      sName: $d['sName'],
      sStreet: $d['sStreet'],
      sCityLine: $d['sCityLine'],
      sPhone: $d['sPhone'],
      perSheet: $perSheet,
      urlLayout1: add_query_arg('layout', '1', $basePrintUrl),
      urlLayout2: add_query_arg('layout', '2', $basePrintUrl),
      urlLayout4: add_query_arg('layout', '4', $basePrintUrl),
      compact: $d['compact'],
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
   * Gradi sve podatke potrebne za prikaz nalepnice jedne pošiljke.
   * Vraća null ako pošiljka ili narudžbina ne postoje ili nema paketa.
   *
   * @param int $perSheet  Broj nalepnica po stranici (1, 2 ili 4)
   * @return array<string, mixed>|null
   */
  private function buildLabelData(int $shipmentId, int $perSheet): ?array
  {
    $shipment = $this->shipments->findById($shipmentId);
    if ($shipment === null) {
      return null;
    }

    $location = $this->locations->findById($shipment->senderLocationId);
    $order    = wc_get_order($shipment->orderId);

    if (!$order instanceof \WC_Order) {
      return null;
    }

    $packages = $shipment->packages;
    if ($packages === []) {
      return null;
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

    $customerContactName = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    if ($customerContactName === '') {
      $customerContactName = $rName;
    }
    $customerContactPhone = $this->formatPhoneForLabel((string) $order->get_billing_phone());
    if ($customerContactPhone === '') {
      $customerContactPhone = $rPhone;
    }

    $recipientCityLine = '';
    if ($townRow !== null) {
      $pc = $townRow['postal_code'];
      $recipientCityLine = ($pc !== null && $pc > 0 ? (string) $pc . ' ' : '') . $townRow['display_name'];
    }
    if ($recipientCityLine === '') {
      $recipientCityLine = trim((string) $recipient['city']);
    }

    $isPackageShop             = trim((string) $order->get_meta('_dexpress_package_shop_location_id')) !== '';
    $packageShopType           = trim((string) $order->get_meta('_dexpress_package_shop_location_type'));
    $packageShopTypeLabel      = trim((string) $order->get_meta('_dexpress_package_shop_location_type_label'));
    $packageShopLocationName   = trim((string) $order->get_meta('_dexpress_package_shop_location_name'));
    $packageShopLocationAddress = trim((string) $order->get_meta('_dexpress_package_shop_location_address'));
    $packageShopLocationCity   = trim((string) $order->get_meta('_dexpress_package_shop_location_city'));

    $recipientBlockTitle    = $rName;
    $recipientBlockStreet   = $rStreet;
    $recipientBlockCityLine = $recipientCityLine;
    $recipientBlockDesc     = $rDesc;
    $recipientSecondaryLine = '';
    $packageShopBadge       = '';

    if ($isPackageShop) {
      $recipientBlockTitle    = $packageShopLocationName !== '' ? $packageShopLocationName : __('Paket Shop lokacija', 'dexpress-woocommerce');
      $recipientBlockStreet   = $packageShopLocationAddress !== '' ? $packageShopLocationAddress : $rStreet;
      $recipientBlockCityLine = $packageShopLocationCity !== '' ? $packageShopLocationCity : $recipientCityLine;
      $recipientBlockDesc     = '';

      $customerParts = [];
      if ($customerContactName !== '') {
        $customerParts[] = $customerContactName;
      }
      if ($customerContactPhone !== '') {
        $customerParts[] = $customerContactPhone;
      }
      if ($customerParts !== []) {
        $recipientSecondaryLine = implode(' · ', $customerParts);
      }

      $isPaketomat      = $packageShopType === '2' || stripos($packageShopTypeLabel, 'paketomat') !== false;
      $packageShopBadge = $isPaketomat ? '📦 PAKETOMAT' : '📦 PAKET SHOP';
    }

    $senderTownId = (int) ($location['town_id'] ?? 0);
    $senderTown   = $senderTownId > 0 ? $this->towns->findPostalDisplay($senderTownId) : null;
    $sName        = (string) ($location['name'] ?? '');
    $sStreet      = trim((string) ($location['street_name'] ?? '') . ' ' . (string) ($location['street_number'] ?? ''));
    $sCityLine    = '';
    if ($senderTown !== null) {
      $pc = $senderTown['postal_code'];
      $sCityLine = ($pc !== null && $pc > 0 ? (string) $pc . ' ' : '') . $senderTown['display_name'];
    }

    $sPhone = $this->formatPhoneForLabel((string) ($location['contact_phone'] ?? ''));

    $hasCod = !$shipment->codAmount->isZero();
    $codRsd = number_format($shipment->codAmount->toRsd(), 2, ',', '.') . ' RSD';

    $referenceDisplay = $shipment->referenceId !== ''
      ? $shipment->referenceId
      : (string) $order->get_order_number();

    $compact = $perSheet >= 4;
    $bw      = $perSheet >= 4 ? 1 : 2;
    $bh      = $perSheet === 1 ? 70 : ($perSheet === 2 ? 58 : 42);

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
        'code'        => $code,
        'svg'         => $barcodeSvg,
        'ordinal'     => $pkg->ordinal,
        'massKg'      => $this->massKgLabel($pkg->mass),
        'contentNote' => $pkgContentNote,
      ];
    }

    return [
      'packageLabels'      => $packageLabels,
      'shipmentContent'    => $shipment->content,
      'shipmentNote'       => $shipment->note,
      'printTime'          => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Belgrade')))->format('d.m.Y H:i'),
      'referenceDisplay'   => $referenceDisplay,
      'paymentLine'        => $this->paymentLineLabel($shipment),
      'returnDocLine'      => $this->returnDocShortLabel($shipment->returnDoc),
      'codLine'            => $codRsd,
      'hasCod'             => $hasCod,
      'recipientPrimary'   => $recipientBlockTitle,
      'recipientStreet'    => $recipientBlockStreet,
      'recipientDesc'      => $recipientBlockDesc,
      'recipientCityLine'  => $recipientBlockCityLine,
      'rPhone'             => $rPhone,
      'recipientSecondary' => $recipientSecondaryLine,
      'packageShopBadge'   => $packageShopBadge,
      'sName'              => $sName,
      'sStreet'            => $sStreet,
      'sCityLine'          => $sCityLine,
      'sPhone'             => $sPhone,
      'perSheet'           => $perSheet,
      'compact'            => $compact,
      'orderNumber'        => $order->get_order_number(),
    ];
  }

  /**
   * Ispisuje <div class="sheet"> blokove za jednu pošiljku (bez HTML omotača).
   * Koriste ga i outputHtml() i renderMultiple().
   *
   * @param array<string, mixed> $d  Podaci koje vraća buildLabelData()
   */
  private function printSheets(array $d): void
  {
    $perSheet   = (int) $d['perSheet'];
    $compact    = (bool) $d['compact'];
    $layoutClass = match ($perSheet) {
      1       => 'sheet--1',
      4       => 'sheet--4',
      6       => 'sheet--6',
      default => 'sheet--2',
    };
    $innerClass = $compact ? 'label-card label-card--compact' : 'label-card';

    /** @var list<array{code:string,svg:string,ordinal:int,massKg:string,contentNote:string}> $packageLabels */
    $packageLabels  = $d['packageLabels'];
    $totalLabels    = count($packageLabels);
    $shipmentContent = (string) $d['shipmentContent'];
    $shipmentNote    = (string) $d['shipmentNote'];
    $printTime       = (string) $d['printTime'];
    $referenceDisplay = (string) $d['referenceDisplay'];
    $paymentLine      = (string) $d['paymentLine'];
    $returnDocLine    = (string) $d['returnDocLine'];
    $codLine          = (string) $d['codLine'];
    $hasCod           = (bool) $d['hasCod'];
    $recipientPrimary  = (string) $d['recipientPrimary'];
    $recipientStreet   = (string) $d['recipientStreet'];
    $recipientDesc     = (string) $d['recipientDesc'];
    $recipientCityLine = (string) $d['recipientCityLine'];
    $rPhone            = (string) $d['rPhone'];
    $recipientSecondary = (string) $d['recipientSecondary'];
    $packageShopBadge  = (string) $d['packageShopBadge'];
    $sName             = (string) $d['sName'];
    $sStreet           = (string) $d['sStreet'];
    $sCityLine         = (string) $d['sCityLine'];
    $sPhone            = (string) $d['sPhone'];

    $chunks = array_chunk($packageLabels, $perSheet);

    foreach ($chunks as $chunk) {
      echo '<div class="sheet ' . esc_attr($layoutClass) . '">';
      foreach ($chunk as $pl) {
        $idx    = (int) $pl['ordinal'];
        $frac   = (string) $idx . '/' . (string) $totalLabels;
        $noteTx = trim($shipmentNote . ($pl['contentNote'] !== '' ? ($shipmentNote !== '' ? ' · ' : '') . $pl['contentNote'] : ''));
        ?>
        <div class="<?php echo esc_attr($innerClass); ?>">
          <div class="zone zone-0"><?php echo esc_html(self::CARRIER_LEGAL_LINE); ?></div>
          <div class="zone zone-1">
            <div class="carrier-block">
              <div class="sender-block">
                <strong><?php esc_html_e('Pošiljalac:', 'dexpress-woocommerce'); ?></strong><br>
                <?php echo esc_html($sName); ?><br>
                <?php echo esc_html(trim($sStreet . ($sCityLine !== '' ? ', ' . $sCityLine : ''))); ?>
                <?php if ($sPhone !== '') : ?>
                  <br><?php echo esc_html($sPhone); ?>
                <?php endif; ?>
              </div>
            </div>
            <div class="pkg-counter"><?php echo esc_html($frac); ?></div>
          </div>

          <div class="zone zone-3">
            <div class="barcode-wrap"><?php echo $pl['svg']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            ?></div>
          </div>

          <div class="zone zone-4">
            <div class="tracking-code"><?php echo esc_html($pl['code']); ?></div>
          </div>

          <div class="zone zone-5">
            <div class="recipient-label"><?php esc_html_e('Primalac:', 'dexpress-woocommerce'); ?></div>
            <div class="recipient-primary"><?php echo esc_html($recipientPrimary); ?></div>
            <div class="recipient-line">
              <?php echo esc_html($recipientStreet); ?>
              <?php echo $recipientDesc !== '' ? ' · ' . esc_html($recipientDesc) : ''; ?>
            </div>
            <?php if ($recipientCityLine !== '') : ?>
              <div class="recipient-line"><?php echo esc_html($recipientCityLine); ?></div>
            <?php endif; ?>
            <?php if ($rPhone !== '' && $packageShopBadge === '') : ?>
              <div class="recipient-phone"><?php echo esc_html($rPhone); ?></div>
            <?php endif; ?>
            <?php if ($packageShopBadge !== '') : ?>
              <div class="package-shop-badge"><?php echo esc_html($packageShopBadge); ?></div>
            <?php endif; ?>
            <?php if ($recipientSecondary !== '') : ?>
              <div class="recipient-secondary">
                <?php esc_html_e('Kontakt primaoca (za notifikacije):', 'dexpress-woocommerce'); ?>
                <?php echo esc_html($recipientSecondary); ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="zone zone-6">
            <div class="info-grid">
              <div class="info-cell">
                <div class="info-label"><?php esc_html_e('Referentni broj', 'dexpress-woocommerce'); ?></div>
                <div class="info-value"><?php echo esc_html($referenceDisplay); ?></div>
              </div>
              <div class="info-cell">
                <div class="info-label"><?php esc_html_e('Uslugu plaća', 'dexpress-woocommerce'); ?></div>
                <div class="info-value"><?php echo esc_html($paymentLine); ?></div>
              </div>
              <div class="info-cell">
                <div class="info-label"><?php esc_html_e('Povratna dokumentacija', 'dexpress-woocommerce'); ?></div>
                <div class="info-value"><?php echo esc_html($returnDocLine); ?></div>
              </div>
              <div class="info-cell">
                <div class="info-label"><?php esc_html_e('Otkupnina', 'dexpress-woocommerce'); ?></div>
                <div class="info-value"><?php echo esc_html($hasCod ? $codLine : '0,00 RSD'); ?></div>
              </div>
              <div class="info-cell full">
                <div class="info-label"><?php esc_html_e('Sadržaj', 'dexpress-woocommerce'); ?></div>
                <div class="info-value"><?php echo esc_html($shipmentContent !== '' ? $shipmentContent : '—'); ?></div>
              </div>
              <div class="info-cell">
                <div class="info-label"><?php esc_html_e('Masa', 'dexpress-woocommerce'); ?></div>
                <div class="info-value"><?php echo esc_html($pl['massKg']); ?></div>
              </div>
              <div class="info-cell full">
                <div class="info-label"><?php esc_html_e('Napomena', 'dexpress-woocommerce'); ?></div>
                <div class="info-value"><?php echo esc_html($noteTx !== '' ? $noteTx : '—'); ?></div>
              </div>
            </div>
          </div>

          <div class="zone zone-7"><?php esc_html_e('Vreme štampe:', 'dexpress-woocommerce'); ?> <?php echo esc_html($printTime); ?></div>
        </div>
        <?php
      }
      echo '</div>';
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
    string $recipientPrimary,
    string $recipientStreet,
    string $recipientDesc,
    string $recipientCityLine,
    string $rPhone,
    string $recipientSecondary,
    string $packageShopBadge,
    string $sName,
    string $sStreet,
    string $sCityLine,
    string $sPhone,
    int $perSheet,
    string $urlLayout1,
    string $urlLayout2,
    string $urlLayout4,
    bool $compact,
  ): void {
?>
    <!DOCTYPE html>
    <html lang="sr">

    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title><?php echo esc_html($docTitle); ?></title>
      <?php $this->printLabelCss(); ?>
    </head>

    <body>

      <div class="toolbar no-print">
        <button type="button" onclick="window.print()"><?php esc_html_e('Štampaj', 'dexpress-woocommerce'); ?></button>
        <button type="button" class="secondary" onclick="window.close()"><?php esc_html_e('Zatvori', 'dexpress-woocommerce'); ?></button>
        <a href="<?php echo esc_url($urlLayout1); ?>" class="<?php echo $perSheet === 1 ? 'active-layout' : ''; ?>"><?php esc_html_e('A4 — 1 nalepnica', 'dexpress-woocommerce'); ?></a>
        <a href="<?php echo esc_url($urlLayout2); ?>" class="<?php echo $perSheet === 2 ? 'active-layout' : ''; ?>"><?php esc_html_e('A4 — 2 nalepnice', 'dexpress-woocommerce'); ?></a>
        <a href="<?php echo esc_url($urlLayout4); ?>" class="secondary <?php echo $perSheet === 4 ? 'active-layout' : ''; ?>"><?php esc_html_e('A4 — 4 nalepnice', 'dexpress-woocommerce'); ?></a>
      </div>

      <?php
      $this->printSheets([
        'packageLabels'      => $packageLabels,
        'shipmentContent'    => $shipmentContent,
        'shipmentNote'       => $shipmentNote,
        'printTime'          => $printTime,
        'referenceDisplay'   => $referenceDisplay,
        'paymentLine'        => $paymentLine,
        'returnDocLine'      => $returnDocLine,
        'codLine'            => $codLine,
        'hasCod'             => $hasCod,
        'recipientPrimary'   => $recipientPrimary,
        'recipientStreet'    => $recipientStreet,
        'recipientDesc'      => $recipientDesc,
        'recipientCityLine'  => $recipientCityLine,
        'rPhone'             => $rPhone,
        'recipientSecondary' => $recipientSecondary,
        'packageShopBadge'   => $packageShopBadge,
        'sName'              => $sName,
        'sStreet'            => $sStreet,
        'sCityLine'          => $sCityLine,
        'sPhone'             => $sPhone,
        'perSheet'           => $perSheet,
        'compact'            => $compact,
        'orderNumber'        => '',
      ]);
      ?>

    </body>

    </html>
<?php
    exit;
  }

  /**
   * Grupna štampa: shipment_ids=1,2,3 + nonce=dexpress_bulk_print
   * Koristi isti template (printSheets) kao i pojedinačna štampa.
   * Podržani rasporedi: 1, 2, 4, 6 nalepnica po strani.
   */
  private function renderMultiple(): void
  {
    if (!current_user_can('manage_woocommerce')) {
      wp_die(__('Nemate dovoljna prava.', 'dexpress-woocommerce'), '', ['response' => 403]);
    }

    $nonce = sanitize_text_field($_GET['nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'dexpress_bulk_print')) {
      wp_die(__('Nevažeći zahtev za grupnu štampu.', 'dexpress-woocommerce'), '', ['response' => 403]);
    }

    $rawIds      = sanitize_text_field($_GET['shipment_ids'] ?? '');
    $shipmentIds = array_values(array_filter(
      array_map('absint', explode(',', $rawIds)),
      static fn (int $id): bool => $id > 0,
    ));

    if (empty($shipmentIds)) {
      wp_die(__('Nema nalepnica za štampu.', 'dexpress-woocommerce'), '', ['response' => 400]);
    }

    $layoutRaw = (string) ($_GET['layout'] ?? '2');
    $perSheet  = in_array($layoutRaw, ['1', '2', '4', '6'], true) ? (int) $layoutRaw : 2;

    $shipmentsData = [];
    foreach ($shipmentIds as $sid) {
      $data = $this->buildLabelData($sid, $perSheet);
      if ($data !== null) {
        $shipmentsData[] = $data;
      }
    }

    if (empty($shipmentsData)) {
      wp_die(__('Nema valjanih pošiljaka za štampu.', 'dexpress-woocommerce'), '', ['response' => 404]);
    }

    $totalCount = count($shipmentsData);
    $docTitle   = sprintf(
      /* translators: %d: number of shipments */
      __('Grupna štampa — %d pošiljak(e)', 'dexpress-woocommerce'),
      $totalCount,
    );

    $baseUrl = add_query_arg([
      'page'         => 'dexpress-label',
      'shipment_ids' => $rawIds,
      'nonce'        => $nonce,
    ], admin_url('admin.php'));

    $urlL1 = add_query_arg('layout', '1', $baseUrl);
    $urlL2 = add_query_arg('layout', '2', $baseUrl);
    $urlL4 = add_query_arg('layout', '4', $baseUrl);
    $urlL6 = add_query_arg('layout', '6', $baseUrl);
    ?>
    <!DOCTYPE html>
    <html lang="sr">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title><?php echo esc_html($docTitle); ?></title>
      <?php $this->printLabelCss(); ?>
      <script>
        (function () {
          var qs = new URLSearchParams(window.location.search);
          if (!qs.get('layout')) {
            var stored = localStorage.getItem('dexpressBulkPrintLayout');
            if (stored && ['1', '2', '4', '6'].indexOf(stored) !== -1) {
              qs.set('layout', stored);
              window.location.replace(window.location.pathname + '?' + qs.toString());
            }
          }
          document.addEventListener('click', function (e) {
            var btn = e.target.closest('.bulk-layout-btn');
            if (btn && btn.dataset.layout) {
              localStorage.setItem('dexpressBulkPrintLayout', btn.dataset.layout);
            }
          });
        }());
      </script>
    </head>
    <body>

      <div class="toolbar no-print">
        <button type="button" onclick="window.print()"><?php esc_html_e('Štampaj', 'dexpress-woocommerce'); ?></button>
        <button type="button" class="secondary" onclick="window.close()"><?php esc_html_e('Zatvori', 'dexpress-woocommerce'); ?></button>
        <a href="<?php echo esc_url($urlL1); ?>" class="bulk-layout-btn <?php echo $perSheet === 1 ? 'active-layout' : ''; ?>" data-layout="1"><?php esc_html_e('1 nalepnica', 'dexpress-woocommerce'); ?></a>
        <a href="<?php echo esc_url($urlL2); ?>" class="bulk-layout-btn <?php echo $perSheet === 2 ? 'active-layout' : ''; ?>" data-layout="2"><?php esc_html_e('2 nalepnice', 'dexpress-woocommerce'); ?></a>
        <a href="<?php echo esc_url($urlL4); ?>" class="bulk-layout-btn <?php echo $perSheet === 4 ? 'active-layout' : ''; ?>" data-layout="4"><?php esc_html_e('4 nalepnice', 'dexpress-woocommerce'); ?></a>
        <a href="<?php echo esc_url($urlL6); ?>" class="bulk-layout-btn secondary <?php echo $perSheet === 6 ? 'active-layout' : ''; ?>" data-layout="6"><?php esc_html_e('6 nalepnica', 'dexpress-woocommerce'); ?></a>
      </div>

      <?php
      $last = count($shipmentsData) - 1;
      foreach ($shipmentsData as $i => $d) {
        $this->printSheets($d);
        if ($i < $last) {
          echo '<div style="page-break-after: always"></div>';
        }
      }
      ?>

    </body>
    </html>
    <?php
    exit;
  }

  /** Ispisuje <style> blok sa CSS-om zajedničkim za sve varijante štampe nalepnica. */
  private function printLabelCss(): void
  {
    ?>
      <style>
        * {
          box-sizing: border-box;
          margin: 0;
          padding: 0;
        }

        body {
          font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
          background: #e8e8e8;
          color: #111;
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
          margin: 0 auto;
          background: #fff;
          padding: 6mm;
          display: grid;
          gap: 0;
        }

        .sheet--1 {
          grid-template-columns: 1fr;
          grid-template-rows: 1fr;
        }

        .sheet--2 {
          grid-template-columns: 1fr 1fr;
          grid-template-rows: 1fr;
        }

        .sheet--4 {
          grid-template-columns: 1fr 1fr;
          grid-template-rows: 1fr 1fr;
        }

        .sheet--6 {
          grid-template-columns: 1fr 1fr;
          grid-template-rows: 1fr 1fr 1fr;
        }

        .sheet--6 .label-card:nth-child(odd) {
          border-right: 1pt dotted #555;
        }

        .sheet--6 .label-card:nth-child(-n+4) {
          border-bottom: 1pt dotted #555;
        }

        .label-card {
          border: 0.8pt solid #111;
          padding: 3.2mm;
          display: flex;
          flex-direction: column;
          page-break-inside: avoid;
          background: #fff;
          position: relative;
        }

        .sheet--2 .label-card:nth-child(odd),
        .sheet--4 .label-card:nth-child(odd) {
          border-right: 1pt dotted #555;
        }

        .sheet--4 .label-card:nth-child(-n+2) {
          border-bottom: 1pt dotted #555;
        }

        .zone {
          border-bottom: 0.6pt solid #444;
          padding: 1.2mm 0;
        }

        .zone:last-of-type {
          border-bottom: none;
        }

        .zone-0 {
          text-align: center;
          font-size: 8.2pt;
          font-weight: 600;
          letter-spacing: 0.01em;
          padding-top: 0;
          padding-bottom: 1.1mm;
        }

        .zone-1 {
          display: flex;
          justify-content: space-between;
          align-items: flex-start;
          gap: 4mm;
        }

        .carrier-block {
          flex: 1;
          min-width: 0;
        }

        .sender-block {
          font-size: 8pt;
          line-height: 1.3;
        }

        .pkg-counter {
          font-size: 26pt;
          font-weight: 800;
          line-height: 1;
          letter-spacing: 0.02em;
          flex-shrink: 0;
          text-align: right;
          min-width: 34mm;
          align-self: flex-start;
        }

        .zone-3 {
          text-align: center;
          padding-top: 1.5mm;
          padding-bottom: 1.5mm;
        }

        .barcode-wrap {
          width: 100%;
          text-align: center;
        }

        .barcode-wrap svg {
          width: 90%;
          max-width: 90%;
          height: auto;
          display: block;
          margin: 0 auto;
        }

        .zone-4 {
          text-align: center;
        }

        .tracking-code {
          font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
          font-size: 18pt;
          font-weight: 700;
          letter-spacing: 0.08em;
          line-height: 1.1;
        }

        .zone-5 .recipient-label {
          font-size: 9pt;
          font-weight: 700;
          margin-bottom: 0.8mm;
        }

        .zone-5 .recipient-primary {
          font-size: 19pt;
          font-weight: 800;
          line-height: 1.1;
          margin-bottom: 0.8mm;
        }

        .zone-5 .recipient-line {
          font-size: 11.5pt;
          font-weight: 600;
          line-height: 1.2;
        }

        .zone-5 .recipient-phone {
          margin-top: 0.8mm;
          font-size: 10.5pt;
          font-weight: 700;
        }

        .package-shop-badge {
          margin-top: 1.1mm;
          display: inline-block;
          border: 0.7pt solid #333;
          padding: 0.6mm 1.8mm;
          font-size: 8.4pt;
          font-weight: 800;
          letter-spacing: 0.02em;
        }

        .recipient-secondary {
          margin-top: 1mm;
          font-size: 8.2pt;
          color: #333;
        }

        .zone-6 {
          padding-top: 1.5mm;
        }

        .info-grid {
          display: grid;
          grid-template-columns: 1fr 1fr;
          gap: 1.1mm 3.8mm;
          font-size: 8.4pt;
          line-height: 1.3;
        }

        .info-cell.full {
          grid-column: 1 / -1;
        }

        .info-label {
          font-weight: 700;
          margin-bottom: 0.4mm;
        }

        .info-value {
          word-break: break-word;
        }

        .zone-7 {
          border-bottom: none;
          margin-top: auto;
          text-align: center;
          font-size: 7pt;
          color: #333;
          padding-top: 1.8mm;
        }

        .label-card--compact .pkg-counter {
          font-size: 18pt;
          min-width: 22mm;
        }

        .label-card--compact .tracking-code {
          font-size: 12pt;
        }

        .label-card--compact .zone-5 .recipient-primary {
          font-size: 12pt;
        }

        .label-card--compact .zone-5 .recipient-line {
          font-size: 8.7pt;
        }

        .label-card--compact .zone-5 .recipient-phone {
          font-size: 8.4pt;
        }

        .label-card--compact .zone-0 {
          font-size: 6.8pt;
        }

        .label-card--compact .sender-block {
          font-size: 6.8pt;
        }

        .label-card--compact .info-grid {
          font-size: 6.8pt;
        }

        .label-card--compact .package-shop-badge {
          font-size: 7pt;
        }

        .label-card--compact .recipient-secondary {
          font-size: 6.8pt;
        }

        .label-card--compact .zone-7 {
          font-size: 6pt;
        }

        @media print {
          body {
            background: #fff;
          }

          .no-print {
            display: none !important;
          }

          .sheet {
            margin: 0;
            padding: 4mm;
            width: 210mm;
            min-height: 297mm;
            page-break-after: always;
          }

          .sheet:last-child {
            page-break-after: auto;
          }

          .label-card {
            page-break-inside: avoid;
          }

          .sheet--1 .label-card {
            min-height: calc(297mm - 8mm);
          }

          .sheet--2 .label-card {
            min-height: calc(297mm - 8mm);
          }

          .sheet--4 .label-card {
            min-height: calc((297mm - 8mm) / 2);
          }

          .sheet--6 .label-card {
            min-height: calc((297mm - 8mm) / 3);
          }

          @page {
            size: A4 portrait;
            margin: 0;
          }
        }
      </style>
    <?php
  }
}
