
<x-layouts.leave>
<div>
    <h2 class="page-title">My Leave History</h2>

    <label>
        <input type="checkbox" wire:model="showPast">
        Show past 36 months
    </label>

    <div class="grid grid-cols-3 gap-4 mt-4">
        @foreach ($this->requests as $req)
            <div class="pg">
                <strong>{{ $req->leave_type->value }}</strong>
                <p>{{ $req->start_date->toDateString() }} – {{ $req->end_date->toDateString() }}</p>
                <p>{{ $req->total_days_applied }} days</p>

                <span class="pill">{{ $req->leave_status->value }}</span>

                <div class="mt-2 flex gap-2">
                    <a href="{{ route('leave.review', $req) }}" class="actn">View</a>

                    @if ($req->leave_status === LeaveStatus::Denied)
                        <button wire:click="reopen({{ $req->id }})" class="actn actn-p">
                            Re-open
                        </button>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>

</x-layouts.leave>
