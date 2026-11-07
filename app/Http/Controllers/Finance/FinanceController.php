<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Services\Finance\FinanceScope;
use App\Services\Finance\PvAttachmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Thin: the route middleware (module + permission), the Livewire components and FinanceScope do the real checks. Which
 * vouchers a user reaches is never decided here.
 */
class FinanceController extends Controller
{
    use EnforcesModuleAccess;

    public function home(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_FINANCE);

        return view('finance.home');
    }

    public function create(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_FINANCE);

        return view('finance.form', ['pv' => null]);
    }

    public function edit(Request $request, int $pv): View
    {
        $this->enforceModule($request, Permission::MODULE_FINANCE);

        return view('finance.form', ['pv' => $pv]);
    }

    public function show(Request $request, int $pv): View
    {
        $this->enforceModule($request, Permission::MODULE_FINANCE);

        return view('finance.show', ['pv' => $pv]);
    }

    public function settings(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_FINANCE);

        return view('finance.settings');
    }

    /** A voucher's scan / supporting document. 404 for a voucher outside the location (same answer as a missing one). */
    public function attachment(Request $request, int $pv, int $attachment): StreamedResponse
    {
        $this->enforceModule($request, Permission::MODULE_FINANCE);

        return $this->serve($request, $pv, $attachment, FinanceScope::AS_FINANCE);
    }

    public function serve(Request $request, int $pv, int $attachment, string $as): StreamedResponse
    {
        $voucher = app(FinanceScope::class)->findPv($request->user(), $pv, $as);
        $file = $voucher->attachments()->whereKey($attachment)->firstOrFail();
        $disk = Storage::disk(PvAttachmentService::DISK);

        abort_unless($disk->exists($file->path), 404);

        return $disk->response($file->path, $file->display_name, [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $file->display_name).'"',
        ]);
    }
}
