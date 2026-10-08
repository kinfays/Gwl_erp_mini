<div>
    <x-ui.page-header title="Report an incident" description="Takes about a minute. Near misses are the most useful reports we get: please tell us even if nothing happened." />

    @if ($emergency !== [])
        <x-ui.alert tone="danger" title="In an emergency, call first" class="dash-row">
            @foreach ($emergency as $contact)
                <span class="nowrap">{{ $contact['label'] }}: <a href="tel:{{ $contact['tel'] }}" class="mono">{{ $contact['number'] }}</a></span>@if (! $loop->last) &middot; @endif
            @endforeach
        </x-ui.alert>
    @endif

    @if ($submittedId)
        <x-ui.card title="Report received" class="dash-row">
            @if ($submittedAnonymous)
                <x-ui.alert tone="success" role="status">
                    Thank you. Your report is anonymous: nothing in the system links it to you. Its reference is <strong class="mono">{{ $submittedReference }}</strong>. The Health &amp; Safety Officer has been told.
                </x-ui.alert>
                <x-ui.alert tone="info" class="dash-row">
                    Because nothing links it to you, it will not appear under My reports and you will not be told what happens to it. If you want to ask about it later, quote that reference. There is no printed copy for the same reason: leave this page open or note the reference now.
                </x-ui.alert>
                <div class="ui-form-actions" style="margin-top:14px">
                    <x-ui.button variant="primary" wire:click="reportAnother">Report another</x-ui.button>
                </div>
            @else
                <x-ui.alert tone="success" role="status">
                    Thank you. Your reference is <strong class="mono">{{ $submittedReference }}</strong>. The Health &amp; Safety Officer has been told, and you can follow what happens to it under My reports.
                </x-ui.alert>
                <div class="ui-form-actions" style="margin-top:14px">
                    <x-ui.button :href="route('health_safety.incidents.print', $submittedId)" target="_blank" icon="printer">Print a copy</x-ui.button>
                    <x-ui.button :href="route('health_safety.incidents.show', $submittedId)">View this report</x-ui.button>
                    <x-ui.button variant="primary" wire:click="reportAnother">Report another</x-ui.button>
                </div>
            @endif
        </x-ui.card>
    @else
        <form wire:submit="submit" class="ui-stack" novalidate>
            <x-ui.card title="1. Where did it happen?" class="dash-row">
                <fieldset class="chip-group" @error('context') aria-describedby="hs-context-error" @enderror>
                    <legend class="ui-label">Location where the incident occurred <span class="ui-req" aria-hidden="true">*</span></legend>
                    <div class="chip-options">
                        @foreach ($contexts as $value => $label)
                            <label class="chip-option">
                                <input type="radio" name="context" value="{{ $value }}" wire:model.live="context">
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('context')
                        <p class="ui-error" id="hs-context-error"><x-ui.icon name="circle-alert" class="icon-sm" /><span>{{ $message }}</span></p>
                    @enderror
                </fieldset>

                @if ($context !== '')
                    <div class="ui-form-grid" style="margin-top:14px">
                        @if ($context === 'regional_office')
                            <x-ui.select label="Department" wire:model="departmentId" required>
                                <option value="">Select department</option>
                                @foreach ($departments as $department)
                                    <option value="{{ $department->id }}">{{ $department->department_name }}</option>
                                @endforeach
                            </x-ui.select>
                        @else
                            <x-ui.select label="{{ $context === 'district_office' ? 'District office' : 'District' }}" wire:model.live="districtId" required>
                                <option value="">Select district</option>
                                @foreach ($districts as $district)
                                    <option value="{{ $district->id }}">{{ $district->district_name }}</option>
                                @endforeach
                            </x-ui.select>
                        @endif

                        @if ($context === 'pay_point')
                            <x-ui.select label="Pay point" wire:model.live="siteId" hint="Not in the list? Choose Other and type its name.">
                                <option value="">Select pay point</option>
                                @foreach ($sites as $site)
                                    <option value="{{ $site->id }}">{{ $site->name }}</option>
                                @endforeach
                                <option value="other">Other (type the name)</option>
                            </x-ui.select>
                            @if (in_array($siteId, ['', 'other'], true))
                                <x-ui.input label="Name of the pay point" wire:model="siteNameRaw" required />
                            @endif
                        @endif

                        @if ($context === 'field_work')
                            <x-ui.input label="Where exactly" wire:model="locationDetail" hint="Street, junction or landmark." required />
                        @elseif ($context === 'district_office')
                            <x-ui.input label="Where in the office (optional)" wire:model="locationDetail" />
                        @endif
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card title="2. What are you reporting?" class="dash-row">
                <fieldset class="chip-group" @error('incidentType') aria-describedby="hs-type-error" @enderror>
                    <legend class="ui-label">Type <span class="ui-req" aria-hidden="true">*</span></legend>
                    <div class="chip-options" style="flex-direction:column;align-items:stretch">
                        @foreach ($types as $value => $label)
                            <label class="chip-option" style="justify-content:flex-start">
                                <input type="radio" name="incidentType" value="{{ $value }}" wire:model.live="incidentType">
                                <span><strong>{{ $label }}</strong> <span class="ui-hint">{{ $typeHelp[$value] }}</span></span>
                            </label>
                        @endforeach
                    </div>
                    @error('incidentType')
                        <p class="ui-error" id="hs-type-error"><x-ui.icon name="circle-alert" class="icon-sm" /><span>{{ $message }}</span></p>
                    @enderror
                </fieldset>

                @if ($incidentType === 'other')
                    <div class="ui-form-grid" style="margin-top:14px">
                        <x-ui.input label="What kind of report is this?" wire:model="otherTypeText" required />
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card title="3. What happened?" class="dash-row">
                <div class="ui-form-grid">
                    <x-ui.input type="date" label="When did it occur?" wire:model="occurredOn" max="{{ $today }}" required />
                    <x-ui.input type="time" label="Time (optional)" wire:model="occurredTime" />
                    <div class="span-2">
                        <x-ui.textarea label="Please describe what happened" wire:model="description" rows="5" required />
                    </div>
                    <x-ui.select label="Was first aid given?" wire:model.live="firstAid" required>
                        <option value="">Select</option>
                        @foreach ($firstAidOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                @if ($incidentType === 'injury' && $firstAid === 'no')
                    <x-ui.alert tone="warning" title="Does anyone need help now?" class="dash-row">
                        Please get first aid or medical help for the person affected before you finish this form.
                    </x-ui.alert>
                @endif
            </x-ui.card>

            <x-ui.card title="4. Witness and photos" description="Both are optional." class="dash-row">
                <div class="ui-form-grid">
                    <x-ui.input label="Witness name" wire:model="witnessName" :disabled="$noWitness" />
                    <x-ui.input label="Witness contact" wire:model="witnessContact" :disabled="$noWitness" />
                    <div class="span-2">
                        <x-ui.checkbox label="No witness" wire:model.live="noWitness" />
                    </div>
                    <x-ui.field label="Photos" for="hs-photos" error="photos" class="span-2" hint="Up to {{ $maxPhotos }} photos, {{ $maxMb }} MB each (JPG, PNG or WebP).">
                        <input id="hs-photos" type="file" class="form-input" wire:model="photos" multiple accept="image/jpeg,image/png,image/webp">
                        @error('photos.*')
                            <p class="ui-error"><x-ui.icon name="circle-alert" class="icon-sm" /><span>{{ $message }}</span></p>
                        @enderror
                    </x-ui.field>
                </div>
            </x-ui.card>

            <x-ui.card title="5. Before you send" class="dash-row">
                <div class="ui-stack">
                    <x-ui.checkbox label="Report anonymously" description="Nothing in the system will link this report to you, and photos have their hidden details (such as where they were taken) removed. The catch: it will not show under My reports and nobody can tell you what was done." wire:model.live="anonymous" />
                    @unless ($anonymous)
                        <x-ui.checkbox label="Keep my name confidential" description="Only the Health & Safety Officer and Manager will see who reported it, and you will be told the outcome. The aim is prevention, not blame." wire:model="confidential" />
                    @endunless
                    <x-ui.checkbox label="This needs attention now" description="Tick if someone is at risk or it cannot wait. Injuries and environmental reports are already treated as urgent." wire:model="urgent" />

                    @if ($canRecordOnBehalf && ! $anonymous)
                        <x-ui.checkbox label="I am reporting for someone else" wire:model.live="onBehalf" />

                        @if ($onBehalf)
                            <div class="ui-form-grid">
                                @if ($behalfEmployeeId)
                                    <div class="span-2">
                                        <span class="ui-label">Reporting for</span>
                                        <p>{{ $behalfName }} <x-ui.button size="sm" variant="ghost" wire:click="clearBehalf">Change</x-ui.button></p>
                                    </div>
                                @else
                                    <x-ui.input label="Find a member of staff" wire:model.live.debounce.300ms="behalfSearch" hint="Name or staff ID. If they have no login, just type their name below." />
                                    <x-ui.input label="Their name" wire:model="behalfName" required />
                                    @if ($behalfMatches->isNotEmpty())
                                        <ul class="span-2" role="listbox" aria-label="Matching staff">
                                            @foreach ($behalfMatches as $match)
                                                <li wire:key="behalf-{{ $match->id }}">
                                                    <x-ui.button size="sm" variant="ghost" wire:click="chooseBehalf({{ $match->id }})">{{ $match->full_name }} <span class="mono">{{ $match->staff_id }}</span></x-ui.button>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                @endif
                            </div>
                        @endif
                    @endif
                </div>

                <div class="ui-form-actions" style="margin-top:14px">
                    <x-ui.button type="submit" variant="primary" icon="send" loading="submit">Submit report</x-ui.button>
                </div>
            </x-ui.card>
        </form>
    @endif
</div>
