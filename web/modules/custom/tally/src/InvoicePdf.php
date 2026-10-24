<?php

namespace Drupal\tally;

use Dompdf\Dompdf;
use Dompdf\Options;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\tally\Entity\Invoice;

/**
 * Draws an invoice as a PDF with dompdf, from the same document the public page shows.
 */
class InvoicePdf {

  public function __construct(
    protected InvoiceDocument $document,
    protected RendererInterface $renderer,
    protected FileSystemInterface $fileSystem,
    protected ModuleExtensionList $modules,
  ) {}

  /**
   * The PDF bytes of one invoice. Nothing is stored; it is drawn on request.
   */
  public function render(Invoice $invoice): string {
    $module = $this->modules->getPath('tally');
    $fonts = $this->fileSystem->getTempDirectory() . '/tally-dompdf';
    $this->fileSystem->prepareDirectory($fonts, FileSystemInterface::CREATE_DIRECTORY);

    $options = new Options();
    // Nothing in the document is fetched from the network or from outside the module.
    $options->setIsRemoteEnabled(FALSE);
    $options->setChroot([realpath(DRUPAL_ROOT . '/' . $module)]);
    $options->setFontDir($fonts);
    $options->setFontCache($fonts);
    $options->setDefaultFont('Geist');
    $dompdf = new Dompdf($options);
    $this->registerFonts($dompdf, DRUPAL_ROOT . '/' . $module . '/fonts');

    $build = $this->document->build($invoice);
    $body = (string) $this->renderer->renderInIsolation($build);
    $css = (string) file_get_contents(DRUPAL_ROOT . '/' . $module . '/css/invoice-document.css');
    $dompdf->loadHtml('<!DOCTYPE html><html><head><meta charset="utf-8"><style>@page { margin: 18mm; }' . $css . '</style></head><body>' . $body . '</body></html>');
    $dompdf->setPaper('letter');
    $dompdf->render();
    return (string) $dompdf->output();
  }

  /**
   * Makes the bundled Geist files known to dompdf, once per font cache.
   */
  protected function registerFonts(Dompdf $dompdf, string $dir): void {
    $metrics = $dompdf->getFontMetrics();
    $files = [
      ['Geist', 'normal', 'Geist-Regular.ttf'],
      ['Geist', 'bold', 'Geist-SemiBold.ttf'],
      ['Geist Mono', 'normal', 'GeistMono-Regular.ttf'],
    ];
    foreach ($files as [$family, $weight, $file]) {
      if (!$metrics->getFont($family, $weight) || !str_contains((string) $metrics->getFont($family, $weight), 'tally-dompdf')) {
        $metrics->registerFont(['family' => $family, 'style' => 'normal', 'weight' => $weight], $dir . '/' . $file);
      }
    }
  }

}
