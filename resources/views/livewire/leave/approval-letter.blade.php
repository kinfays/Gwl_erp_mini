<div>
    <x-ui.page-header title="Leave Approval Letter" :description="$request->requester->full_name.' · '.$request->leave_type.' leave, '.$request->start_date->format('d M Y').' to '.$request->end_date->format('d M Y')">
        <x-slot:actions>
            @if ($letter)
                <x-ui.button :href="route('leave.letters.pdf', $request)" target="_blank" rel="noopener" variant="primary" icon="printer">Print Letter</x-ui.button>
                <x-ui.button :href="route('leave.letters.pdf', ['leaveRequest' => $request, 'download' => 1])" icon="download">Download PDF</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('success'))
        <x-ui.alert tone="success" role="status">{{ session('success') }}</x-ui.alert>
    @endif

    @error('letter')
        <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert>
    @enderror

    @if (! $letter)
        <x-ui.card title="No letter yet">
            <p class="ui-hint">This approval has no letter. It is normally made at final approval; if that failed, HR can generate it now.</p>
            @if ($canEdit)
                <button type="button" class="btn btn-primary" wire:click="regenerate">Generate the letter</button>
            @endif
        </x-ui.card>
    @else
        @if ($signature['revoked'])
            <x-ui.alert tone="warning" title="A signature was revoked">
                The signature on this letter has been revoked, so it is no longer printed: the signing space is left blank for a wet signature.
            </x-ui.alert>
        @endif

        <div class="ui-grid ui-grid-main">
            <x-ui.card title="Letter" :description="$letter->isLocked() ? 'Printed '.$letter->printed_count.' '.\Illuminate\Support\Str::plural('time', $letter->printed_count).'. Locked.' : 'Not printed yet.'">
                @php($s = $letter->snapshot)
                <div class="letter-text">
                    <p class="ui-hint">
                        {{ $s['letterhead']['region_name'] }}@foreach ($s['letterhead']['address_lines'] as $line), {{ $line }}@endforeach
                        · {{ \Carbon\Carbon::parse($s['letter_date'])->format(\App\Services\Leave\LeaveLetterService::DATE_FORMAT) }}
                    </p>
                    <p>
                        <strong>{{ $s['addressee']['name'] }}</strong><br>
                        {{ $s['addressee']['designation'] }}<br>
                        @if ($s['addressee']['thro']){{ $s['addressee']['thro'] }}<br>@endif
                        {{ $s['addressee']['organisation'] }}<br>
                        {{ $s['addressee']['station'] }}
                    </p>
                    <p>{{ $s['addressee']['salutation'] }}</p>
                    <p><strong>{{ $s['subject'] }}</strong></p>
                    <p>{{ $paragraphs['application'] }}</p>
                    <p>{{ $paragraphs['approval'] }}</p>
                    <p>{{ $paragraphs['dates'] }}</p>
                    @if ($paragraphs['christmas'])<p>{{ $paragraphs['christmas'] }}</p>@endif
                    @if ($paragraphs['balance'])<p>{{ $paragraphs['balance'] }}</p>@endif
                    <p>Yours faithfully,</p>
                    <p>
                        <strong>{{ $s['signatory']['name'] }}</strong><br>
                        {{ $s['signatory']['title'] }}<br>
                        @if ($s['signatory']['for_line']){{ $s['signatory']['for_line'] }}@endif
                    </p>
                    @if (! empty($s['cc']))<p class="ui-hint">cc: {{ implode(', ', $s['cc']) }}</p>@endif
                </div>
            </x-ui.card>

            <div class="ui-stack">
                <x-ui.card title="Signature">
                    @if ($signature['authorized'] && ! $signature['revoked'])
                        <x-ui.badge tone="success">Signature will be printed</x-ui.badge>
                    @elseif ($signature['revoked'])
                        <x-ui.badge tone="danger">Revoked: blank signing space</x-ui.badge>
                    @else
                        <x-ui.badge tone="neutral">Blank signing space</x-ui.badge>
                    @endif

                    <p class="ui-hint">Signed by {{ $signature['signer'] }}. Only the signer can apply their own saved signature.</p>

                    @if ($signature['can_apply'])
                        @if ($signature['signer_has_signature'])
                            <button type="button" class="btn btn-primary" wire:click="applySignature">Apply my signature</button>
                        @else
                            <p class="ui-hint">You have no saved signature. <a class="text-link" href="{{ route('leave.signature') }}">Add one under My Signature</a>, then come back.</p>
                        @endif
                    @endif
                </x-ui.card>

                @if ($canEdit)
                    <x-ui.card title="Letter details" :description="$letter->isLocked() ? 'Locked after the first print.' : 'You can change these until the letter is first printed.'">
                        <fieldset @disabled(! $canEditNow) class="ui-stack">
                            <x-ui.input label="My Ref. No." wire:model="referenceNo" maxlength="100" error="referenceNo" />

                            <x-ui.field label="cc (one per line)" for="letter-cc">
                                <textarea id="letter-cc" wire:model="cc" rows="3" class="form-input ui-input"></textarea>
                            </x-ui.field>

                            <x-ui.select label="Who signs" wire:model="mode" error="mode">
                                @foreach ($modes as $option)
                                    <option value="{{ $option }}">{{ ['self' => 'The approver', 'acting' => 'The approver, acting', 'for' => 'HR signs for the chief manager'][$option] }}</option>
                                @endforeach
                            </x-ui.select>
                            @error('signatory_mode') <p class="ui-error"><span>{{ $message }}</span></p> @enderror

                            @if ($letter->snapshot['compulsory']['applies'] ?? false)
                                <x-ui.checkbox label="Include the Christmas break line" id="letter-christmas" wire:model="christmas" />
                            @endif
                        </fieldset>

                        @if ($canEditNow)
                            <div class="ui-form-actions">
                                <button type="button" class="btn btn-primary" wire:click="save">Save details</button>
                                <button type="button" class="btn btn-ghost" wire:click="regenerate">Regenerate from the request</button>
                            </div>
                        @endif
                    </x-ui.card>
                @endif
            </div>
        </div>
    @endif
</div>
