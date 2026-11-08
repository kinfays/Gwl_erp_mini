<div>
    <x-ui.page-header :title="$existing ? 'Edit voucher '.$existing->pv_no : 'New payment voucher'"
        :description="$isResubmission ? 'Audit queried this voucher. Correct what was asked, then resubmit it.' : 'Save it as a draft (only Finance sees drafts) or send it straight to Audit.'">
        <x-slot:actions>
            <x-ui.button icon="arrow-left" :href="$existing ? route('finance.pvs.show', $existing->id) : route('finance.home')">Back</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (! $existing)
        @include('livewire.finance.partials.location-picker')
    @endif

    @error('conflict')
        <x-ui.alert tone="warning" role="alert" class="dash-row">
            {{ $message }}
            <x-slot:actions><x-ui.button size="sm" wire:click="reload">Reload the latest</x-ui.button></x-slot:actions>
        </x-ui.alert>
    @enderror

    @if ($existing && $isResubmission)
        @php($query = $existing->events->last(fn ($event) => $event->action === \App\Models\FinPvEvent::QUERIED))
        @if ($query)
            <x-ui.alert tone="danger" title="Audit's query" class="dash-row">
                <strong>{{ $query->reasonLabel() }}.</strong> {{ $query->comment }}
                <div class="cell-muted">{{ $query->actorName() }}, {{ $query->created_at->format('d M Y H:i') }}</div>
            </x-ui.alert>
        @endif
    @endif

    @if ($existing || $location)
        <x-ui.card title="Voucher" class="dash-row">
            <div class="ui-form-grid">
                <x-ui.input label="PV date" type="date" required wire:model="form.pv_date" :max="today()->toDateString()" />
                <x-ui.input label="PV number" required wire:model="form.pv_no" maxlength="60" hint="Unique across the whole company, voided vouchers included." />

                <x-ui.select label="Who is being paid" required wire:model.live="form.payee_kind">
                    @foreach ($payeeKinds as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>

                {{-- One accessible combobox for every payee kind (<x-form.searchable-select>, ARIA 1.2). People and suppliers are searched on
                     the server (250 ms debounce, 2+ characters, 20 rows at most); districts are few, so they filter in the browser. The
                     browser sends back only an id, which the workflow service re-resolves inside the location on every save. --}}
                @if (in_array($form['payee_kind'], ['employee', 'retiree'], true))
                    <x-form.searchable-select
                        :label="$form['payee_kind'] === 'employee' ? 'Employee' : 'Retiree'" model="form.payee_employee_id" search="searchPeople"
                        :initial-label="$payeeLabel" :reset-key="$form['payee_kind'].'|'.$pickerNonce.'|'.($location['district_id'] ?? '')"
                        :min-chars="2" :limit="20" required placeholder="Type a name or staff ID"
                        empty-text="No one matches that"
                        :hint="$form['payee_kind'] === 'employee' ? 'Current staff of this location.' : 'Former staff (retired or left service) of this location.'" />
                @elseif ($form['payee_kind'] === 'district')
                    <x-form.searchable-select
                        label="District" model="form.payee_district_id" :options="$districtOptions"
                        :initial-label="$payeeLabel" :reset-key="'district|'.($location['district_id'] ?? '')"
                        required placeholder="Type to find a district" empty-text="No district matches that"
                        hint="The districts of this location." />
                @else
                    <x-form.searchable-select
                        label="Supplier" model="form.supplier_id" search="searchSuppliers"
                        :initial-label="$payeeLabel" :reset-key="'supplier|'.$pickerNonce"
                        :min-chars="2" :limit="20" required placeholder="Type a supplier name or code"
                        empty-text="No supplier matches that" hint="The company-wide supplier list.">
                        @if ($canManageSuppliers)
                            <x-slot:noMatch>
                                <button type="button" class="btn btn-ghost btn-sm" x-on:mousedown.prevent="$wire.startNewSupplier(query); close()">
                                    Add <strong x-text="'&quot;' + query.trim() + '&quot;'"></strong> as a new supplier
                                </button>
                            </x-slot:noMatch>
                        @endif
                    </x-form.searchable-select>
                @endif

                <x-ui.select label="Category" required wire:model.live="form.category_id">
                    <option value="">Choose</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->effective_name }}@unless ($category->effective_active) (no longer in use) @endunless</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select label="Type" required wire:model="form.type_id">
                    <option value="">{{ $form['category_id'] ? 'Choose' : 'Choose a category first' }}</option>
                    @foreach ($types as $type)
                        <option value="{{ $type->id }}">{{ $type->effective_name }}@unless ($type->effective_active) (no longer in use) @endunless</option>
                    @endforeach
                </x-ui.select>

                <x-ui.input label="Amount (GH¢)" required inputmode="decimal" wire:model="form.amount" placeholder="0.00" hint="Cedis only, at most two decimals." />
                <div class="span-2">
                    <x-ui.textarea label="Details" required rows="3" maxlength="2000" wire:model="form.details" />
                </div>
                <div class="span-2">
                    <x-ui.textarea label="Remarks (Finance's own note)" rows="2" maxlength="1000" wire:model="form.remarks" hint="Not where Audit's queries go; those appear on the voucher's timeline." />
                </div>
            </div>

            @if ($form['payee_kind'] === 'supplier' && $canManageSuppliers)
                <details class="dash-row" @if ($showSupplierAdd || $errors->has('newSupplier.*') || $supplierMatches) open @endif>
                    <summary>Supplier not on the list? Add it</summary>
                    @include('livewire.finance.partials.supplier-fields', ['prefix' => 'newSupplier', 'matches' => $supplierMatches, 'ackModel' => 'differentSupplier'])
                    <div class="ui-form-actions"><x-ui.button size="sm" icon="plus" wire:click="addSupplier" loading="addSupplier">Add and choose this supplier</x-ui.button></div>
                </details>
            @endif
        </x-ui.card>

        <x-ui.card title="Supporting documents (optional)" :description="'PDF, JPG, PNG or WebP, up to '.$maxMb.' MB each, at most '.$maxFiles.' files.'" class="dash-row">
            @foreach ($attachments as $attachment)
                <x-ui.checkbox wire:key="att-{{ $attachment->id }}" label="Remove {{ $attachment->display_name }}" wire:model="removeIds" value="{{ $attachment->id }}" />
            @endforeach

            <input type="file" multiple wire:model="uploads" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" aria-label="Add supporting documents">
            <div wire:loading.inline-flex wire:target="uploads" class="cell-muted wl-inline"><x-loading.drops size="sm" decorative />Uploading...</div>
            @error('form.attachments') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            @error('uploads.*') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
        </x-ui.card>

        @if ($isResubmission)
            <x-ui.card title="Your reply to Audit (optional)" class="dash-row">
                <x-ui.textarea label="Note" rows="2" maxlength="1000" wire:model="note" />
            </x-ui.card>
        @endif

        <div class="ui-form-actions">
            <x-ui.button wire:click="saveDraft" loading="saveDraft">{{ $existing ? 'Save changes' : 'Save as draft' }}</x-ui.button>
            <x-ui.button variant="primary" icon="send" wire:click="saveAndSend" loading="saveAndSend">{{ $isResubmission ? 'Save and resubmit to Audit' : 'Save and send to Audit' }}</x-ui.button>
        </div>
    @endif
</div>
