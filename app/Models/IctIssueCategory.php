<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

/**
 * A top-level ICT issue category. ict_asset_issue_reports.issue_type stores the label as plain text, so a label is
 * never renamed or deleted once reports may use it: deactivate it instead.
 */
class IctIssueCategory extends Model
{
    /**
     * Categories whose asset picker is narrowed further by asset_type inside their device category. Keyed by label so
     * no third taxonomy table is needed; Phone and Network/Internet need no narrowing.
     */
    public const ASSET_TYPE_NARROWING = [
        'Computer' => ['PC', 'AIO', 'Laptop', 'Server'],
        'Printer/Photocopier' => ['PRT', 'PTC'],
    ];

    protected $table = 'ict_issue_categories';

    protected $fillable = ['label', 'requires_linked_asset', 'maps_to_device_category', 'is_active', 'sort_order'];

    protected $casts = [
        'requires_linked_asset' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function subtypes(): HasMany
    {
        return $this->hasMany(IctIssueSubtype::class, 'ict_issue_category_id')->orderBy('sort_order')->orderBy('id');
    }

    public function activeSubtypes(): HasMany
    {
        return $this->subtypes()->where('is_active', true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /** The asset_type values a linked asset may have for this category, or null for "any type in the device category". */
    public function narrowedAssetTypes(): ?array
    {
        return self::ASSET_TYPE_NARROWING[$this->label] ?? null;
    }

    /** Labels of the active categories in display order; the old fixed list if the table is missing or empty. */
    public static function activeLabels(): array
    {
        if (Schema::hasTable('ict_issue_categories')) {
            $labels = self::query()->active()->ordered()->pluck('label')->all();

            if ($labels !== []) {
                return $labels;
            }
        }

        return IctAssetIssueReport::ISSUE_TYPES;
    }

    /** Every label ever offered (active or not) in display order, for filters and summaries over historical data. */
    public static function allLabels(): array
    {
        return Schema::hasTable('ict_issue_categories')
            ? self::query()->ordered()->pluck('label')->all()
            : [];
    }
}
