<div class="pg">
    <div class="pg-head">
        <span class="pg-title">Leave request review</span>
    </div>

    <div class="p-4">
        <p><strong>Employee:</strong> {{ $leaveRequest->requester->full_name }}</p>
        <p><strong>Type:</strong> {{ $leaveRequest->leave_type->value }}</p>
        <p><strong>Dates:</strong>
            {{ $leaveRequest->start_date->toFormattedDateString() }} –
            {{ $leaveRequest->end_date->toFormattedDateString() }}
        </p>
        <p><strong>Days:</strong> {{ $leaveRequest->total_days_applied }}</p>

        <textarea wire:model="comments" class="form-input"
                  placeholder="Comments (optional)"></textarea>

        <div class="flex gap-2 justify-end mt-3">

            @if(auth()->user()->employee->id === $leaveRequest->manager_id)
                <button wire:click="recommend" class="actn actn-g">
                    Recommend
                </button>
            @endif

            @if(
                $leaveRequest->manager_recommendation === 'Recommended' &&
                auth()->user()->employee->id !== $leaveRequest->manager_id
            )
                <button wire:click="approve" class="actn actn-p">
                    Approve
                </button>
            @endif

            <button wire:click="deny" class="actn actn-r">
                Deny
            </button>
        </div>
    </div>
</div>