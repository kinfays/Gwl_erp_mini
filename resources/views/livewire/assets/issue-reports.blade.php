<div>
    <x-ui.page-header title="Issue Reporting" description="Capture field issues and resolution lifecycle per district or region.">
        <x-slot:actions>
            <button type="button" wire:click="openCreate" class="btn btn-primary">
                <x-ui.icon name="plus" />
                New Report
            </button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card title="Reported Issues" :padded="false">
        <div class="ui-toolbar" role="search" aria-label="Filter issue reports">
            <div class="ui-input-wrap toolbar-grow">
                <x-ui.icon name="search" class="ui-input-icon" />
                <input type="text" wire:model.live="search" class="form-input ui-input has-icon" placeholder="Search issue, reason, linked asset" aria-label="Search issue reports">
            </div>
            <select wire:model.live="status" class="form-input" aria-label="Status">
                <option value="">All statuses</option>
                @foreach ($statusOptions as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
            <select wire:model.live="type" class="form-input" aria-label="Issue type">
                <option value="">All issue types</option>
                @foreach ($typeOptions as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
        </div>

        <div class="ui-loading-host">
            <x-ui.table label="Reported issues" pin-first>
                <x-slot:head>
                    <tr>
                        <th>Title</th>
                        <th>Type</th>
                        <th>Asset</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th>Date Solved</th>
                        <th class="actions"><span class="sr-only-text">Action</span></th>
                    </tr>
                </x-slot:head>

                @forelse ($reports as $report)
                    <tr wire:key="issue-report-{{ $report->id }}">
                        <td>
                            <span class="ui-cell-stack">
                                <span class="ui-person-name">{{ $report->title }}</span>
                                <span class="ui-person-sub">{{ \Illuminate\Support\Str::limit($report->reason, 70) ?: '-' }}</span>
                            </span>
                        </td>
                        <td>
                            <span class="ui-cell-stack">
                                <span>{{ $report->issue_type }}</span>
                                @if ($report->issue_subtype)
                                    <span class="ui-person-sub">{{ $report->issue_subtype }}</span>
                                @endif
                            </span>
                        </td>
                        <td @class(['cell-muted' => ! $report->asset])>{{ $report->asset?->asset_name ?: 'Unlinked' }}</td>
                        <td>
                            <span class="ui-cell-stack">
                                <span>{{ $report->district?->district_name ?: '-' }}</span>
                                <span class="ui-person-sub">{{ $report->region?->region_name ?: '-' }}</span>
                            </span>
                        </td>
                        <td><x-ui.status-pill domain="issue" :status="$report->status" /></td>
                        <td @class(['nowrap', 'cell-muted' => ! $report->date_solved])>{{ $report->date_solved?->format('d M Y') ?: '-' }}</td>
                        <td class="actions">
                            @if ((! $regionLimited || (int) $report->reporting_region_id === (int) $ownRegionId))
                                <button type="button" wire:click="openEdit({{ $report->id }})" class="btn btn-ghost btn-sm btn-icon" title="Edit" aria-label="Edit {{ $report->title }}">
                                    <x-ui.icon name="pencil" />
                                </button>
                            @else
                                <span class="ui-hint">Read only</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="7" icon="triangle-alert" title="No issue reports found." description="Raise one with New Report." />
                @endforelse

                @if ($reports->hasPages())
                    <x-slot:footer>
                        <div class="pager-end">{{ $reports->links() }}</div>
                    </x-slot:footer>
                @endif
            </x-ui.table>

            <div wire:loading.delay class="table-skeleton">
                <span class="skeleton-line"></span>
                <span class="skeleton-line"></span>
                <span class="skeleton-line short"></span>
            </div>
        </div>
    </x-ui.card>

    @if ($showForm)
        <x-ui.modal :title="$editingReportId ? 'Edit Report' : 'New Issue Report'" close="closeForm()" size="lg" icon="triangle-alert" tone="warning">
            <div class="ui-form-grid">
                <div class="span-2">
                    <x-ui.input label="Title" wire:model.defer="form.title" />
                </div>

                <x-ui.select label="Issue Type" wire:model.live="form.issue_type" required>
                    <option value="">Select type</option>
                    @foreach ($categoryOptions as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                    @if ($form['issue_type'] !== '' && ! in_array($form['issue_type'], $categoryOptions, true))
                        <option value="{{ $form['issue_type'] }}">{{ $form['issue_type'] }} (older type)</option>
                    @endif
                </x-ui.select>
                <x-ui.select label="Status" wire:model.defer="form.status">
                    @foreach ($formStatuses as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                    @if (! in_array($form['status'], $formStatuses, true))
                        <option value="{{ $form['status'] }}">{{ $form['status'] }}</option>
                    @endif
                </x-ui.select>

                @if (count($subtypeOptions) || $form['issue_subtype'] !== '')
                    <div class="span-2">
                        <x-ui.select label="What is wrong?" wire:model.defer="form.issue_subtype" :required="count($subtypeOptions) > 0">
                            <option value="">Select one</option>
                            @foreach ($subtypeOptions as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
                            @if ($form['issue_subtype'] !== '' && ! in_array($form['issue_subtype'], $subtypeOptions, true))
                                <option value="{{ $form['issue_subtype'] }}">{{ $form['issue_subtype'] }}</option>
                            @endif
                        </x-ui.select>
                    </div>
                @endif

                <div class="span-2">
                    <x-ui.select label="Linked Asset" wire:model.defer="form.linked_asset_id" :required="$requiresAsset"
                        :hint="$requiresAsset ? 'Required for this type of issue.' : null">
                        <option value="">{{ $requiresAsset ? 'Select the affected device' : 'Unlinked' }}</option>
                        @foreach ($assets as $asset)
                            <option value="{{ $asset->id }}">{{ $asset->asset_name }} ({{ $asset->serial_number ?: 'No serial' }})</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <x-ui.select label="Region" wire:model.defer="form.reporting_region_id" :disabled="$regionLocked" :hint="$regionLocked ? 'Fixed to your own region.' : null">
                    <option value="">Select region</option>
                    @foreach ($regions as $region)
                        <option value="{{ $region->id }}">{{ $region->region_name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select label="District" wire:model.defer="form.reporting_district_id">
                    <option value="">Select district</option>
                    @foreach ($districts as $district)
                        <option value="{{ $district->id }}">{{ $district->district_name }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.input label="Date Solved" type="date" wire:model.defer="form.date_solved" />

                <div class="span-2">
                    <x-ui.textarea label="Reason / Details" wire:model.defer="form.reason" rows="4" />
                </div>
            </div>

            <x-slot:footer>
                <button type="button" wire:click="closeForm" class="btn btn-secondary">Cancel</button>
                <button type="button" wire:click="save" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                    {{ $editingReportId ? 'Update' : 'Save' }}
                </button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($promptAsset)
        <x-ui.modal title="Is this device still Active?" :description="$promptAsset->asset_name.' ('.($promptAsset->serial_number ?: 'No serial').') was linked to the report you just closed.'" close="skipStatusPrompt()" icon="laptop" tone="warning">
            <fieldset class="ui-stack">
                <legend class="sr-only-text">Device status</legend>
                <label><input type="radio" wire:model.live="statusPromptChoice" value="none"> No change (still {{ $promptAsset->status }})</label>
                @foreach ($promptChoices as $choice)
                    <label><input type="radio" wire:model.live="statusPromptChoice" value="{{ $choice }}"> {{ $choice }}</label>
                @endforeach
            </fieldset>

            @if ($statusPromptChoice !== 'none')
                <div class="ui-stack" style="margin-top: 0.75rem;">
                    <x-ui.textarea label="Reason" wire:model.defer="statusPromptReason" rows="2"
                        :required="$statusPromptChoice === 'Damaged'"
                        :hint="$statusPromptChoice === 'Damaged' ? 'Required for Damaged (e.g. faulty power button).' : 'Optional.'" />
                </div>
            @endif

            @error('statusPromptChoice') <p class="ui-error">{{ $message }}</p> @enderror

            <x-slot:footer>
                <button type="button" wire:click="skipStatusPrompt" class="btn btn-secondary">Skip</button>
                <button type="button" wire:click="applyStatusPrompt" class="btn btn-primary" wire:loading.attr="disabled" wire:target="applyStatusPrompt">Update device</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
