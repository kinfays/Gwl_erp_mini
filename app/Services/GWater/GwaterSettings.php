<?php

namespace App\Services\GWater;

use App\Models\GwProduct;
use App\Models\GwSetting;
use Illuminate\Support\Collection;

/**
 * The few options a location may change: the low-stock threshold per product and the two reminder intervals. The defaults
 * are config/gwl.php (and .env); a location's own value is an OVERRIDE row in gw_settings for its books (Head Office, or a
 * region without it), and a value saved equal to its default is stored as no row at all, so "default" keeps meaning "whatever
 * config says". Read only here; written only by GwaterWorkflowService::saveSettings().
 */
class GwaterSettings
{
    public const PAYMENT_OVERDUE_DAYS = 'payment_overdue_days';

    public const REMINDER_REPEAT_DAYS = 'reminder_repeat_days';

    public function __construct(protected GwaterVisibility $visibility) {}

    /**
     * Every setting that can be overridden.
     *
     * @return array<string, array{label: string, help: string, unit: string, min: int, max: int, default: int}>
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach (GwProduct::query()->active()->orderBy('id')->get() as $product) {
            $definitions['low_stock_'.$product->code] = [
                'label' => 'Low stock: '.$product->name,
                'help' => 'Packs on hand at or below this are flagged on the Overview and reminded about.',
                'unit' => 'packs',
                'min' => 0,
                'max' => 1000000,
                'default' => (int) config('gwl.gwater_low_stock_packs'),
            ];
        }

        $definitions[self::PAYMENT_OVERDUE_DAYS] = [
            'label' => 'Payment request overdue after',
            'help' => 'A payment request to G Water still not paid after this many days is overdue.',
            'unit' => 'days',
            'min' => 1,
            'max' => 365,
            'default' => (int) config('gwl.gwater_payment_overdue_days'),
        ];

        $definitions[self::REMINDER_REPEAT_DAYS] = [
            'label' => 'Remind again after',
            'help' => 'The same low stock or overdue request is reminded about at most once in this many days.',
            'unit' => 'days',
            'min' => 1,
            'max' => 365,
            'default' => (int) config('gwl.gwater_reminder_repeat_days'),
        ];

        return $definitions;
    }

    /** The location's own value, else the default. @param array{region_id: int|null, district_id: int|null} $location */
    public function value(array $location, string $key): int
    {
        $definition = $this->definitions()[$key] ?? throw new \InvalidArgumentException("Unknown G-Water setting {$key}.");

        return $this->overrides($location)->firstWhere('key', $key)?->value ?? $definition['default'];
    }

    public function lowStock(array $location, GwProduct|string $product): int
    {
        return $this->value($location, 'low_stock_'.($product instanceof GwProduct ? $product->code : $product));
    }

    /**
     * Each setting with its current value, its default and whether the location changed it.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(array $location): array
    {
        $overrides = $this->overrides($location);

        return collect($this->definitions())->map(function (array $definition, string $key) use ($overrides) {
            $override = $overrides->firstWhere('key', $key);

            return $definition + ['value' => $override?->value ?? $definition['default'], 'overridden' => $override !== null];
        })->all();
    }

    /** @return Collection<int, GwSetting> */
    public function overrides(array $location): Collection
    {
        return $this->visibility->atLocation(GwSetting::query(), $location)->orderBy('id')->get();
    }
}
