<?php

namespace App\Services\HealthSafety\Concerns;

use Dompdf\Dompdf;
use Dompdf\Options;

trait RendersLabelPdf
{
    /** Overridable so a test can force a rendering failure. */
    protected function renderPdf(string $html): string
    {
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        // Remote files stay off: every image on a label is an inline data URI.
        $options->set('isRemoteEnabled', false);

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html);
        $pdf->setPaper('a4', 'portrait');
        $pdf->render();

        return $pdf->output();
    }
}
