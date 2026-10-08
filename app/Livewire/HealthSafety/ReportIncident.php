<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\Department;
use App\Models\District;
use App\Models\HsIncident;
use App\Models\HsSite;
use App\Models\Permission;
use App\Services\HealthSafety\HealthSafetySettings;
use App\Services\HealthSafety\IncidentWorkflowService;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The report form that replaces the four Microsoft Forms (design section 3.1): where it happened, which place, what
 * kind of report, when, what happened, first aid, the witness, optional photos. About six required fields, on a phone.
 */
class ReportIncident extends Component
{
    use EnforcesModuleAccess;
    use ScopesHealthSafetyByActor;
    use WithFileUploads;

    public string $context = '';

    public ?int $departmentId = null;

    public ?int $districtId = null;

    /** '' (nothing chosen), a pay point's id, or 'other' (typed below). */
    public string $siteId = '';

    public string $siteNameRaw = '';

    public string $locationDetail = '';

    public string $incidentType = '';

    public string $otherTypeText = '';

    public string $occurredOn = '';

    public string $occurredTime = '';

    public string $description = '';

    public string $firstAid = '';

    public string $witnessName = '';

    public string $witnessContact = '';

    public bool $noWitness = false;

    public bool $confidential = false;

    /** Nothing in the database links the report to the person filing it (and no feedback can reach them). */
    public bool $anonymous = false;

    public bool $urgent = false;

    public bool $onBehalf = false;

    public string $behalfSearch = '';

    public ?int $behalfEmployeeId = null;

    public string $behalfName = '';

    /** @var array<int, mixed> */
    public array $photos = [];

    #[Locked]
    public ?int $submittedId = null;

    #[Locked]
    public ?string $submittedReference = null;

