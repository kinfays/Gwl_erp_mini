<?php

namespace App\Services\Assets\Mdm;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Renders a payload as an inline SVG QR code (no GD/Imagick needed). The returned markup is generated entirely by
 * the library from the payload, so it is safe to print unescaped.
 */
class QrCodeRenderer
{
    public function svg(string $payload, int $size = 360): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle($size, 2),
            new SvgImageBackEnd
        ));

        // Low error correction keeps the modules big: a factory-fresh phone's setup camera scans a large, simple
        // code from a screen far more reliably than a dense one.
        $svg = $writer->writeString($payload, 'UTF-8', ErrorCorrectionLevel::L());

        // Drop the XML prolog so the SVG can be inlined in HTML.
        return trim(preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg) ?? $svg);
    }
}
