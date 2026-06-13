<?php

namespace App\Providers;

use App\Events\Transport\DocumentExpiryDetected;
use App\Events\Transport\MaintenanceDue;
use App\Events\Transport\VehicleIssueReported;
use App\Listeners\Transport\NotifyTransportManagersOfDocumentExpiry;
use App\Listeners\Transport\NotifyTransportManagersOfIssue;
use App\Listeners\Transport\NotifyTransportManagersOfMaintenanceDue;
use App\Models\Employee;
use App\Models\MileageLog;
use App\Observers\EmployeeObserver;
use App\Observers\MileageLogObserver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Employee::observe(EmployeeObserver::class);
        MileageLog::observe(MileageLogObserver::class);

        Event::listen(VehicleIssueReported::class, NotifyTransportManagersOfIssue::class);
        Event::listen(MaintenanceDue::class, NotifyTransportManagersOfMaintenanceDue::class);
        Event::listen(DocumentExpiryDetected::class, NotifyTransportManagersOfDocumentExpiry::class);

        URL::forceScheme('https');
        
    }
}
