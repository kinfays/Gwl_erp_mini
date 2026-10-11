<?php

namespace App\Services\GWater;

use App\Models\GwReminderState;
use App\Models\GwSupplierPayment;
use App\Models\Region;
use App\Models\User;
use App\Notifications\GeneralDatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tells the G-Water users of a location, in-app, when a product's stock is at or below its low-stock threshold or a payment
 * request to G Water is overdue. Run daily by `php artisan gwater:remind` (registered, and scheduled, only while the G-Water
 * module flag is on; it needs the scheduler to be running).
 *
 *  - the thresholds and intervals are the location's own settings (GwaterSettings), else the config defaults;
 *  - one item (a product, a payment request) is reminded about at most once per repeat interval, whatever happens to it in
 *    between (gw_reminder_states);
 *  - a notice carries only the product, the location and counts: no reference, no amount, no name, no number of a person;
 *  - the people told are active staff based at that location who can record in G-Water (gwater.record); the developer
 *    super_admin account is never told.
 */
class GwaterReminderService
{
    public function __construct(
        protected GwaterVisibility $visibility,
        protected GwaterStockService $stock,
        protected GwaterSettings $settings,
    ) {}

    /**
     * @return array{enabled: bool, locations: int, low_stock: int, overdue: int, reminded: int, notifications: int, skipped: int, nobody: int}
     */
    public function remind(?Carbon $asOf = null, bool $dryRun = false): array
    {
        $asOf ??= now();
        $summary = ['enabled' => (bool) config('gwl.gwater_module_enabled'), 'locations' => 0, 'low_stock' => 0, 'overdue' => 0, 'reminded' => 0, 'notifications' => 0, 'skipped' => 0, 'nobody' => 0];

        if (! $summary['enabled']) {
            return $summary;
        }

        foreach ($this->locations() as $location) {
            $summary['locations']++;
            $recipients = $this->recipients($location);
            $label = $this->label($location);
            $repeat = max(1, $this->settings->value($location, GwaterSettings::REMINDER_REPEAT_DAYS));

            foreach ($this->settings->definitions() as $key => $definition) {
                if (! str_starts_with($key, 'low_stock_')) {
                    continue;
                }

                $product = \App\Models\GwProduct::query()->where('code', substr($key, strlen('low_stock_')))->first();

                if (! $product) {
                    continue;
                }

                $packs = $this->stock->balance($location, $product->id);
                $threshold = $this->settings->lowStock($location, $product);

                if ($packs > $threshold) {
                    continue;
                }

                $summary['low_stock']++;

                if (! $this->due($location, GwReminderState::LOW_STOCK, $product->id, $repeat, $asOf)) {
                    $summary['skipped']++;

                    continue;
                }

                $this->deliver($summary, $recipients, $dryRun, $location, GwReminderState::LOW_STOCK, [$product->id], $asOf,
                    'G-Water: low stock',
                    sprintf('%s is low at %s: %d pack(s) on hand (limit %d).', $product->name, $label, $packs, $threshold),
                    route('gwater.stock'),
                    ['kind' => 'gwater_low_stock', 'product' => $product->code]
                );
            }

            $overdueDays = max(1, $this->settings->value($location, GwaterSettings::PAYMENT_OVERDUE_DAYS));
            $overdue = $this->visibility->atLocation(GwSupplierPayment::query()->where('status', GwSupplierPayment::REQUESTED), $location)
                ->whereDate('request_date', '<=', $asOf->copy()->subDays($overdueDays)->toDateString())
                ->get();

            $summary['overdue'] += $overdue->count();
            $due = $overdue->filter(fn (GwSupplierPayment $payment) => $this->due($location, GwReminderState::PAYMENT_OVERDUE, $payment->id, $repeat, $asOf));
            $summary['skipped'] += $overdue->count() - $due->count();

            if ($due->isNotEmpty()) {
                $oldest = (int) $overdue->map(fn (GwSupplierPayment $payment) => $payment->request_date->diffInDays($asOf))->max();

                $this->deliver($summary, $recipients, $dryRun, $location, GwReminderState::PAYMENT_OVERDUE, $due->pluck('id')->all(), $asOf,
                    'G-Water: payment requests overdue',
                    sprintf('%d payment request(s) to G Water at %s are overdue (the oldest by %d days; the limit is %d days).', $overdue->count(), $label, $oldest, $overdueDays),
                    route('gwater.payments'),
                    ['kind' => 'gwater_payment_overdue', 'count' => $overdue->count()]
                );
            }
        }

        return $summary;
    }

    /**
     * Every location (Head Office, or a region without it) that has any G-Water records, once each, as a place to read the
     * books from.
     *
     * @return Collection<int, array{region_id: int, district_id: int|null}>
     */
    public function locations(): Collection
    {
        return collect(['gw_stock_movements', 'gw_supplier_payments', 'gw_opening_balances'])
            ->flatMap(fn (string $table) => DB::table($table)->select('region_id', 'district_id')->distinct()->get())
            ->map(fn (object $row) => ['region_id' => (int) $row->region_id, 'district_id' => $row->district_id !== null ? (int) $row->district_id : null])
            ->unique(fn (array $location) => GwaterBooksVersion::unitKey($location))
            ->values();
    }

    /** @return Collection<int, User> */
    public function recipients(array $location): Collection
    {
        return $this->visibility->peopleAt($location)->visibleInErp()->with(['roles.permissions'])->get()
            ->filter(fn (User $user) => $user->hasPermission('gwater.record') && ! $user->hasRoles('super_admin'))
            ->values();
    }

    // ------------------------------------------------------------------ internals

    /** Whether the item was never reminded about, or last longer ago than the repeat interval. */
    protected function due(array $location, string $kind, int $subjectId, int $repeatDays, Carbon $asOf): bool
    {
        $state = $this->visibility->atLocation(GwReminderState::query()->where('kind', $kind)->where('subject_id', $subjectId), $location)->orderByDesc('id')->first();

        return ! $state || $state->last_reminded_at->lte($asOf->copy()->subDays($repeatDays));
    }

    /**
     * Sends one notice to everybody at the location and records the items as reminded. With nobody to tell, nothing is recorded
     * (the item is reminded about as soon as someone can be told).
     *
     * @param  array<string, int>  $summary
     * @param  list<int>  $subjectIds
     */
    protected function deliver(array &$summary, Collection $recipients, bool $dryRun, array $location, string $kind, array $subjectIds, Carbon $asOf, string $title, string $message, string $url, array $meta): void
    {
        if ($recipients->isEmpty()) {
            $summary['nobody']++;

            return;
        }

        $summary['reminded']++;
        $summary['notifications'] += $recipients->count();

        if ($dryRun) {
            return;
        }

        $meta += ['region_id' => $location['region_id'], 'district_id' => $location['district_id']];

        foreach ($recipients as $user) {
            $user->notify(new GeneralDatabaseNotification($title, $message, $url, 'gwater', $meta));
        }

        foreach ($subjectIds as $subjectId) {
            $state = $this->visibility->atLocation(GwReminderState::query()->where('kind', $kind)->where('subject_id', $subjectId), $location)->orderByDesc('id')->first();

            $state
                ? $state->forceFill(['last_reminded_at' => $asOf])->save()
                : GwReminderState::query()->create([...$location, 'kind' => $kind, 'subject_id' => $subjectId, 'last_reminded_at' => $asOf]);
        }
    }

    protected function label(array $location): string
    {
        return $this->visibility->isHeadOffice($location) ? 'Head Office' : (Region::query()->whereKey($location['region_id'])->value('region_name') ?? 'your location');
    }
}
