<?php

namespace App\Livewire\Assets\Concerns;

use App\Models\IctAsset;
use App\Models\IctRack;
use App\Services\Assets\RackService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;

/**
 * The optional Rack / Starting U / U height fields of the Assets form (servers) and the Network form (routers and
 * switches): IctAsset::RACK_ASSET_TYPES decides which types carry them, exactly as UPS_ASSET_TYPES does for the UPS.
 * Only the choreography lives here; the rules that matter (the U range fits the rack, it overlaps no other device, the
 * rack is in the device's region, a type with no rack drops its position) are AssetRecordService's, so they hold for
 * every writer. Needs a CATEGORY constant, a `form` array and ScopesAssetsByActor.
 *
 * A person who may not see racks (`assets.view_racks`) is not shown the fields and cannot change a placement through
 * the form even with a hand-made request: rackPayload() takes the keys out of what is saved, so the stored position
 * stays as it was.
 */
trait HandlesRackPlacement
{
    /** The form's three rack keys for an asset being edited, or for a blank form. @return array<string, int|null> */
    protected function rackFormValues(?IctAsset $asset = null): array
    {
        return [
            'rack_id' => $asset?->rack_id,
            'rack_unit' => $asset?->rack_unit,
            'rack_unit_span' => $asset?->rack_unit_span ?: 1,
        ];
    }

    /** Whether the type picked in the form is one that sits in a rack, whoever is asking. */
    protected function formTypeSupportsRack(): bool
    {
        return IctAsset::supportsRack(self::CATEGORY, (string) ($this->form['asset_type'] ?? ''));
    }

    /** Whether the fields are on offer: a rack-mounted type, and a person who may look at racks. */
    protected function formSupportsRack(): bool
    {
        return $this->formTypeSupportsRack() && RackService::mayView($this->actor());
    }

    /** The rack the asset being edited sits in now (it stays selectable even if that rack has since been decommissioned). */
    protected function currentRackId(): ?int
    {
        return $this->editingAssetId
            ? IctAsset::query()->whereKey($this->editingAssetId)->value('rack_id')
            : null;
    }

    /**
     * Validation of the three fields: a rack of the actor's own region (the device is stamped with it) or the one the
     * device is already in; a starting U and a height whenever a rack is chosen.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rackFormRules(): array
    {
        $region = $this->actorRegionId() ?? 0;
        $current = $this->currentRackId() ?? 0;
        $chosen = fn () => $this->formSupportsRack() && filled($this->form['rack_id'] ?? null);

        return [
            'form.rack_id' => ['nullable', 'integer', Rule::exists('ict_racks', 'id')->where(
                fn ($query) => $query->where('region_id', $region)->orWhere('id', $current)
            )],
            'form.rack_unit' => [Rule::requiredIf($chosen), 'nullable', 'integer', 'min:1', 'max:'.IctRack::MAX_UNITS],
            'form.rack_unit_span' => [Rule::requiredIf($chosen), 'nullable', 'integer', 'min:1', 'max:'.IctRack::MAX_UNITS],
        ];
    }

    /** @return array<string, string> */
    protected function rackFormMessages(): array
    {
        return [
            'form.rack_id.exists' => 'Pick a rack from the list.',
            'form.rack_unit.required' => 'Enter the starting U (1 is the bottom of the rack).',
            'form.rack_unit_span.required' => 'Enter how many U the device is high.',
        ];
    }

    /** @return array<string, string> */
    protected function rackFormAttributes(): array
    {
        return [
            'form.rack_id' => 'rack',
            'form.rack_unit' => 'starting U',
            'form.rack_unit_span' => 'U height',
        ];
    }

    /**
     * What is handed to AssetRecordService::save(): the validated form, with the rack keys taken out for a person who is
     * not shown them (the placement then stays exactly as it was).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function rackPayload(array $validated): array
    {
        if (! RackService::mayView($this->actor())) {
            unset($validated['rack_id'], $validated['rack_unit'], $validated['rack_unit_span']);
        }

        return $validated;
    }

    /** Picking a type that sits in no rack drops whatever was entered for one. Call it when `form.asset_type` changes. */
    protected function clearRackFieldsUnlessSupported(): void
    {
        if (! $this->formTypeSupportsRack()) {
            $this->form['rack_id'] = null;
            $this->form['rack_unit'] = null;
            $this->form['rack_unit_span'] = 1;
        }
    }

    /** Choosing "No rack" drops the position that went with it. */
    public function updatedFormRackId($value): void
    {
        if (blank($value)) {
            $this->form['rack_id'] = null;
            $this->form['rack_unit'] = null;
            $this->form['rack_unit_span'] = 1;
        }
    }

    /**
     * Racks the select offers: the actor's own region (the device is stamped with it), not decommissioned, and always the
     * rack the device is in now.
     *
     * @return Collection<int, IctRack>
     */
    protected function rackOptions(): Collection
    {
        $current = $this->currentRackId() ?? 0;

        return IctRack::query()
            ->where('region_id', $this->actorRegionId() ?? 0)
            ->where(fn ($query) => $query->where('status', '!=', IctRack::STATUS_DECOMMISSIONED)->orWhere('id', $current))
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'location', 'total_units', 'status']);
    }

    /** @return array<string, mixed> */
    protected function rackViewData(): array
    {
        $supports = $this->formSupportsRack();

        return [
            'formSupportsRack' => $supports,
            'rackOptions' => $supports ? $this->rackOptions() : new Collection,
        ];
    }
}
