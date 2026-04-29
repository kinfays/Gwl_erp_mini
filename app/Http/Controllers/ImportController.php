<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\Import\DataImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

class ImportController extends Controller
{
    public function uac(DataImportService $imports): View
    {
        return view('uac.import.index', [
            'availableImportTypes' => $imports->availableTypes(true),
        ]);
    }

    public function staff(DataImportService $imports): View
    {
        return view('staff.import', [
            'availableImportTypes' => $imports->availableTypes(false),
        ]);
    }

    public function downloadTemplate(Request $request, string $type, DataImportService $imports)
    {
        return Excel::download(
            $imports->templateExport($type),
            $type . '_template.xlsx'
        );
    }

    public function preview(Request $request, DataImportService $imports): RedirectResponse
    {
        $request->validate([
            'type' => ['required', 'string'],
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt'],
        ]);

        $context = $this->resolveContext($request);
        $preview = $imports->preview($request->file('file'), $request->string('type')->toString());

        session()->put($this->previewKey($context), $preview);

        return back()->with('success', 'Import preview generated successfully.');
    }

    public function run(Request $request, DataImportService $imports): RedirectResponse
    {
        $context = $this->resolveContext($request);
        $preview = session()->get($this->previewKey($context));

        if (! $preview || empty($preview['valid_rows'])) {
            return back()->withErrors([
                'import' => 'No validated rows are available. Please preview a file first.',
            ]);
        }

        $result = $imports->run($preview['type'], $preview['valid_rows']);

        AuditLog::record(
            'run_import',
            $context,
            $preview['type'],
            null,
            null,
            $result
        );

        session()->forget($this->previewKey($context));

        return back()->with('success', sprintf(
            'Import completed. %d processed, %d created, %d updated.',
            $result['processed'],
            $result['created'],
            $result['updated']
        ));
    }

    protected function previewKey(string $context): string
    {
        return 'import_preview.' . $context;
    }

    protected function resolveContext(Request $request): string
    {
        $context = $request->route('context');

        if ($context) {
            return (string) $context;
        }

        $routeName = (string) $request->route()?->getName();

        return str_starts_with($routeName, 'staff.') ? 'staff' : 'uac';
    }
}
