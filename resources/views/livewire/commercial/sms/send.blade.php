<div>
    <x-ui.page-header title="Send SMS" description="Four steps: who, what, check, confirm. Nothing is sent until the last step.">
        <x-slot:actions><a href="{{ route('commercial.sms') }}" class="btn btn-secondary">Back</a></x-slot:actions>
    </x-ui.page-header>

    <p class="ui-hint dash-row">
        <strong class="{{ $step === 1 ? '' : 'ui-muted' }}">1 Who</strong> &rsaquo;
        <strong class="{{ $step === 2 ? '' : 'ui-muted' }}">2 What</strong> &rsaquo;
        <strong class="{{ $step === 3 ? '' : 'ui-muted' }}">3 Check</strong> &rsaquo;
        <strong class="{{ $step === 4 ? '' : 'ui-muted' }}">4 Confirm</strong>
        @if ($balance)
            &nbsp;·&nbsp; Balance: {{ $balance['available'] ? number_format($balance['credits']).' credits' : 'unavailable' }}
            <button type="button" class="btn btn-ghost btn-sm" wire:click="refreshBalance">Refresh</button>
        @endif
    </p>

    @if ($balance && $balance['low'])
        <x-ui.alert tone="warning" class="dash-row">SMS credits are running low ({{ number_format($balance['credits']) }} left).</x-ui.alert>
    @endif

    {{-- ============================================================ 1. who --}}
    @if ($step === 1)
        <x-ui.card title="Who should get the message?" description="Only customers of {{ implode(', ', array_map('mb_strtoupper', (array) config('gwl.commercial_sms_region_names'))) }} with a valid mobile number can be messaged. Each number gets one SMS, even when several accounts share it." class="dash-row">
            <div class="ui-toolbar">
                <label><input type="radio" wire:model.live="type" value="all"> All customers</label>
                <label><input type="radio" wire:model.live="type" value="group"> A group</label>
                <label><input type="radio" wire:model.live="type" value="selected"> Selected customers ({{ number_format(count($selectedIds)) }})</label>
            </div>

            @if ($type === 'group')
                <div class="ui-toolbar">
                    <select wire:model.live="group" class="form-input" aria-label="Group">
                        @foreach ($groups as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </select>

                    @if ($group === 'debtors')
                        <label>Owing more than GH¢ <input type="number" min="0" step="0.01" wire:model.live.debounce.500ms="minDebt" class="form-input" style="width: 8rem" aria-label="Minimum owed"></label>
                        <select wire:model.live="bucket" class="form-input" aria-label="Arrears bucket">
                            <option value="">Any amount of bills owed</option>
                            @foreach (\App\Services\Commercial\Customers\CustomerAnalyticsService::BUCKET_LABELS as $key => $label)@if ($key >= 2)<option value="{{ $key }}">{{ $label }}</option>@endif @endforeach
                        </select>
                        <span class="ui-hint">A positive balance is taken as owed (to be confirmed).</span>
                    @elseif ($group === 'no_payment')
                        <select wire:model.live="days" class="form-input" aria-label="Days without payment">
                            @foreach (\App\Services\Commercial\Sms\SmsAudienceResolver::NO_PAYMENT_DAYS as $d)<option value="{{ $d }}">No payment in {{ $d }} days</option>@endforeach
                        </select>
                        <span class="ui-hint">Counted from each district's file date. Includes accounts that never paid.</span>
                    @elseif ($group === 'valuable')
                        <select wire:model.live="topMode" class="form-input" aria-label="Top N or percent">
                            <option value="n">The top N</option><option value="pct">The top percent</option>
                        </select>
                        @if ($topMode === 'n')
                            <input type="number" min="1" wire:model.live.debounce.500ms="topN" class="form-input" style="width: 7rem" aria-label="Top N">
                        @else
                            <input type="number" min="0.1" max="100" step="0.1" wire:model.live.debounce.500ms="topPct" class="form-input" style="width: 7rem" aria-label="Top percent"> %
                        @endif
                        <span class="ui-hint">The billing accounts with the highest last bill, within the filters below; ties at the cut-off are included. An assumption to be confirmed.</span>
                    @endif
                </div>
            @endif

            @if ($type === 'selected')
                <div class="dash-row">
                    <label for="pasted">Add customers by account number (one per line, or separated by commas)</label>
                    <textarea id="pasted" wire:model="pasted" rows="3" class="form-input" placeholder="100000000001, 100000000002 ..."></textarea>
                    @error('pasted')<span class="form-error">{{ $message }}</span>@enderror
                    <button type="button" class="btn btn-secondary btn-sm" wire:click="usePasted">Add these</button>
                    @if ($selectedIds !== [])<button type="button" class="btn btn-ghost btn-sm" wire:click="clearSelected">Clear the selection</button>@endif
                    @if ($tickedOutside > 0)<p class="ui-hint">{{ number_format($tickedOutside) }} of the customers you ticked belong to another region and will not be messaged.</p>@endif
                    @if ($pasteReport)
                        <p class="ui-hint">{{ number_format($pasteReport['added']) }} customer(s) added.
                            @if ($pasteReport['not_found']) {{ number_format($pasteReport['not_found']) }} account number(s) were not found. @endif
                            @if ($pasteReport['outside']) {{ number_format($pasteReport['outside']) }} belong to another region and were left out. @endif
                            @if ($pasteReport['invalid']) {{ number_format($pasteReport['invalid']) }} could not be read. @endif
                            @if ($pasteReport['duplicates']) {{ number_format($pasteReport['duplicates']) }} were repeated. @endif
                        </p>
                    @endif
                    <p class="ui-hint">Tick customers on <a href="{{ route('commercial.customers.list') }}">Find customers</a> and choose "Send SMS to ticked" to add them here.</p>
                </div>
            @endif

            @if ($type !== 'selected')
                <div class="ui-toolbar">
                    <select wire:model.live="districtIds" multiple class="form-input" aria-label="Districts" size="{{ min(6, max(3, $districts->count())) }}">
                        @foreach ($districts as $id => $districtName)<option value="{{ $id }}">{{ $districtName }}</option>@endforeach
                    </select>
                    <span class="ui-hint">Districts (none ticked = all). Hold Ctrl to pick several.</span>
                    @if ($routes->isNotEmpty())
                        <select wire:model.live="routeId" class="form-input" aria-label="Route"><option value="">Every route</option>@foreach ($routes as $id => $routeName)<option value="{{ $id }}">{{ $routeName }}</option>@endforeach</select>
                    @endif
                    <select wire:model.live="categoryGroup" class="form-input" aria-label="Category group"><option value="">Every category group</option>@foreach ($categoryGroups as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                    <select wire:model.live="statusId" class="form-input" aria-label="Account status"><option value="">Every account status</option>@foreach ($statuses as $status)<option value="{{ $status->id }}">{{ $status->label }} ({{ $status->code }})</option>@endforeach</select>
                </div>
            @endif

            <label class="ui-hint"><input type="checkbox" wire:model.live="includeMissing"> Include accounts that are no longer in the latest customer file</label>

            @error('audience')<x-ui.alert tone="danger" class="dash-row">{{ $message }}</x-ui.alert>@enderror

            <div class="dash-row">
                <button type="button" class="btn btn-secondary" wire:click="countAudience" wire:loading.attr="disabled">Count the audience</button>
                <span wire:loading wire:target="countAudience" class="ui-hint">Counting ...</span>
            </div>

            @if ($counts)
                <div class="dash-row">
                    <p><strong>{{ number_format($counts['numbers']) }}</strong> recipients (one SMS each).</p>
                    <p class="ui-hint">{{ $definition }}</p>
                    <ul class="ui-hint">
                        <li>{{ number_format($counts['matched']) }} accounts match.</li>
                        <li>{{ number_format($counts['reachable']) }} of them have a valid mobile number @if ($counts['without_number']) ({{ number_format($counts['without_number']) }} have none) @endif.</li>
                        @if ($counts['sharing'] > 0)<li>{{ number_format($counts['sharing']) }} accounts share a number with another account, so recipients are fewer than accounts.</li>@endif
                    </ul>
                    @if ($counts['numbers'] > $maxRecipients)<x-ui.alert tone="danger">That is more than the limit of {{ number_format($maxRecipients) }} recipients for one campaign. Narrow it down.</x-ui.alert>@endif
                </div>
            @endif
        </x-ui.card>
    @endif

    {{-- ============================================================ 2. what --}}
    @if ($step === 2)
        <x-ui.card title="What do you want to say?" class="dash-row">
            <div class="ui-toolbar">
                <label>Sender ID
                    <select wire:model="senderId" class="form-input" aria-label="Sender ID">@foreach ($senders as $sender)<option value="{{ $sender }}">{{ $sender }}</option>@endforeach</select>
                </label>
                @error('senderId')<span class="form-error">{{ $message }}</span>@enderror
                <label>Template
                    <select wire:model.live="templateId" class="form-input" aria-label="Template"><option value="">None</option>@foreach ($templates as $template)<option value="{{ $template->id }}">{{ $template->name }}</option>@endforeach</select>
                </label>
            </div>
            @if ($senders === [])<x-ui.alert tone="warning">No approved sender ID is configured on this server (GWL_ARKESEL_SENDER_ID).</x-ui.alert>@endif

            <textarea wire:model.live.debounce.400ms="body" rows="5" class="form-input" aria-label="Message" placeholder="Write the message ..."></textarea>
            @error('body')<span class="form-error">{{ $message }}</span>@enderror

            <p class="ui-hint">
                {{ number_format($inspect['length']) }} characters · {{ $inspect['encoding'] === 'gsm7' ? 'standard (GSM-7) text: 160 per part, 153 when it takes more' : 'contains characters outside standard text, so it is sent as Unicode: 70 per part, 67 when it takes more' }}
                · <strong>{{ $inspect['segments'] }} part{{ $inspect['segments'] === 1 ? '' : 's' }}</strong> = {{ $inspect['segments'] }} credit{{ $inspect['segments'] === 1 ? '' : 's' }} per recipient
                @if ($inspect['personalised']) (counted with the longest possible name, account number and balance) @endif
            </p>
            <p class="ui-hint">
                Merge fields: <code>{account_no}</code>, <code>{balance}</code>@if ($mayUseName), <code>{name}</code>@endif. A message with merge fields is sent one by one, built when it is sent, and is not stored with the customer's data.
                @unless ($mayUseName) <code>{name}</code> needs the permission to see customer names. @endunless
            </p>
            @if ($inspect['unknown'])<x-ui.alert tone="danger">Unknown merge field: {{ '{'.implode('}, {', $inspect['unknown']).'}' }}</x-ui.alert>@endif

            <div class="ui-toolbar">
                <input wire:model="name" class="form-input" placeholder="Name for this campaign or template (optional)" aria-label="Name" maxlength="120">
                <button type="button" class="btn btn-ghost btn-sm" wire:click="saveTemplate">Save as a template</button>
            </div>
        </x-ui.card>
    @endif

    {{-- ============================================================ 3. check --}}
    @if ($step === 3)
        <x-ui.card title="Check before you send" class="dash-row">
            <p><strong>{{ number_format($counts['numbers'] ?? 0) }}</strong> recipients · sender <strong>{{ $senderId }}</strong> · {{ $inspect['segments'] }} part(s) each · about <strong>{{ number_format($credits ?? 0) }}</strong> credits (an estimate: recipients x message parts).</p>
            <p class="ui-hint">{{ $definition }}</p>

            @if ($covers && ! $covers['ok'])<x-ui.alert tone="danger">{{ $covers['reason'] }}</x-ui.alert>@endif

            @if ($sample !== [])
                <x-ui.table label="Sample recipients" :sticky="false">
                    <x-slot:head><tr><th>Account</th>@if ($mayUseName)<th>Name</th>@endif<th>Mobile (masked)</th></tr></x-slot:head>
                    @foreach ($sample as $row)
                        <tr><td class="mono">{{ $row['account_no'] }}</td>@if ($mayUseName)<td>{{ $row['name'] ?? '–' }}</td>@endif<td class="mono">{{ $row['mobile'] }}</td></tr>
                    @endforeach
                </x-ui.table>
            @endif

            <p class="dash-row"><strong>The message</strong></p>
            <pre class="mono" style="white-space: pre-wrap">{{ $example ?? $body }}</pre>
            @if ($inspect['personalised'])<p class="ui-hint">Shown for the first sample recipient; each customer gets their own values.</p>@endif
        </x-ui.card>
    @endif

    {{-- ============================================================ 4. confirm --}}
    @if ($step === 4)
        <x-ui.card title="Confirm" class="dash-row">
            <p><strong>{{ number_format($counts['numbers'] ?? 0) }}</strong> recipients · about {{ number_format($credits ?? 0) }} credits.</p>

            @if ($needsTyping)
                <label for="confirmCount">Type the number of recipients ({{ number_format($counts['numbers']) }}) to confirm</label>
                <input id="confirmCount" wire:model="confirmCount" class="form-input" inputmode="numeric" autocomplete="off">
            @else
                <label><input type="checkbox" wire:model="confirmChecked"> Yes, send this message to {{ number_format($counts['numbers'] ?? 0) }} recipient(s)</label>
            @endif

            <div class="dash-row">
                <label for="scheduleAt">Send later (optional; leave empty to send now)</label>
                <input id="scheduleAt" type="datetime-local" wire:model="scheduleAt" class="form-input">
            </div>

            @error('confirm')<x-ui.alert tone="danger" class="dash-row">{{ $message }}</x-ui.alert>@enderror

            <div class="dash-row">
                <button type="button" class="btn btn-primary" wire:click="send" wire:loading.attr="disabled" wire:target="send">{{ $scheduleAt !== '' ? 'Schedule the campaign' : 'Send now' }}</button>
                <span wire:loading wire:target="send" class="ui-hint">Filing the campaign ...</span>
            </div>
        </x-ui.card>
    @endif

    <div class="dash-row">
        @if ($step > 1)<button type="button" class="btn btn-secondary" wire:click="back">Back</button>@endif
        @if ($step < 4)<button type="button" class="btn btn-primary" wire:click="next">Next</button>@endif
    </div>
</div>
