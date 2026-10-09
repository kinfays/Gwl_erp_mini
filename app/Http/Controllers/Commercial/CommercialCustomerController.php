<?php

namespace App\Http\Controllers\Commercial;

use App\Exports\Commercial\CommercialReportExport;
use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Jobs\Commercial\BuildCustomerListExport;
use App\Jobs\Commercial\ProcessCustomerListBatch;
use App\Models\CommercialCustomerBatch;
use App\Models\Permission;
use App\Services\Commercial\Customers\CustomerAnalyticsService;
use App\Services\Commercial\Customers\CustomerExportService;
use App\Services\Commercial\Customers\CustomerImportException;
use App\Services\Commercial\Customers\CustomerImportService;
use App\Services\Commercial\Customers\CustomerListService;
use App\Services\Commercial\Customers\CustomerScope;
use App\Services\Commercial\Customers\CustomerSnapshots;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Thin: the page wrappers, the file upload (a plain form post, because customer files are far too big for Livewire's
 * temporary uploads) and the exports. Every action re-checks the flag, the module, the permission and the viewer's region.
 */
class CommercialCustomerController extends Controller
{
    use EnforcesModuleAccess;

    protected function page(Request $request, string $view, array $data = [])
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);
        abort_unless(config('gwl.commercial_customer_list_enabled'), 404);

        return view($view, $data);
    }

    public function dashboard(Request $request)
    {
        return $this->page($request, 'commercial.customers.dashboard');
    }

    public function list(Request $request)
    {
        return $this->page($request, 'commercial.customers.list');
    }

    public function show(Request $request, int $customer)
    {
        return $this->page($request, 'commercial.customers.show', ['customerId' => $customer]);
    }

    public function uploads(Request $request)
    {
        return $this->page($request, 'commercial.customers.uploads');
    }

    public function batch(Request $request, CommercialCustomerBatch $batch)
    {
        return $this->page($request, 'commercial.customers.batch', ['batch' => $batch]);
    }

    public function lookups(Request $request)
    {
        return $this->page($request, 'commercial.customers.lookups');
    }

    // ---------------------------------------------------------------- upload

    public function upload(Request $request, CustomerImportService $imports)
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);
        abort_unless(config('gwl.commercial_customer_list_enabled'), 404);

        $user = $request->user();

        if (CustomerScope::regionRestriction($user) === 0) {
            return back()->with('error', 'Your account has no region assigned, so you cannot upload customer lists. Contact an administrator.');
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:'.((int) config('gwl.commercial_customer_import_max_mb', 200) * 1024)],
            'period_type' => ['required', 'in:weekly,monthly'],
            'as_of_date' => ['required', 'date', 'before_or_equal:tomorrow'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $file = $request->file('file');

        try {
            ['batch' => $batch, 'duplicate' => $duplicate] = $imports->register(
                $file->getRealPath(), $file->getClientOriginalName(), (string) $request->input('period_type'), Carbon::parse((string) $request->input('as_of_date')), $user, $request->input('notes')
            );
        } catch (CustomerImportException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        if ($duplicate) {
            return redirect()->route('commercial.customers.batch', $batch)->with('status', 'This exact file was already uploaded (batch #'.$batch->id.', '.CommercialCustomerBatch::statusLabel($batch->status).'). Nothing was done twice.');
        }

        Audit::log(
            action: 'commercial.customer_batch_uploaded',
            module: Permission::MODULE_COMMERCIAL,
            targetType: CommercialCustomerBatch::class,
            targetId: $batch->id,
            metadata: ['period' => $batch->period_key, 'period_type' => $batch->period_type, 'size_mb' => round($file->getSize() / 1048576, 1)]
        );

        ProcessCustomerListBatch::dispatch($batch->id);

        return redirect()->route('commercial.customers.batch', $batch)->with('status', 'File received. It is being read in the background; this page shows its progress.');
    }

    // ---------------------------------------------------------------- exports

    public function export(Request $request, string $report, string $format, CustomerSnapshots $snapshots, CustomerAnalyticsService $analytics, CustomerExportService $exports, CustomerListService $lists)
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);
        abort_unless(config('gwl.commercial_customer_list_enabled'), 404);
        abort_unless($format === 'excel', 404);

        $user = $request->user();
        $restriction = CustomerScope::regionRestriction($user);
        $districtId = ctype_digit((string) $request->query('district')) && $request->query('district') !== '' ? (int) $request->query('district') : null;
        $periodKey = is_string($request->query('period')) && $request->query('period') !== '' ? (string) $request->query('period') : null;

        $batches = $snapshots->current($restriction, null, $districtId, $periodKey);
        abort_if($districtId !== null && ! $batches->has($districtId), 404);

        return match ($report) {
            'summary' => $this->summary($batches, $restriction, $snapshots, $analytics, $exports, $districtId),
            'list' => $this->listExport($request, $batches, $restriction, $exports, $lists, $districtId),
            default => abort(404),
        };
    }

    protected function summary($batches, ?int $restriction, CustomerSnapshots $snapshots, CustomerAnalyticsService $analytics, CustomerExportService $exports, ?int $districtId)
    {
        if ($batches->isEmpty()) {
            return back()->with('error', 'There is nothing to export yet.');
        }

        $filters = $snapshots->filters($batches, $restriction, ['district_id' => $districtId]);
        $report = $exports->summary([
            'overview' => $analytics->overview($filters), 'receivables' => $analytics->receivables($filters), 'meters' => $analytics->meters($filters, 'district'),
            'dormancy' => $analytics->dormancy($filters), 'quality' => $analytics->quality($filters),
        ], [
            'Scope' => $districtId ? (DB::table('districts')->where('id', $districtId)->value('district_name') ?? 'District') : ($restriction === null ? 'Every region' : 'Your region'),
            'Districts' => (string) $batches->count(),
            'Data as of' => Carbon::parse($batches->max('as_of_date'))->format('d M Y'),
        ], $batches->max('period_key'));

        $cap = (int) config('gwl.commercial_export_max_rows', 5000);

        if ($report['row_count'] > $cap) {
            return back()->with('error', 'This export has '.number_format($report['row_count']).' rows, over the limit of '.number_format($cap).'. Narrow it to a district and try again.');
        }

        Audit::log(action: 'commercial.export_customer_summary_excel', module: Permission::MODULE_COMMERCIAL, metadata: ['districts' => $batches->count(), 'rows' => $report['row_count']]);

        return Excel::download(new CommercialReportExport($report, now()), 'commercial_customer_summary_'.now()->format('Ymd').'.xlsx');
    }

    protected function listExport(Request $request, $batches, ?int $restriction, CustomerExportService $exports, CustomerListService $lists, ?int $districtId)
    {
        abort_if($districtId === null, 404);

        $details = $request->user()->hasRoles('super_admin') || $request->user()->hasPermission('commercial.view_customer_details');
        $filters = [
            'district_id' => $districtId,
            'route_id' => ctype_digit((string) $request->query('route')) && $request->query('route') !== '' ? (int) $request->query('route') : null,
            'group' => is_string($request->query('group')) && $request->query('group') !== '' ? (string) $request->query('group') : null,
            'status_id' => ctype_digit((string) $request->query('status')) && $request->query('status') !== '' ? (int) $request->query('status') : null,
            'meter_status_id' => ctype_digit((string) $request->query('meter')) && $request->query('meter') !== '' ? (int) $request->query('meter') : null,
            'bucket' => ctype_digit((string) $request->query('bucket')) && $request->query('bucket') !== '' ? (int) $request->query('bucket') : null,
            'sort' => (string) $request->query('sort', 'route'),
            'issue' => is_string($request->query('issue')) && $request->query('issue') !== '' ? (string) $request->query('issue') : null,
            'missing' => (bool) $request->query('missing'),
            'as_of' => Carbon::parse($batches[$districtId]->as_of_date)->toDateString(),
        ];

        $cap = (int) config('gwl.commercial_customer_export_max_rows', 100000);
        $estimate = $exports->estimate($filters + ['restriction' => $restriction]);

        if ($estimate !== null && $estimate > $cap) {
            return back()->with('error', 'This list has about '.number_format($estimate).' customers, over the export limit of '.number_format($cap).'. Narrow it to a route or a category group and try again.');
        }

        // Anything that may be big is built in the background and handed over when ready.
        if ($estimate === null || $estimate > (int) config('gwl.commercial_customer_export_queue_threshold', 20000)) {
            BuildCustomerListExport::dispatch($request->user()->id, $filters);

            return back()->with('status', 'This list is large, so it is being built in the background. You will get a notification with the download link when it is ready.');
        }

        $path = storage_path('app/private/commercial-export-'.uniqid().'.xlsx');
        @mkdir(dirname($path), 0775, true);
        $result = $exports->writeList($path, $filters + ['restriction' => $restriction], $details, $cap, $lists);

        Audit::log(action: 'commercial.export_customer_list_excel', module: Permission::MODULE_COMMERCIAL, metadata: ['filters' => $filters, 'rows' => $result['rows'], 'truncated' => $result['truncated'], 'with_details' => $details, 'queued' => false]);

        return response()->download($path, 'customer-list-'.now()->format('Ymd-His').'.xlsx')->deleteFileAfterSend(true);
    }

    /** A finished background export, handed only to the user who asked for it. */
    public function download(Request $request, string $token)
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);
        abort_unless(config('gwl.commercial_customer_list_enabled'), 404);

        $entry = Cache::get('commercial-customer-export:'.$token);
        abort_unless(is_array($entry) && (int) $entry['user_id'] === (int) $request->user()->id && Storage::disk('local')->exists($entry['path']), 404);

        return Storage::disk('local')->download($entry['path'], $entry['name']);
    }
}
