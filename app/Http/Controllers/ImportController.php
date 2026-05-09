<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Notifications\GeneralDatabaseNotification;
use App\Services\Import\DataImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
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
            $type.'_template.xlsx'
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
        $maxFailurePercent = (int) config('gwl.max_import_failure_percent', 20);
        $rowCount = max(1, (int) ($preview['total_rows'] ?? 0));
        $failurePercent = round(((int) ($preview['error_count'] ?? 0) / $rowCount) * 100, 1);

        $preview['failure_percent'] = $failurePercent;
        $preview['max_failure_percent'] = $maxFailurePercent;
        $preview['blocked'] = $failurePercent > $maxFailurePercent;

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

        if (! empty($preview['blocked'])) {
            return back()->withErrors([
                'import' => sprintf(
                    'Import blocked because %.1f%% of rows failed validation. The configured maximum is %d%%.',
                    $preview['failure_percent'] ?? 0,
                    $preview['max_failure_percent'] ?? config('gwl.max_import_failure_percent', 20)
                ),
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

        if (Schema::hasTable('notifications')) {
            $request->user()?->notify(new GeneralDatabaseNotification(
                'Import completed',
                sprintf('%d %s rows were processed.', $result['processed'], str_replace('_', ' ', $preview['type'])),
                route($context.'.import'),
                $context,
                ['type' => 'import_completed']
            ));
        }

        return back()->with('success', sprintf(
            'Import completed. %d processed, %d created, %d updated.',
            $result['processed'],
            $result['created'],
            $result['updated']
        ));
    }

    public function clear(Request $request): RedirectResponse
    {
        $context = $this->resolveContext($request);

        session()->forget($this->previewKey($context));

        return back()->with('success', 'Upload error cleared.');
    }

    protected function previewKey(string $context): string
    {
        return 'import_preview.'.$context;
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