    #[Locked]
    public bool $submittedAnonymous = false;

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);
        $this->guardHealthSafetyPermission('health_safety.report_incident');

        $this->resetForm();
        $this->applySiteFromLink(request()->query('site'));
    }

    /**
     * A scanned site poster opens the form as /report?site=<id>. It only PRE-FILLS the form (where it happened, the district,
     * the pay point); the submit validates everything as usual. An unknown, inactive, non-numeric or out-of-reach id is
     * ignored without a word, so the parameter reveals nothing about sites the person cannot use.
     */
    protected function applySiteFromLink(mixed $value): void
    {
        if (! is_string($value) || ! ctype_digit($value)) {
            return;
        }

        $site = HsSite::query()->active()->find((int) $value);

        if (! $site) {
            return;
        }

        $inReach = $this->actorSeesAllRegions() || ($this->actorRegionId() !== null && (int) $site->region_id === $this->actorRegionId());

        if (! $inReach) {
            return;
        }

        $districtReachable = $site->district_id !== null && $this->districtOptions()->contains('id', (int) $site->district_id);

        switch ($site->kind) {
            case HsSite::KIND_PAY_POINT:
                if ($districtReachable) {
                    $this->context = HsIncident::CONTEXT_PAY_POINT;
                    $this->districtId = (int) $site->district_id;
                    $this->siteId = (string) $site->id;
                }
                break;
            case HsSite::KIND_DISTRICT_OFFICE:
                if ($districtReachable) {
                    $this->context = HsIncident::CONTEXT_DISTRICT_OFFICE;
                    $this->districtId = (int) $site->district_id;
                }
                break;
            case HsSite::KIND_REGIONAL_OFFICE:
                $this->context = HsIncident::CONTEXT_REGIONAL_OFFICE;
                break;
        }
    }

    public function updatedContext(): void
    {
        $this->resetErrorBag();
        $this->siteId = '';
        $this->siteNameRaw = '';
    }

    public function updatedDistrictId(): void
    {
        $this->siteId = '';
    }

    public function updatedNoWitness(bool $value): void
    {
        if ($value) {
            $this->witnessName = '';
            $this->witnessContact = '';
        }
    }

    /** An anonymous report has no reporter to keep confidential and nobody it is "for": the other two ticks are cleared. */
    public function updatedAnonymous(bool $value): void
    {
        if ($value) {
            $this->confidential = false;
            $this->onBehalf = false;
            $this->behalfEmployeeId = null;
            $this->behalfName = '';
            $this->behalfSearch = '';
        }
    }

    public function updatedOnBehalf(bool $value): void
    {
        if (! $value || $this->anonymous || ! $this->actorCan('health_safety.record_on_behalf')) {
            $this->onBehalf = false;
            $this->behalfEmployeeId = null;
            $this->behalfName = '';
            $this->behalfSearch = '';
        }
    }

    public function chooseBehalf(int $employeeId): void
    {
        $employee = $this->employeeMatches($this->behalfSearch, 50)->firstWhere('id', $employeeId);

        if ($employee) {
            $this->behalfEmployeeId = $employee->id;
            $this->behalfName = $employee->full_name;
            $this->behalfSearch = '';
        }
    }

    public function clearBehalf(): void
    {
        $this->behalfEmployeeId = null;
        $this->behalfName = '';
    }

    public function submit(IncidentWorkflowService $workflow): void
    {
        $this->guardHealthSafetyPermission('health_safety.report_incident');

        if ($this->anonymous) {
            // Never both: an anonymous report is nobody's, so it cannot be "for" someone or keep a reporter confidential.
            $this->onBehalf = false;
            $this->confidential = false;
        }

        if ($this->onBehalf) {
            $this->guardHealthSafetyPermission('health_safety.record_on_behalf');
        }

        $this->validate($this->rules(), $this->messages(), $this->validationAttributes());
        $this->guardTimeNotInFuture();

        $incident = $workflow->submit($this->actor(), [
            'context' => $this->context,
            'department_id' => $this->departmentId,
            'district_id' => $this->districtId,
            'site_id' => in_array($this->siteId, ['', 'other'], true) ? null : (int) $this->siteId,
            'site_name_raw' => trim($this->siteNameRaw),
            'location_detail' => trim($this->locationDetail) ?: null,
            'incident_type' => $this->incidentType,
            'other_type_text' => trim($this->otherTypeText) ?: null,
            'occurred_on' => $this->occurredOn,
            'occurred_time' => $this->occurredTime ?: null,
            'description' => $this->description,
            'first_aid' => $this->firstAid,
            'witness_name' => trim($this->witnessName) ?: null,
            'witness_contact' => trim($this->witnessContact) ?: null,
            'no_witness' => $this->noWitness,
            'is_confidential' => $this->confidential,
            'is_urgent' => $this->urgent,
            'anonymous' => $this->anonymous,
            'on_behalf' => $this->onBehalf,
            'behalf_employee_id' => $this->behalfEmployeeId,
            'behalf_name' => $this->behalfName,
        ], array_values($this->photos));

        $this->submittedId = $incident->id;
        $this->submittedReference = $incident->reference;
        $this->submittedAnonymous = (bool) $incident->is_anonymous;
    }

    public function reportAnother(): void
    {
        $this->resetForm();
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        $maxKb = (int) config('gwl.hs_attachment_max_mb') * 1024;
        $districtIds = $this->districtOptions()->pluck('id')->all();

        $rules = [
            'context' => ['required', Rule::in(array_keys(HsIncident::CONTEXTS))],
            'incidentType' => ['required', Rule::in(array_keys(HsIncident::TYPES))],
            'otherTypeText' => ['nullable', 'string', 'max:120', Rule::requiredIf($this->incidentType === HsIncident::TYPE_OTHER)],
            'occurredOn' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'occurredTime' => ['nullable', 'date_format:H:i'],
            'description' => ['required', 'string', 'min:3', 'max:5000'],
            'firstAid' => ['required', Rule::in(array_keys(HsIncident::FIRST_AID))],
            'witnessName' => ['nullable', 'string', 'max:150'],
            'witnessContact' => ['nullable', 'string', 'max:150'],
            'photos' => ['array', 'max:'.(int) config('gwl.hs_attachments_per_incident')],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.$maxKb],
        ];

        $rules += match ($this->context) {
            HsIncident::CONTEXT_REGIONAL_OFFICE => [
                'departmentId' => ['required', 'integer', Rule::exists('departments', 'id')],
            ],
            HsIncident::CONTEXT_DISTRICT_OFFICE => [
                'districtId' => ['required', 'integer', Rule::in($districtIds)],
            ],
            HsIncident::CONTEXT_PAY_POINT => [
                'districtId' => ['required', 'integer', Rule::in($districtIds)],
                'siteId' => ['nullable', 'string', Rule::in(['', 'other', ...$this->siteOptions()->pluck('id')->map(fn ($id) => (string) $id)->all()])],
                'siteNameRaw' => [Rule::requiredIf(in_array($this->siteId, ['', 'other'], true)), 'nullable', 'string', 'max:150'],
            ],
            HsIncident::CONTEXT_FIELD_WORK => [
                'districtId' => ['required', 'integer', Rule::in($districtIds)],
                'locationDetail' => ['required', 'string', 'max:255'],
            ],
            default => [],
        };

        if ($this->onBehalf) {
            $rules['behalfName'] = ['required', 'string', 'max:150'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'context.required' => 'Choose where it happened.',
            'incidentType.required' => 'Choose what you are reporting.',
            'description.required' => 'Say what happened.',
            'firstAid.required' => 'Say whether first aid was given.',
            'occurredOn.before_or_equal' => 'The date cannot be in the future.',
            'departmentId.required' => 'Choose the department.',
            'districtId.required' => 'Choose the district.',
            'districtId.in' => 'Choose a district from the list.',
            'siteNameRaw.required' => 'Choose the pay point, or type its name.',
            'locationDetail.required' => 'Say where exactly (street, landmark).',
            'behalfName.required' => 'Say who this report is for.',
            'otherTypeText.required' => 'Say what kind of report this is.',
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'photos.*' => 'photo',
            'occurredOn' => 'date',
            'occurredTime' => 'time',
        ];
    }

    /** A time earlier today is fine; one still to come is not. */
    protected function guardTimeNotInFuture(): void
    {
        if ($this->occurredTime !== '' && $this->occurredOn === today()->toDateString() && $this->occurredTime > now()->format('H:i')) {
            throw ValidationException::withMessages(['occurredTime' => 'The time cannot be in the future.']);
        }
    }

    protected function resetForm(): void
    {
        $employee = $this->actorEmployee();

        $this->reset([
            'context', 'siteId', 'siteNameRaw', 'locationDetail', 'incidentType', 'otherTypeText', 'occurredTime', 'description',
            'firstAid', 'witnessName', 'witnessContact', 'noWitness', 'confidential', 'anonymous', 'urgent', 'onBehalf', 'behalfSearch',
            'behalfEmployeeId', 'behalfName', 'photos', 'submittedId', 'submittedReference', 'submittedAnonymous',
        ]);
        $this->resetErrorBag();

        $this->occurredOn = today()->toDateString();
        $this->departmentId = $employee?->department_id;
        $this->districtId = $employee?->district_id && $this->districtOptions()->contains('id', $employee->district_id)
            ? (int) $employee->district_id
            : null;
    }

    /** @return Collection<int, District> */
    protected function districtOptions(): Collection
    {
        return District::query()
            ->when(! $this->actorSeesAllRegions(), fn ($query) => $query->where('region_id', $this->actorRegionId() ?? 0))
            ->orderBy('district_name')
            ->get(['id', 'district_name', 'region_id']);
    }

    /** @return Collection<int, HsSite> Active pay points in the chosen district. */
    protected function siteOptions(): Collection
    {
        if (! $this->districtId) {
            return collect();
        }

        return HsSite::query()
            ->active()
            ->where('kind', HsSite::KIND_PAY_POINT)
            ->where('district_id', $this->districtId)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** @return list<array{label: string, number: string, tel: string}> */
    protected function emergencyContacts(): array
    {
        return collect(explode('|', (string) HealthSafetySettings::value('hs_emergency_contacts')))
            ->map(fn (string $entry) => trim($entry))
            ->filter()
            ->map(function (string $entry) {
                [$label, $number] = array_pad(array_map('trim', explode(':', $entry, 2)), 2, '');

                return ['label' => $number === '' ? 'Emergency' : $label, 'number' => $number === '' ? $label : $number, 'tel' => preg_replace('/[^0-9+]/', '', $number === '' ? $label : $number)];
            })
            ->values()
            ->all();
    }

    public function render()
    {
        return view('livewire.health_safety.report-incident', [
            'contexts' => HsIncident::CONTEXTS,
            'types' => HsIncident::TYPES,
            'typeHelp' => HsIncident::TYPE_HELP,
            'firstAidOptions' => HsIncident::FIRST_AID,
            'departments' => Department::query()->orderBy('department_name')->get(['id', 'department_name']),
            'districts' => $this->districtOptions(),
            'sites' => $this->siteOptions(),
            'behalfMatches' => $this->onBehalf ? $this->employeeMatches($this->behalfSearch) : collect(),
            'canRecordOnBehalf' => $this->actorCan('health_safety.record_on_behalf'),
            'emergency' => $this->emergencyContacts(),
            'maxMb' => (int) config('gwl.hs_attachment_max_mb'),
            'maxPhotos' => (int) config('gwl.hs_attachments_per_incident'),
            'today' => today()->toDateString(),
        ]);
    }
}
