<?php

namespace App\Services\HealthSafety;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * The ONE place the QR package (bacon/bacon-qr-code, already installed for the MDM enrolment codes) is used by Health &
 * Safety, so it can be swapped without touching a label or a poster. Output is an SVG data URI, which Dompdf draws as
 * vector paths: no GD or Imagick needed and the code stays sharp at any print size.
 */
class QrCodeGenerator
{
    /** An image Dompdf (and a browser) can show in an <img src>. */
    public function dataUri(string $payload): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($this->svg($payload));
    }

    public function svg(string $payload): string
    {
        $writer = new Writer(new ImageRenderer(
            // A quiet zone of 2 modules; the sticker itself is white around it.
            new RendererStyle(400, 2),
            new SvgImageBackEnd
        ));

        // Medium error correction: a sticker on a wall gets scuffed, and a link this short stays a small, easy-to-scan code.
        $svg = $writer->writeString($payload, 'UTF-8', ErrorCorrectionLevel::M());

        return trim(preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg) ?? $svg);
    }
}
