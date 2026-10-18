<?php

namespace Tests\Feature\GWater;

use App\Livewire\GWater\Purchases;
use App\Models\AuditLog;
use App\Models\GwPurchase;
use App\Services\GWater\GwaterWorkflowService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/** The picture of the paper waybill, attached when a trip is recorded. */
class GwaterWaybillPhotoTest extends GwaterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(GwaterWorkflowService::WAYBILL_DISK);
    }

    private function record($user, string $waybill, ?UploadedFile $photo): GwPurchase
    {
        $this->actingAs($user);

        return $this->workflow()->recordPurchase($user, [
            'purchase_date' => '2026-06-01', 'waybill_no' => $waybill, 'lines' => [['product_id' => $this->bottle->id, 'packs' => 10]],
        ], $photo);
    }

    public function test_a_trip_keeps_the_waybill_picture_on_the_private_disk(): void
    {
        $user = $this->gw('G1', $this->sowutuom);

        $trip = $this->record($user, 'WB-1', UploadedFile::fake()->image('IMG_2041.jpg', 400, 300));

        $this->assertTrue($trip->fresh()->hasWaybillPhoto());
        $this->assertSame('image/jpeg', $trip->fresh()->waybill_photo_mime);
        $this->assertStringStartsWith('gwater/waybills/'.$trip->id.'/', $trip->fresh()->waybill_photo_path);
        $this->assertStringNotContainsString('IMG_2041', $trip->fresh()->waybill_photo_path, 'the stored name is random, not the file name');
        Storage::disk(GwaterWorkflowService::WAYBILL_DISK)->assertExists($trip->fresh()->waybill_photo_path);

        // The audit row says a picture was attached, never what it was called.
        $row = AuditLog::query()->where('action', 'gwater.purchase_recorded')->firstOrFail();
        $this->assertTrue($row->metadata['waybill_photo']);
        $this->assertStringNotContainsString('IMG_2041', json_encode($row->metadata));
    }

    public function test_a_pdf_is_accepted_and_kept_as_a_pdf(): void
    {
        $user = $this->gw('G1', $this->sowutuom);

        $trip = $this->record($user, 'WB-PDF', UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4
1 0 obj
<< /Type /Catalog >>
endobj
trailer
<< /Root 1 0 R >>
%%EOF"));

        $this->assertSame('application/pdf', $trip->fresh()->waybill_photo_mime);
        $this->assertStringEndsWith('.pdf', $trip->fresh()->waybill_photo_path);
        Storage::disk(GwaterWorkflowService::WAYBILL_DISK)->assertExists($trip->fresh()->waybill_photo_path);

        $response = $this->actingAs($user)->get(route('gwater.purchases.waybill', $trip->id));
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'), 'shown in the browser, not downloaded');
    }

    public function test_the_picture_is_optional(): void
    {
        $trip = $this->record($this->gw('G1', $this->sowutuom), 'WB-1', null);

        $this->assertFalse($trip->hasWaybillPhoto());
        $this->assertFalse(AuditLog::query()->where('action', 'gwater.purchase_recorded')->firstOrFail()->metadata['waybill_photo']);
    }

    public function test_only_real_images_within_the_size_limit_are_accepted(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        config(['gwl.gwater_waybill_photo_max_mb' => 1]);

        foreach ([
            UploadedFile::fake()->createWithContent('waybill.pdf', 'named like a pdf, but it is not one'),
            UploadedFile::fake()->createWithContent('notes.txt', 'plain text'),
            UploadedFile::fake()->createWithContent('page.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('waybill.jpg', 'this is not a picture'),   // right name, wrong content
            UploadedFile::fake()->image('huge.png')->size(2048),
        ] as $bad) {
            try {
                $this->record($user, 'WB-X', $bad);
                $this->fail('Expected a refusal for '.$bad->getClientOriginalName());
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('waybill_photo', $exception->errors());
            }
        }

        $this->assertSame(0, GwPurchase::query()->count());
        $this->assertSame([], Storage::disk(GwaterWorkflowService::WAYBILL_DISK)->allFiles());
    }

    public function test_a_trip_that_is_refused_leaves_no_picture_behind(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->record($user, 'WB-1', null);

        try {
            $this->record($user, 'WB-1', UploadedFile::fake()->image('again.jpg'));   // waybill number already used
            $this->fail('Expected a refusal.');
        } catch (ValidationException) {
            $this->assertSame(1, GwPurchase::query()->count());
            $this->assertSame([], Storage::disk(GwaterWorkflowService::WAYBILL_DISK)->allFiles());
        }
    }

    public function test_the_picture_is_served_only_to_people_who_can_see_the_trip(): void
    {
        $owner = $this->gw('G1', $this->sowutuom);
        $trip = $this->record($owner, 'WB-1', UploadedFile::fake()->image('wb.png', 120, 90));

        $response = $this->actingAs($owner)->get(route('gwater.purchases.waybill', $trip->id));
        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        // Another district of the same region shares the books; another region and Head Office get the same 404 as a missing trip.
        $this->actingAs($this->gw('G2', $this->odorkor))->get(route('gwater.purchases.waybill', $trip->id))->assertOk();
        $this->actingAs($this->gw('G3', $this->kumasi))->get(route('gwater.purchases.waybill', $trip->id))->assertNotFound();
        $this->actingAs($this->gw('G4', $this->headOffice))->get(route('gwater.purchases.waybill', $trip->id))->assertNotFound();
        $this->actingAs($owner)->get(route('gwater.purchases.waybill', 999999))->assertNotFound();

        // Someone without G-Water access never gets it.
        $this->assertContains($this->actingAs($this->person('E1', $this->sowutuom))->get(route('gwater.purchases.waybill', $trip->id))->getStatusCode(), [302, 403]);

        // A trip with no picture answers 404.
        $plain = $this->record($owner, 'WB-2', null);
        $this->actingAs($owner)->get(route('gwater.purchases.waybill', $plain->id))->assertNotFound();
    }

    public function test_the_trip_form_uploads_the_picture_and_the_list_links_to_it(): void
    {
        $user = $this->gw('G1', $this->sowutuom);

        Livewire::actingAs($user)->test(Purchases::class)
            ->call('openForm')
            ->set('form.purchase_date', '2026-06-01')->set('form.waybill_no', 'WB-77')
            ->set("packs.{$this->bottle->id}", '20')
            ->set('waybillPhoto', UploadedFile::fake()->image('scan.jpg', 300, 200))
            ->assertHasNoErrors()
            ->call('save')->assertHasNoErrors()
            ->assertSet('waybillPhoto', null)
            ->set('from', '2026-01-01')
            ->assertSee('Preview waybill');

        $trip = GwPurchase::query()->where('waybill_no', 'WB-77')->firstOrFail();
        $this->assertTrue($trip->hasWaybillPhoto());

        // A non-image is refused on the form, and nothing is saved.
        Livewire::actingAs($user)->test(Purchases::class)
            ->call('openForm')
            ->set('form.purchase_date', '2026-06-02')->set('form.waybill_no', 'WB-78')
            ->set("packs.{$this->bottle->id}", '20')
            ->set('waybillPhoto', UploadedFile::fake()->createWithContent('notes.txt', 'plain text'))
            ->assertHasErrors('waybillPhoto')
            ->call('save')->assertHasErrors('waybillPhoto');
        $this->assertSame(0, GwPurchase::query()->where('waybill_no', 'WB-78')->count());

        // A PDF is fine, and the list previews it in a window (an inline frame for a PDF, an image for a picture).
        Livewire::actingAs($user)->test(Purchases::class)
            ->call('openForm')
            ->set('form.purchase_date', '2026-06-03')->set('form.waybill_no', 'WB-79')
            ->set("packs.{$this->bottle->id}", '20')
            ->set('waybillPhoto', UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4
%%EOF"))
            ->assertHasNoErrors()
            ->call('save')->assertHasNoErrors();

        $pdf = GwPurchase::query()->where('waybill_no', 'WB-79')->firstOrFail();
        $component = Livewire::actingAs($user)->test(Purchases::class)->set('from', '2026-01-01');
        $component->call('openPreview', $pdf->id)->assertSee('<iframe', false)->assertSee(route('gwater.purchases.waybill', $pdf->id), false);
        $component->call('closePreview')->assertDontSee('<iframe', false);
        $component->call('openPreview', $trip->id)->assertSee('<img src="'.route('gwater.purchases.waybill', $trip->id), false)->assertDontSee('<iframe', false);

        // A trip with no waybill, or in another location's books, cannot be previewed.
        $none = $this->record($user, 'WB-80', null);
        Livewire::actingAs($user)->test(Purchases::class)->call('openPreview', $none->id)->assertNotFound();
        Livewire::actingAs($this->gw('G9', $this->kumasi))->test(Purchases::class)->call('openPreview', $trip->id)->assertNotFound();
    }
}
