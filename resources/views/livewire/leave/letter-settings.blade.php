<div>
    <x-ui.page-header title="Letter Settings" description="The letterhead and footer printed on leave approval letters. Letters already issued keep what they were printed with." />

    @if (session('success'))
        <x-ui.alert tone="success" role="status">{{ session('success') }}</x-ui.alert>
    @endif

    <div class="ui-grid ui-grid-main">
        <x-ui.card title="Letterhead" description="The address block on the right of the letter, and the HR person who signs for the chief manager.">
            <x-ui.select label="Location" wire:model.live="scope" id="letter-scope">
                @foreach ($letterheads as $row)
                    <option value="{{ $keyOf($row) }}">{{ $row->isHeadOffice() ? 'Head Office' : $row->region_name }}{{ $row->addressMissing() ? ' (address not set)' : '' }}</option>
                @endforeach
            </x-ui.select>

            @if ($current?->addressMissing())
                <x-ui.alert tone="warning">The address for this location is not set yet, so letters from it print without one.</x-ui.alert>
            @endif

            <x-ui.input label="Heading (region or office name)" wire:model="regionName" id="letter-region-name" error="regionName" />

            <x-ui.field label="Address lines (one per line: Post Office Box, town and country, West Africa)" for="letter-address">
                <textarea id="letter-address" wire:model="addressLines" rows="5" class="form-input ui-input"></textarea>
            </x-ui.field>

            <x-ui.select label="HR signatory for this location" wire:model="hrSignatory" id="letter-hr-signatory" error="hr_signatory_user_id" hint="Signs 'for' the chief manager when HR switches a letter to that mode.">
                <option value="">None</option>
                @foreach ($candidates as $candidate)
                    <option value="{{ $candidate->id }}">{{ $candidate->full_name }} ({{ $candidate->staff_id }})</option>
                @endforeach
            </x-ui.select>
            @error('hr_signatory_user_id') <p class="ui-error"><span>{{ $message }}</span></p> @enderror

            <x-ui.field label="Default cc list (one per line)" for="letter-cc-default">
                <textarea id="letter-cc-default" wire:model="defaultCc" rows="3" class="form-input ui-input"></textarea>
            </x-ui.field>

            <x-slot:footer>
                <div class="ui-form-actions">
                    <button type="button" class="btn btn-primary" wire:click="saveLetterhead">Save letterhead</button>
                </div>
            </x-slot:footer>
        </x-ui.card>

        <x-ui.card title="Preview">
            <p><strong>{{ $regionName }}</strong></p>
            @foreach (preg_split('/\R/', $addressLines) as $line)
                @if (trim($line) !== '') <div>{{ $line }}</div> @endif
            @endforeach
            <p class="ui-hint">{{ \Carbon\Carbon::today()->format(\App\Services\Leave\LeaveLetterService::DATE_FORMAT) }}</p>
            <hr>
            <div class="ui-hint">
                <strong>Board of Directors</strong>
                @foreach ($board as $member)
                    @if (trim($member['name']) !== '')
                        <div>{{ $member['name'] }}@if ($member['role'] !== 'Member') ({{ $member['role'] }})@endif</div>
                    @endif
                @endforeach
                @if (trim($registeredOffice) !== '')<div>Registered Office: {{ $registeredOffice }}</div>@endif
                @if (trim($telephone) !== '')<div>Tel: {{ $telephone }}</div>@endif
                @if (trim($website) !== '')<div>Website: {{ $website }}</div>@endif
                @if (trim($email) !== '')<div>E-mail: {{ $email }}</div>@endif
            </div>
        </x-ui.card>
    </div>

    @if ($canEditCompany)
        <x-ui.card title="Company details and Board of Directors" description="Printed in the footer of every letter. Head Office HR, Global Admin and super_admin only.">
            <div class="ui-form-grid">
                <x-ui.input label="Registered office" wire:model="registeredOffice" id="company-registered-office" />
                <x-ui.input label="Telephone" wire:model="telephone" id="company-telephone" />
                <x-ui.input label="Website" wire:model="website" id="company-website" />
                <x-ui.input label="E-mail" wire:model="email" id="company-email" />
            </div>

            <x-ui.field label="Main bankers (one per line)" for="company-bankers">
                <textarea id="company-bankers" wire:model="bankers" rows="3" class="form-input ui-input"></textarea>
            </x-ui.field>

            <h3 class="ui-panel-title">Board of Directors</h3>
            @foreach ($board as $index => $member)
                <div class="ui-form-grid" wire:key="director-{{ $index }}">
                    <x-ui.input label="Name" wire:model="board.{{ $index }}.name" id="director-name-{{ $index }}" />
                    <x-ui.select label="Role" wire:model="board.{{ $index }}.role" id="director-role-{{ $index }}">
                        @foreach ($boardRoles as $role)
                            <option value="{{ $role }}">{{ $role }}</option>
                        @endforeach
                    </x-ui.select>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="removeDirector({{ $index }})">Remove</button>
                </div>
            @endforeach
            @error('board_members') <p class="ui-error"><span>{{ $message }}</span></p> @enderror

            <x-slot:footer>
                <div class="ui-form-actions">
                    <button type="button" class="btn btn-secondary" wire:click="addDirector">Add a director</button>
                    <button type="button" class="btn btn-primary" wire:click="saveCompany">Save company details</button>
                </div>
            </x-slot:footer>
        </x-ui.card>
    @endif

    @if ($signatureHolders->isNotEmpty())
        <x-ui.card title="Signatures on file" description="You can revoke a signature (for example when an account is compromised). You can't see, add or apply one." :padded="false">
            <x-ui.table label="Signatures on file">
                <x-slot:head><tr><th>Person</th><th>Saved</th><th>How</th><th class="actions"><span class="sr-only-text">Actions</span></th></tr></x-slot:head>
                @foreach ($signatureHolders as $holder)
                    <tr wire:key="signature-{{ $holder->id }}">
                        <td>{{ $holder->user?->full_name }} <span class="ui-person-sub">#{{ $holder->user?->staff_id }}</span></td>
                        <td class="nowrap">{{ $holder->created_at->format('d M Y') }}</td>
                        <td>{{ $holder->method }}</td>
                        <td class="actions">
                            <button
                                type="button"
                                class="btn btn-danger btn-sm"
                                x-data
                                x-on:click.prevent="$dispatch('confirm-action', {
                                    title: 'Revoke this signature?',
                                    message: 'It will never be printed again, on new letters or reprints.',
                                    confirmLabel: 'Revoke',
                                    variant: 'danger',
                                    action: () => $wire.revokeSignature({{ $holder->user_id }})
                                })"
                            >Revoke</button>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif
</div>
