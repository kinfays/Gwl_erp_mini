<?php

namespace App\Http\Controllers\Assets;

use App\Exports\Assets\AssetImportTemplateExport;
use App\Exports\Assets\Sheets\SummarySheet;
use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Services\Assets\AssetImportProfile;
use App\Services\Assets\AssetImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Assets > Upload: three uploads (Assets, Phones, Network) on one screen with a tab each. Same steps as the Staff import
 * (template, preview, run), but the run reads the stored file again instead of trusting the preview: the session holds
 * only the file's token, and the rules live in AssetImportService. Every action is for ONE type, taken from the route
 * (the original unprefixed routes are the Assets type); a preview kept for one type is never read for another. The route
 * middleware holds module access and assets.import; the service checks the permission again.
 */
class AssetImportController extends Controller
{
    use EnforcesModuleAccess;

    public function __construct(protected AssetImportService $imports) {}

    public function index(Request $request, string $type = AssetImportProfile::ASSETS): View
    {
        $profile = $this->profile($request, $type);

        return view('assets.import', [
            'profile' => $profile,
            'preview' => $this->currentPreview($request, $profile),
            'result' => session('import_result'),
        ]);
    }

    public function template(Request $request, string $type = AssetImportProfile::ASSETS)
    {
        $profile = $this->profile($request, $type);

        return Excel::download(
            new AssetImportTemplateExport($profile->type, $this->imports->allowedValues($profile->type, $request->user())),
            $profile->type.'_import_template.xlsx'
        );
    }

    public function preview(Request $request, string $type = AssetImportProfile::ASSETS): RedirectResponse
    {
        $profile = $this->profile($request, $type);

        $request->validate(['file' => ['required', 'file']]);

        $user = $request->user();
        $this->forget($request, $profile);

        try {
            $token = $this->imports->stage($request->file('file'), $user, $profile->type);
            $preview = $this->imports->preview($token, $user, $profile->type);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        session()->put($profile->sessionKey, $preview);

        return back()->with('success', 'Import preview generated. Nothing has been saved yet.');
    }

    public function run(Request $request, string $type = AssetImportProfile::ASSETS): RedirectResponse
    {
        $profile = $this->profile($request, $type);
        $user = $request->user();
        $preview = $this->currentPreview($request, $profile);

        if (! $preview) {
            return back()->withErrors(['import' => 'No file is waiting. Please upload and preview a file first.']);
        }

        try {
            // The token comes from the session, never from the request; the service reads the stored file again, in the
            // folder of THIS type.
            $result = $this->imports->confirm($preview['token'], $user, $request->boolean('valid_only'), $profile->type);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        session()->forget($profile->sessionKey);

        return back()
            ->with('import_result', ['type' => $profile->type] + array_intersect_key($result, array_flip(['created', 'skipped', 'failed', 'assigned', 'rows'])))
            ->with('success', sprintf(
                'Import completed: %d %s(s) created%s%s.',
                $result['created'],
                $profile->noun,
                $result['skipped'] > 0 ? ', '.$result['skipped'].' row(s) skipped' : '',
                $result['failed'] > 0 ? ', '.$result['failed'].' row(s) failed' : ''
            ));
    }

    public function clear(Request $request, string $type = AssetImportProfile::ASSETS): RedirectResponse
    {
        $profile = $this->profile($request, $type);
        $this->forget($request, $profile);

        return back()->with('success', 'Upload cleared.');
    }

    /** Every error and warning of the previewed file (Row, Column, Severity, Message), read from the stored file again. */
    public function errors(Request $request, string $type = AssetImportProfile::ASSETS)
    {
        $profile = $this->profile($request, $type);
        $preview = $this->currentPreview($request, $profile);

        abort_unless($preview, 404);

        $rows = array_map(
            fn (array $issue) => [(string) $issue['row'], (string) $issue['column'], $issue['severity'], $issue['message']],
            $this->imports->issues($preview['token'], $request->user(), $profile->type)
        );

        return Excel::download(new SummarySheet('Issues', ['Row', 'Column', 'Severity', 'Message'], $rows), $profile->type.'_import_issues.xlsx');
    }

    protected function profile(Request $request, string $type): AssetImportProfile
    {
        $this->enforceModule($request, 'assets');

        abort_unless($this->imports->canImport($request->user()), 403, 'You do not have permission to perform this action.');

        return AssetImportProfile::for($type);
    }

    /**
     * The preview kept for THIS user and THIS type only: a preview put in the session for another user, or another type,
     * is ignored (and the stored file would not be found in this type's folder anyway).
     *
     * @return array<string, mixed>|null
     */
    protected function currentPreview(Request $request, AssetImportProfile $profile): ?array
    {
        $preview = session()->get($profile->sessionKey);

        return is_array($preview)
            && ($preview['user_id'] ?? null) === $request->user()->id
            && ($preview['type'] ?? AssetImportProfile::ASSETS) === $profile->type
            ? $preview
            : null;
    }

    protected function forget(Request $request, AssetImportProfile $profile): void
    {
        $this->imports->discard($this->currentPreview($request, $profile)['token'] ?? null, $profile->type);
        session()->forget($profile->sessionKey);
    }
}
