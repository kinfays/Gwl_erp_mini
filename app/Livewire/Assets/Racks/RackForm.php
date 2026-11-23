<?php

namespace App\Livewire\Assets\Racks;

use App\Livewire\Assets\Concerns\ScopesAssetsByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\District;
use App\Models\IctRack;
use App\Services\Assets\RackService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Create or edit one rack (full page). Needs `assets.manage_racks`. A NEW rack is stamped with the actor's own region and
 * its district is one of that region; an EDITED rack keeps the region it was created in (resolved through the WRITE scope,
 * so another region's rack answers 404) and its district is one of that region. The rules that matter (unique name and
 * code, a height no shorter than the highest device in it, no decommissioning while devices are mounted) are RackService's.
 */
class RackForm extends Component
{
    use EnforcesModuleAccess;
    use ScopesAssetsByActor;

    /** The rack being edited; null on the create page. Locked, and resolved again inside the write scope on save. */
    #[Locked]
    public ?int $rackId = null;

    public array $form = [
        'name' => '',
        'code' => '',
        'district_id' => null,
        'location' => '',
        'total_units' => IctRack::DEFAULT_UNITS,
        'status' => IctRack::STATUS_ACTIVE,
        'notes' => '',
    ];

    public function mount(?int $rack = null): void
    {
        $this->enforceLivewireModule('assets');

        abort_unless(RackService::mayManage($this->actor()), 403, 'You do not have permission to manage racks.');

        if ($rack === null) {
            return;
        }

        $model = $this->scopeAssetsForActor(IctRack::query())->findOrFail($rack);

        $this->rackId = $model->id;
        $this->form = [
            'name' => (string) $model->name,
            'code' => (string) $model->code,
            'district_id' => $model->district_id,
            'location' => (string) $model->location,
            'total_units' => $model->total_units,
            'status' => (string) $model->status,
            'notes' => (string) $model->notes,
        ];
    }

    /** The region the rack lives in: its own when editing, the actor's when creating. */
    protected function formRegionId(): ?int
    {
        return $this->rackId
            ? IctRack::query()->whereKey($this->rackId)->value('region_id')
            : $this->actorRegionId();
    }

    public function save()
    {
        $actor = $this->actor();
        abort_unless(RackService::mayManage($actor), 403, 'You do not have permission to manage racks.');

        $regionId = $this->rackId ? $this->formRegionId() : $this->requireActorRegionId();

        $data = $this->validate($this->rules($regionId), $this->messages(), $this->validationAttributes())['form'];

        $service = app(RackService::class);

        try {
            if ($this->rackId) {
                $rack = $this->scopeAssetsForActor(IctRack::query())->findOrFail($this->rackId);
                $rack = $service->update($rack, $data, $actor);
                $message = 'Rack updated.';
            } else {
                $rack = $service->create([...$data, 'region_id' => $regionId], $actor);
                $message = 'Rack created.';
            }
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        session()->flash('status', $message);

        return $this->redirectRoute('assets.racks.show', $rack->id, navigate: false);
    }

    /** @return array<string, array<int, mixed>> */
    protected function rules(?int $regionId): array
    {
        return [
            'form.name' => ['required', 'string', 'max:255'],
            'form.code' => ['nullable', 'string', 'max:60'],
            'form.district_id' => ['required', 'integer', Rule::exists('districts', 'id')->where('region_id', $regionId ?? 0)],
            'form.location' => ['nullable', 'string', 'max:255'],
            'form.total_units' => ['required', 'integer', 'min:1', 'max:'.IctRack::MAX_UNITS],
            'form.status' => ['required', Rule::in(IctRack::STATUSES)],
            'form.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'form.district_id.exists' => 'The selected district is not in this rack\'s region.',
            'form.total_units.min' => 'A rack is at least 1 U high.',
            'form.total_units.max' => 'A rack is at most '.IctRack::MAX_UNITS.' U high.',
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'form.name' => 'name',
            'form.code' => 'code',
            'form.district_id' => 'district',
            'form.location' => 'location',
            'form.total_units' => 'height',
            'form.status' => 'status',
            'form.notes' => 'notes',
        ];
    }

    public function render()
    {
        $regionId = $this->formRegionId();

        return view('livewire.assets.racks.rack-form', [
            'editing' => $this->rackId !== null,
            'statuses' => IctRack::STATUSES,
            'districts' => District::query()->where('region_id', $regionId ?? 0)->orderBy('district_name')->get(),
            'region' => $this->rackId
                ? IctRack::query()->with('region')->find($this->rackId)?->region
                : $this->actorRegion(),
        ]);
    }
}
