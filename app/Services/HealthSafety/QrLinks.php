<?php

namespace App\Services\HealthSafety;

/**
 * The absolute links baked into printed QR codes. The base is gwl.hs_qr_base_url, or APP_URL when that is blank. The
 * links carry the numeric id of an item (never its asset code, which an officer types and may hold a slash or a space)
 * and never any data: they are the normal login-required routes.
 */
class QrLinks
{
    public function baseUrl(): string
    {
        $configured = trim((string) config('gwl.hs_qr_base_url'));

        return rtrim($configured !== '' ? $configured : (string) config('app.url'), '/');
    }

    /** @param  string  $type  'extinguisher' or 'kit' */
    public function scanUrl(string $type, int $id): string
    {
        return $this->baseUrl().route('health_safety.scan', ['type' => $type, 'id' => $id], false);
    }

    /** The report form with a site already chosen (the poster's code). */
    public function reportUrl(int $siteId): string
    {
        return $this->baseUrl().route('health_safety.report', [], false).'?site='.$siteId;
    }
}
