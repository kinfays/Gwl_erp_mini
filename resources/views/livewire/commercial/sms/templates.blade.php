<div>
    <x-ui.page-header title="SMS templates" description="Saved wordings you can pick on the send screen. Merge fields: {account_no}, {balance}, and {name} for people who may see customer names. A template never holds customer data.">
        <x-slot:actions><a href="{{ route('commercial.sms') }}" class="btn btn-secondary">Back</a></x-slot:actions>
    </x-ui.page-header>

    <x-ui.card :title="$editing ? 'Edit the template' : 'New template'" class="dash-row">
        <input wire:model="name" class="form-input" placeholder="Name" aria-label="Template name" maxlength="80">
        <textarea wire:model.live.debounce.400ms="body" rows="4" class="form-input" aria-label="Wording" placeholder="Wording ..."></textarea>
        @error('body')<span class="form-error">{{ $message }}</span>@enderror
        <p class="ui-hint">{{ number_format($inspect['length']) }} characters · {{ $inspect['segments'] }} part(s) · {{ $inspect['encoding'] === 'gsm7' ? 'standard text' : 'Unicode (70 per part)' }}</p>
        <button type="button" class="btn btn-primary" wire:click="save">Save</button>
        @if ($editing)<button type="button" class="btn btn-ghost" wire:click="cancelEdit">Cancel</button>@endif
    </x-ui.card>

    <x-ui.card :padded="false" class="dash-row">
        <x-ui.table label="Templates" :sticky="false">
            <x-slot:head><tr><th>Name</th><th>Wording</th><th></th></tr></x-slot:head>
            @forelse ($templates as $template)
                <tr wire:key="tpl-{{ $template->id }}">
                    <td>{{ $template->name }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($template->body, 120) }}</td>
                    <td>
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="edit({{ $template->id }})">Edit</button>
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="delete({{ $template->id }})" wire:confirm="Delete this template?">Delete</button>
                    </td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="3" icon="message-square" title="No templates yet." />
            @endforelse
        </x-ui.table>
    </x-ui.card>
</div>
