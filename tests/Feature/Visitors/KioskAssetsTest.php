<?php

namespace Tests\Feature\Visitors;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

/**
 * The public kiosk gets SignaturePad from the Vite bundle (version-locked in package-lock.json),
 * never from a CDN.
 */
class KioskAssetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_kiosk_loads_the_bundled_signature_pad_instead_of_a_cdn(): void
    {
        $this->get(route('visitors.kiosk'))
            ->assertOk()
            ->assertSee(Vite::asset('resources/js/signature-pad.js'), false)
            ->assertDontSee('cdn.jsdelivr.net', false);
    }
}
