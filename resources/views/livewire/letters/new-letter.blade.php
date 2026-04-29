<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>New Letter</h2>
            <p>Register incoming correspondence and assign the first received status.</p>
        </div>
        <div class="ph-right">
            <a href="{{ route('letters.active') }}" class="btn">Active Letters</a>
        </div>
    </div>

    @if ($missingEmployee)
        <div class="erp-card" style="margin-top:14px;background:#fcebeb;border-color:#f7c1c1;color:#a32d2d">
            Your user account is not linked to an employee record.
        </div>
    @elseif (! $canCreate)
        <div class="erp-card" style="margin-top:14px;background:#faeeda;border-color:#fac775;color:#854f0b">
            You do not have permission to create letters.
        </div>
    @else
        <form wire:submit.prevent="save" class="pg" style="margin-top:14px">
            <div class="pg-head">
                <span class="pg-title">Letter details</span>
                <button type="submit" class="btn btn-primary">Save Letter</button>
            </div>

            <div style="padding:14px">
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Subject</label>
                        <input type="text" wire:model="subject" class="form-input">
                        @error('subject') <span class="form-label" style="color:#a32d2d">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Reference No.</label>
                        <input type="text" wire:model="ref_no" class="form-input">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Type</label>
                        <select wire:model.live="type" class="form-input">
                            <option value="Internal">Internal</option>
                            <option value="External">External</option>
                        </select>
                    </div>
                    <div class="form-field">
                        <label class="form-label">Date on Letter</label>
                        <input type="date" wire:model="date_on_letter" class="form-input">
                        @error('date_on_letter') <span class="form-label" style="color:#a32d2d">{{ $message }}</span> @enderror
                    </div>
                </div>

                @if ($type === 'Internal')
                    <div class="form-row">
                        <div class="form-field">
                            <label class="form-label">Search Employee Sender</label>
                            <input type="text" wire:model.live="senderSearch" class="form-input" placeholder="Name or staff ID">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Memo Sender</label>
                            <select wire:model="memo_sender_id" class="form-input">
                                <option value="">Select employee</option>
                                @foreach ($senders as $sender)
                                    <option value="{{ $sender->id }}">{{ $sender->full_name }} · {{ $sender->staff_id }}</option>
                                @endforeach
                            </select>
                            @error('memo_sender_id') <span class="form-label" style="color:#a32d2d">{{ $message }}</span> @enderror
                        </div>
                    </div>
                @else
                    <div class="form-field" style="margin-bottom:10px">
                        <label class="form-label">Company / External Sender</label>
                        <textarea wire:model="company_sender" class="form-input" rows="3"></textarea>
                        @error('company_sender') <span class="form-label" style="color:#a32d2d">{{ $message }}</span> @enderror
                    </div>
                @endif

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Region</label>
                        <select wire:model="region_id" class="form-input">
                            <option value="">Select region</option>
                            @foreach ($regions as $region)
                                <option value="{{ $region->id }}">{{ $region->region_name }}</option>
                            @endforeach
                        </select>
                        @error('region_id') <span class="form-label" style="color:#a32d2d">{{ $message }}</span> @enderror
                    </div>
                </div>
            </div>
        </form>
    @endif
</div>
