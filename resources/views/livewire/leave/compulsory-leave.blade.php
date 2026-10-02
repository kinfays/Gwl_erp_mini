<div>
    <x-ui.page-header title="Compulsory Leave" description="Days taken off the annual entitlement of Head Office and regional office staff for the year's shutdown. They are not a leave request." />

    @if (session('success'))
        <x-ui.alert tone="success" role="status">{{ session('success') }}</x-ui.alert>
    @endif

    @if ($status['source'] === 'legacy')
        <x-ui.alert tone="info" title="Handled by the earlier deduction">
            {{ $year }} was handled by the earlier compulsory deduction, whose days are already counted as used leave. Nothing more is deducted here, so the figures can't be changed for this year.
        </x-ui.alert>
    @elseif ($status['source'] === 'default')
        <x-ui.alert tone="warning" title="No compulsory leave recorded for {{ $year }}">
            Until you save one, the default of {{ $defaultDays }} days applies to Head Office and regional office staff. Save the year to set the days and the dates.
        </x-ui.alert>
    @endif

    <div class="ui-grid ui-grid-main">
        <x-ui.card title="Compulsory leave" description="Taken from the gross entitlement, so Senior staff at Head Office have {{ $seniorGross }} − {{ $status['days'] }} = {{ $seniorGross - $status['days'] }} days to take.">
            <div class="ui-form-grid">
                <x-ui.select label="Leave Year" wire:model.live="year" id="compulsory-year" error="year">
                    @foreach ($years as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.input type="number" label="Compulsory days" wire:model="days" id="compulsory-days" min="0" max="60" step="1" error="days" :disabled="$status['source'] === 'legacy'" />

                <x-ui.input type="date" label="Shutdown starts" wire:model="startDate" id="compulsory-start" error="startDate" :disabled="$status['source'] === 'legacy'" />
                <x-ui.input type="date" label="Staff resume" wire:model="resumeDate" id="compulsory-resume" error="resumeDate" :disabled="$status['source'] === 'legacy'" />

                <div class="span-2">
                    <x-ui.textarea label="Notes" wire:model="notes" rows="3" id="compulsory-notes" error="notes" :disabled="$status['source'] === 'legacy'" />
                </div>
            </div>

            @if ($status['source'] !== 'legacy')
                <x-slot:footer>
                    <div class="ui-form-actions">
                        <button type="button" wire:click="save" wire:loading.attr="disabled" class="btn btn-primary">
                            <x-ui.icon name="check" />
                            Save compulsory leave
                        </button>
                    </div>
                </x-slot:footer>
            @endif
        </x-ui.card>

        <x-ui.card title="Who it applies to" description="Active staff, counted now.">
            <x-ui.table label="Staff covered by compulsory leave">
                <x-slot:head>
                    <tr>
                        <th>Staff</th>
                        <th class="num">Active</th>
                    </tr>
                </x-slot:head>
                <tr><td>Head Office and regional office (deducted)</td><td class="num">{{ $coverage['covered'] }}</td></tr>
                <tr><td>District (exempt)</td><td class="num">{{ $coverage['district'] }}</td></tr>
                <tr><td>Contract (no leave)</td><td class="num">{{ $coverage['contract'] }}</td></tr>
                <tr><td>No grade yet (unchanged)</td><td class="num">{{ $coverage['ungraded'] }}</td></tr>
            </x-ui.table>

            <p class="cell-muted">
                Changing the days recalculates everyone's entitlement for the year. Leave already taken is never taken back:
                the entitlement can't fall below the days used. Staff with no grade keep their current entitlement until they are graded.
            </p>
        </x-ui.card>
    </div>
</div>
