<?php

namespace App\Http\Controllers\Assets;

use App\Exports\Assets\AssetImportTemplateExport;
use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Services\Assets\AssetImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Assets > Assets > Upload assets. Same steps as the UAC and Staff imports (template, preview, run), but the run reads
 * the stored file again instead of trusting the preview: the session holds only the file's token, and the rules live in
 * AssetImportService. The route middleware holds module access and assets.import; the service checks the permission again.
 */
class AssetImportController extends Controller
{
    use EnforcesModuleAccess;

    protected const SESSION_KEY = 'asset_import_preview';

    public function __construct(protected AssetImportService $imports) {}

    public function index(Request $request): View
    {
        $this->guard($request);

        return view('assets.import', ['preview' => $this->currentPreview($request)]);
    }

    public function template(Request $request)
    {
        $this->guard($request);

        return Excel::download(new AssetImportTemplateExport, 'assets_import_template.xlsx');
    }

    public function preview(Request $request): RedirectResponse
    {
        $this->guard($request);

        $request->validate(['file' => ['required', 'file']]);

        $user = $request->user();
        $this->forget($request);

        try {
            $token = $this->imports->stage($request->file('file'), $user);
            $preview = $this->imports->preview($token, $user);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        session()->put(self::SESSION_KEY, $preview);

        return back()->with('success', 'Import preview generated. Nothing has been saved yet.');
    }

    public function run(Request $request): RedirectResponse
    {
        $this->guard($request);

        $user = $request->user();
        $preview = $this->currentPreview($request);

        if (! $preview) {
            return back()->withErrors(['import' => 'No file is waiting. Please upload and preview a file first.']);
        }

        try {
            // The token comes from the session, never from the request; the service reads the stored file again.
            $result = $this->imports->confirm($preview['token'], $user, $request->boolean('valid_only'));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        session()->forget(self::SESSION_KEY);

        return back()->with('success', sprintf(
            'Import completed: %d asset(s) created%s.',
            $result['created'],
            $result['skipped'] > 0 ? ', '.$result['skipped'].' row(s) skipped' : ''
        ));
    }

    public function clear(Request $request): RedirectResponse
    {
        $this->guard($request);
        $this->forget($request);

        return back()->with('success', 'Upload cleared.');
    }

    protected function guard(Request $request): void
    {
        $this->enforceModule($request, 'assets');

        abort_unless($this->imports->canImport($request->user()), 403, 'You do not have permission to perform this action.');
    }

    /**
     * The preview kept for THIS user only.
     *
     * @return array<string, mixed>|null
     */
    protected function currentPreview(Request $request): ?array
    {
        $preview = session()->get(self::SESSION_KEY);

        return is_array($preview) && ($preview['user_id'] ?? null) === $request->user()->id ? $preview : null;
    }

    protected function forget(Request $request): void
    {
        $this->imports->discard($this->currentPreview($request)['token'] ?? null);
        session()->forget(self::SESSION_KEY);
    }
}
