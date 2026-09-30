<div>
    <x-ui.page-header title="New Letter" description="Register incoming correspondence and assign the first received status.">
        <x-slot:actions>
            <a href="{{ route('letters.active') }}" class="btn btn-secondary">
                <x-ui.icon name="inbox" />
                Active Letters
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($missingEmployee)
        <x-ui.alert tone="danger">Your user account is not linked to an employee record.</x-ui.alert>
    @elseif (! $canCreate)
        <x-ui.alert tone="warning">You do not have permission to create letters.</x-ui.alert>
    @else
        <form wire:submit.prevent="save">
            <x-ui.card title="Letter details" description="Fields marked * are required.">
                <div class="ui-form-grid">
                    <x-ui.input label="Subject" wire:model="subject" required />
                    <x-ui.input label="Reference No." wire:model="ref_no" class="mono" />

                    <div class="ui-field">
                        <span class="ui-label" aria-hidden="true">Type<span class="ui-req">*</span></span>
                        <x-ui.segmented label="Type" wire:model.live="type" :options="['Internal' => 'Internal', 'External' => 'External']" />
                    </div>
                    <x-ui.input label="Date on Letter" type="date" wire:model="date_on_letter" required />

                    @if ($type === 'Internal')
                        <x-form.combobox
                            class="span-2"
                            label="Memo Sender"
                            model="memo_sender_id"
                            required
                            :options="$senderOptions"
                            placeholder="Type to search employee"
                            empty-text="No matching employees"
                        />
                    @else
                        <div class="span-2">
                            <x-ui.textarea label="Company / External Sender" wire:model="company_sender" rows="3" required />
                        </div>
                    @endif

                    <x-ui.select label="Region" wire:model="region_id" required>
                        <option value="">Select region</option>
                        @foreach ($regions as $region)
                            <option value="{{ $region->id }}">{{ $region->region_name }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                @if ($scansEnabled)
                    <div class="ui-stack" x-data="{ camera: window.matchMedia('(pointer: coarse)').matches }">
                        <div class="ui-field">
                            <label class="ui-label" for="new-letter-scans">Scans of the hardcopy (optional)</label>
                            <input
                                id="new-letter-scans"
                                type="file"
                                class="form-input"
                                wire:model="scans"
                                multiple
                                accept="image/*,application/pdf"
                                x-bind:capture="camera ? 'environment' : false"
                            >
                            <span class="ui-hint">PDF, JPG or PNG, up to {{ round(config('gwl.letters_scan_max_kb') / 1024, 1) }} MB each. A scan never replaces the hardcopy.</span>
                            <button type="button" class="btn btn-sm btn-ghost" x-show="camera" x-cloak x-on:click="camera = false">Pick files instead of using the camera</button>
                        </div>
                        <div wire:loading wire:target="scans" class="ui-hint">Uploading…</div>
                        @error('scans') <x-ui.alert tone="danger">{{ $message }}</x-ui.alert> @enderror
                        @foreach ($errors->get('scans.*') as $messages)
                            <x-ui.alert tone="danger">{{ $messages[0] }}</x-ui.alert>
                        @endforeach
                    </div>
                @endif

                <x-slot:footer>
                    <div class="ui-form-actions">
                        <a href="{{ route('letters.active') }}" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                            <x-ui.icon name="check" />
                            Save Letter
                        </button>
                    </div>
                </x-slot:footer>
            </x-ui.card>
        </form>
    @endif
</div>
