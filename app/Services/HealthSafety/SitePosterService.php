<?php

namespace App\Services\HealthSafety;

use App\Models\HsSite;
use App\Models\User;
use App\Services\HealthSafety\Concerns\RendersLabelPdf;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One A4 poster per site: a large QR that opens the incident report form with that site already chosen (the form's own
 * `?site=` handling does the choosing and checks it again), the site name and district, and "Report an incident or near
 * miss". Master-data work (manage_master_data); sites outside the actor's part of the register are left out and counted.
 */
class SitePosterService
{
    use RendersLabelPdf;

    public function __construct(
        protected EquipmentScope $scope,
        protected QrCodeGenerator $qr,
        protected QrLinks $links,
    ) {}

    /**
     * @param  list<int|string>  $ids
     * @return array{pdf: string, count: int, excluded: int, filename: string}
     */
    public function print(User $actor, array $ids): array
    {
        abort_unless($this->scope->can($actor, 'health_safety.manage_master_data'), 403, 'You may not print site posters.');

        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->unique()->values();

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['posters' => 'Choose at least one site to print a poster for.']);
        }

        if ($ids->count() > EquipmentLabelService::max()) {
            throw ValidationException::withMessages(['posters' => 'A PDF holds at most '.EquipmentLabelService::max().' posters. Select fewer sites.']);
        }

        $sites = HsSite::query()
            ->active()
            ->whereIn('id', $ids->all())
            ->with(['district', 'region'])
            ->orderBy('name')
            ->get()
            ->filter(fn (HsSite $site) => $this->scope->contains($actor, $site))
            ->values();

        $excluded = $ids->count() - $sites->count();

        if ($sites->isEmpty()) {
            throw ValidationException::withMessages(['posters' => 'None of those sites can be printed from your part of the register.']);
        }

        $posters = $sites->map(fn (HsSite $site) => [
            'qr' => $this->qr->dataUri($this->links->reportUrl($site->id)),
            'name' => $site->name,
            'district' => $site->district?->district_name,
            'region' => $site->region?->region_name,
            'kind' => $site->kindLabel(),
        ])->all();

        $pdf = $this->renderPdf(view('health_safety.posters', ['posters' => $posters])->render());

        DB::transaction(function () use ($sites, $excluded) {
            Audit::log('health_safety.posters_printed', 'health_safety', 'hs_sites', null, [
                'count' => $sites->count(),
                'excluded' => $excluded,
                'first' => $sites->first()->name,
                'last' => $sites->last()->name,
                'base_url' => $this->links->baseUrl(),
            ]);
        });

        return [
            'pdf' => $pdf,
            'count' => $sites->count(),
            'excluded' => $excluded,
            'filename' => 'site-posters-'.now()->format('Ymd-His').'.pdf',
        ];
    }
}
