<div>
    <x-ui.page-header
        :title="$editId ? 'Edit Leave Request' : 'Apply for Leave'"
        description="Save it as planned to decide later, or submit it for approval."
    />

    <div class="ui-grid ui-grid-main">
        <x-ui.card title="Leave details" :description="$editId ? 'You are editing a planned request.' : null">
            @if ($errors->any())
                <x-ui.alert tone="danger" title="Please fix the following" role="alert" class="form-summary">
                    <ul>
                        @foreach ($errors->all() as $e)
                            <li>{{ $e }}</li>
                        @endforeach
                    </ul>
                </x-ui.alert>
            @endif

            @if (! empty($compulsoryRanges))
                <div class="blocked-ranges apply-blocked">
                    <div class="blocked-ranges-title">Unavailable compulsory leave dates</div>
                    <div class="blocked-ranges-grid">
                        @foreach ($compulsoryRanges as $range)
                            <div class="blocked-range">
                                <span>{{ $range['label'] }}</span>
                                <strong>{{ $range['days'] }} days</strong>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="ui-form-grid">
                <x-ui.select label="Leave Type" wire:model.live="leave_type" id="leave-type">
                    <option>Annual</option>
                    <option>Casual</option>
                    <option>Paternity</option>
                    <option>Maternity</option>
                    <option>Sick</option>
                </x-ui.select>

                <div class="day-counter apply-days" aria-live="polite">
                    <span class="day-label">Working days</span>
                    <span class="day-num">{{ $working_days }}</span>
                </div>

                <x-ui.input type="date" label="Start Date" wire:model.live="start_date" min="{{ $minDate }}" id="leave-start" />
                <x-ui.input type="date" label="End Date" wire:model.live="end_date" min="{{ $minEndDate }}" id="leave-end" />

                <div class="span-2">
                    <x-ui.textarea label="Reason (Optional)" wire:model="leave_details" rows="4" id="leave-details" />
                </div>

                <div class="span-2">
                    <x-ui.field label="Attachment (Optional)" for="leave-attachment" hint="A medical note or supporting document, if you have one." error="file_attachment">
                        <input type="file" id="leave-attachment" wire:model="file_attachment" class="ui-file">
                    </x-ui.field>
                </div>
            </div>

            <x-slot:footer>
                <div class="ui-form-actions">
                    <x-ui.button wire:click="savePlanned" loading="savePlanned" icon="calendar-days">Save as Planned</x-ui.button>
                    <x-ui.button variant="primary" wire:click="submit" loading="submit" icon="send">Submit Request</x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.card>

        <x-ui.card title="Leave Balance (This Year)" description="Days you can still take">
            <dl class="balance-list">
                @foreach ($balances as $type => $remain)
                    <div>
                        <dt>{{ $type }}</dt>
                        {{-- Sick leave carries a 9999-day virtual entitlement, i.e. no fixed limit. --}}
                        <dd class="num">{{ is_numeric($remain) && $remain >= 9999 ? 'No limit' : $remain }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-ui.card>
    </div>
</div>
