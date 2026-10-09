<?php

namespace App\Jobs\Commercial;

use App\Models\Permission;
use App\Models\User;
use App\Notifications\GeneralDatabaseNotification;
use App\Services\Commercial\Customers\CustomerExportService;
use App\Services\Commercial\Customers\CustomerListService;
use App\Services\Commercial\Customers\CustomerScope;
use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A big customer list exported in the background. Scope and permissions are decided AGAIN here from the user record (a job
 * has no session): the region restriction from CustomerScope, the name / address columns only if the user still holds
 * commercial.view_customer_details. The finished file lives on the private disk for a week and is handed out only to the user
 * who asked, through commercial.customers.download. It carries the filters and counts, never customer data, in the audit log.
 */
class BuildCustomerListExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    /** @param  array<string, mixed>  $filters  CustomerListService filters WITHOUT the restriction (decided here) */
    public function __construct(public readonly int $userId, public readonly array $filters) {}

    public function handle(CustomerExportService $exports, CustomerListService $lists): void
    {
        $user = User::query()->find($this->userId);

        if (! $user || ! ($user->hasRoles('super_admin') || $user->hasPermission('commercial.export_reports'))) {
            return;
        }

        $details = $user->hasRoles('super_admin') || $user->hasPermission('commercial.view_customer_details');
        $q = $this->filters + ['restriction' => CustomerScope::regionRestriction($user)];
        $token = (string) Str::uuid();
        $path = 'commercial/customer-exports/'.$token.'.xlsx';
        Storage::disk('local')->makeDirectory('commercial/customer-exports');

        $result = $exports->writeList(Storage::disk('local')->path($path), $q, $details, (int) config('gwl.commercial_customer_export_max_rows', 100000), $lists);
        Cache::put('commercial-customer-export:'.$token, ['user_id' => $user->id, 'path' => $path, 'name' => 'customer-list-'.now()->format('Ymd-His').'.xlsx'], now()->addDays(7));

        Auth::setUser($user);
        Audit::log(action: 'commercial.export_customer_list_excel', module: Permission::MODULE_COMMERCIAL, metadata: ['filters' => array_diff_key($this->filters, ['search' => 1]), 'rows' => $result['rows'], 'truncated' => $result['truncated'], 'with_details' => $details, 'queued' => true]);
        Auth::forgetUser();

        $user->notify(new GeneralDatabaseNotification(
            'Your customer list export is ready',
            number_format($result['rows']).' rows'.($result['truncated'] ? ' (limited by the export row cap)' : '').'. The file is kept for 7 days.',
            route('commercial.customers.download', $token),
            'commercial',
            ['kind' => 'commercial_customer_export_ready'],
        ));
    }
}
