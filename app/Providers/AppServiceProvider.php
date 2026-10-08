<?php

namespace App\Providers;

use App\Events\HealthSafety\IncidentReported;
use App\Events\HealthSafety\IncidentSeverityRaised;
use App\Events\HealthSafety\IncidentStatusChanged;
use App\Events\Transport\DocumentExpiryDetected;
use App\Events\Transport\MaintenanceDue;
use App\Events\Transport\VehicleIssueReported;
use App\Listeners\HealthSafety\NotifyIncidentStakeholders;
use App\Listeners\Transport\NotifyTransportManagersOfDocumentExpiry;
use App\Listeners\Transport\NotifyTransportManagersOfIssue;
use App\Listeners\Transport\NotifyTransportManagersOfMaintenanceDue;
use App\Models\Employee;
use App\Models\MileageLog;
use App\Observers\EmployeeObserver;
use App\Observers\MileageLogObserver;
use App\Services\Assets\Mdm\AndroidManagementClient;
use App\Services\Assets\Mdm\AndroidManagementGateway;
use App\Services\Assets\Mdm\GoogleOidcTokenVerifier;
use App\Services\Assets\Mdm\IdTokenVerifier;
use App\Services\Commercial\CommercialSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Android Enterprise (MDM). Tests rebind AndroidManagementGateway / IdTokenVerifier to fakes; nothing in the
        // suite may reach Google. The client builds its Google connection lazily, so resolving it needs no credentials.
        $this->app->singleton(AndroidManagementGateway::class, AndroidManagementClient::class);
        $this->app->bind(IdTokenVerifier::class, GoogleOidcTokenVerifier::class);

        // Commercial settings keep a pristine copy of the config (the defaults) for the life of the request, so the one
        // instance must be shared between the boot hook below and everything that asks for it later.
        $this->app->singleton(CommercialSettings::class);
        // One instance per application: it reads the saved Health & Safety settings once and forgets them on a save.
        $this->app->singleton(\App\Services\HealthSafety\HealthSafetySettings::class);
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

        Event::listen(IncidentReported::class, [NotifyIncidentStakeholders::class, 'onReported']);
        Event::listen(IncidentSeverityRaised::class, [NotifyIncidentStakeholders::class, 'onSeverityRaised']);
        Event::listen(IncidentStatusChanged::class, [NotifyIncidentStakeholders::class, 'onStatusChanged']);

        URL::forceScheme('https');

        // Commercial: targets and thresholds edited on the settings screen are laid over config('gwl.*'), so every
        // analysis and export sees them. Only while the module is on; quietly nothing before its table exists.
        if (config('gwl.commercial_module_enabled')) {
            $this->app->make(CommercialSettings::class)->applyToConfig();
        }

        // Google's Pub/Sub push deliveries come from a small set of addresses and can burst after an outage.
        RateLimiter::for('mdm-webhook', fn (Request $request) => Limit::perMinute(max(1, (int) config('gwl.mdm_webhook_rate_per_minute', 600)))->by($request->ip()));
    }
}
