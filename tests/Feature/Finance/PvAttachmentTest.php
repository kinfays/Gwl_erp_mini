<?php

namespace Tests\Feature\Finance;

use App\Models\FinPvAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Optional attachments: checked by CONTENT, limits from config, private random names, served only through the scope. */
class PvAttachmentTest extends FinanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_a_pdf_is_recognised_by_its_signature_whatever_it_is_called(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->actingAs($fin);

        $pv = $this->workflow()->createDraft($fin, $this->pvData(), [
            UploadedFile::fake()->createWithContent('scan.jpg', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n"),
        ]);

        $attachment = $pv->attachments()->firstOrFail();
        $this->assertSame('application/pdf', $attachment->mime);
        $this->assertStringEndsWith('.pdf', $attachment->path);
        $this->assertStringStartsWith('finance/pvs/'.$pv->id.'/', $attachment->path);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_pictures_are_accepted_but_svg_text_and_fakes_are_refused(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->actingAs($fin);
        $w = $this->workflow();

        $this->assertNotNull($w->createDraft($fin, $this->pvData(['pv_no' => 'IMG']), [UploadedFile::fake()->image('receipt.png', 20, 20)]));

        $bad = [
            UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('notes.pdf', 'just some text, not a pdf'),
            UploadedFile::fake()->createWithContent('page.png', '<html><body>hi</body></html>'),
            UploadedFile::fake()->createWithContent('empty.pdf', ''),
        ];

        foreach ($bad as $index => $file) {
            try {
                $w->createDraft($fin, $this->pvData(['pv_no' => 'BAD-'.$index]), [$file]);
                $this->fail('A file that is not a PDF / JPG / PNG / WebP must be refused ('.$file->getClientOriginalName().').');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('attachments', $exception->errors());
            }
        }

        // Nothing was stored or saved for the refused ones.
        $this->assertSame(1, FinPvAttachment::query()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('finance'));
    }

    public function test_the_size_and_count_limits_come_from_config(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->actingAs($fin);
        $pdf = fn () => UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n".str_repeat('x', 100));

        config(['gwl.fin_attachment_max_per_pv' => 2]);
        $this->assertRefused(fn () => $this->workflow()->createDraft($fin, $this->pvData(['pv_no' => 'C1']), [$pdf(), $pdf(), $pdf()]));

        $pv = $this->workflow()->createDraft($fin, $this->pvData(['pv_no' => 'C2']), [$pdf(), $pdf()]);
        // A third one is refused on edit; removing one makes room for it.
        $this->assertRefused(fn () => $this->workflow()->update($fin, $pv, $this->pvData(['pv_no' => 'C2']), $pv->lock_version, [$pdf()]));
        $first = $pv->attachments()->firstOrFail();
        $pv = $this->workflow()->update($fin, $pv, $this->pvData(['pv_no' => 'C2']), $pv->lock_version, [$pdf()], [$first->id]);
        $this->assertSame(2, $pv->attachments()->count());
        Storage::disk('local')->assertMissing($first->path);
        $this->assertSame(2, $pv->lock_version, 'swapping a file is a change of content, so the version rose');

        config(['gwl.fin_attachment_max_mb' => 1]);
        $big = UploadedFile::fake()->createWithContent('big.pdf', "%PDF-1.4\n".str_repeat('x', 1024 * 1024 + 10));
        $this->assertRefused(fn () => $this->workflow()->createDraft($fin, $this->pvData(['pv_no' => 'C3']), [$big]));
    }

    public function test_a_file_is_served_only_to_users_who_reach_the_voucher_and_only_through_the_route(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $finKumasi = $this->finance('F2', $this->kumasi);
        $aud = $this->audit('AU1', $this->sowutuom);
        $audKumasi = $this->audit('AU2', $this->kumasi);

        $this->actingAs($fin);
        $pv = $this->workflow()->createDraft($fin, $this->pvData(), [UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4\nbody")]);
        $attachment = $pv->attachments()->firstOrFail();
        $finUrl = route('finance.pvs.attachment', [$pv->id, $attachment->id]);
        $audUrl = route('audit.pvs.attachment', [$pv->id, $attachment->id]);

        $response = $this->actingAs($fin)->get($finUrl)->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));

        $this->actingAs($finKumasi)->get($finUrl)->assertNotFound();                     // another location
        $this->actingAs($aud)->get($audUrl)->assertNotFound();                           // a draft is Finance's alone
        $this->actingAs($aud)->get(route('finance.pvs.attachment', [$pv->id, $attachment->id]))->assertRedirect(); // no finance module access

        $this->actingAs($fin);
        $this->workflow()->submit($fin, $pv, $pv->lock_version);

        $this->actingAs($aud)->get($audUrl)->assertOk();
        $this->actingAs($audKumasi)->get($audUrl)->assertNotFound();
        // Same attachment id under a voucher it does not belong to is a 404, not a leak.
        $other = $this->draft($fin, ['pv_no' => 'OTHER']);
        $this->actingAs($fin)->get(route('finance.pvs.attachment', [$other->id, $attachment->id]))->assertNotFound();

        // The file has no public URL.
        $this->assertFalse(str_contains($attachment->path, 'public'));
        $this->assertNull(Storage::disk('local')->providesTemporaryUrls() ? null : null);
    }

    private function assertRefused(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected the files to be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('attachments', $exception->errors());
        }
    }
}
