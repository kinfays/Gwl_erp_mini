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

                @if (in_array($form['payee_kind'], ['employee', 'retiree'], true))
                    <x-ui.input label="Find the person" wire:model.live.debounce.400ms="payeeSearch" placeholder="Name or staff ID" hint="{{ $form['payee_kind'] === 'employee' ? 'Current staff of this location.' : 'Former staff (retired or left service) of this location.' }}" />
                    <x-ui.select label="{{ $form['payee_kind'] === 'employee' ? 'Employee' : 'Retiree' }}" required wire:model="form.payee_employee_id">
                        <option value="">Choose</option>
                        @foreach ($employees as $person)
                            <option value="{{ $person->id }}">{{ $person->full_name }} ({{ $person->staff_id }})@if (! $person->is_active) - left service @endif</option>
                        @endforeach
                    </x-ui.select>
                @elseif ($form['payee_kind'] === 'district')
                    <x-ui.select label="District" required wire:model="form.payee_district_id">
                        <option value="">Choose</option>
                        @foreach ($districts as $district)
                            <option value="{{ $district->id }}">{{ $district->district_name }}</option>
                        @endforeach
                    </x-ui.select>
                @else
                    <x-ui.input label="Find the supplier" wire:model.live.debounce.400ms="payeeSearch" placeholder="Supplier name" />
                    <x-ui.select label="Supplier" required wire:model="form.supplier_id" hint="The company-wide supplier list.">
                        <option value="">Choose</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}@unless ($supplier->is_active) (inactive) @endunless</option>
                        @endforeach
                    </x-ui.select>
                @endif

                <x-ui.select label="Category" required wire:model.live="form.category_id">
                    <option value="">Choose</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}@unless ($category->is_active) (no longer in use) @endunless</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select label="Type" required wire:model="form.type_id">
                    <option value="">{{ $form['category_id'] ? 'Choose' : 'Choose a category first' }}</option>
                    @foreach ($types as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}@unless ($type->is_active) (no longer in use) @endunless</option>
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
                <details class="dash-row" @if ($errors->has('newSupplier.*') || $supplierMatches) open @endif>
                    <summary>Supplier not on the list? Add it</summary>
                    @include('livewire.finance.partials.supplier-fields', ['prefix' => 'newSupplier', 'matches' => $supplierMatches, 'ackModel' => 'differentSupplier'])
                    <div class="ui-form-actions"><x-ui.button size="sm" icon="plus" wire:click="addSupplier" loading="addSupplier">Add and choose this supplier</x-ui.button></div>
                </details>
            @endif

            @if ($canManageCatalogue)
                <details class="dash-row" @if ($errors->has('catalogue.*')) open @endif>
                    <summary>Add a category or type</summary>
                    <div class="ui-form-grid">
                        <x-ui.input label="New category" wire:model="newCategory" maxlength="120" error="catalogue.name" />
                        <div class="ui-form-actions"><x-ui.button size="sm" wire:click="addCategory">Add category</x-ui.button></div>
                        <x-ui.input label="New type (in the category chosen above)" wire:model="newType" maxlength="120" error="catalogue.name" />
                        <div class="ui-form-actions"><x-ui.button size="sm" wire:click="addType">Add type</x-ui.button></div>
                    </div>
                    @error('catalogue.category_id') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
                </details>
            @endif
        </x-ui.card>

        <x-ui.card title="Supporting documents (optional)" :description="'PDF, JPG, PNG or WebP, up to '.$maxMb.' MB each, at most '.$maxFiles.' files.'" class="dash-row">
            @foreach ($attachments as $attachment)
                <x-ui.checkbox wire:key="att-{{ $attachment->id }}" label="Remove {{ $attachment->display_name }}" wire:model="removeIds" value="{{ $attachment->id }}" />
            @endforeach

            <input type="file" multiple wire:model="uploads" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" aria-label="Add supporting documents">
            <div wire:loading wire:target="uploads" class="cell-muted">Uploading...</div>
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
