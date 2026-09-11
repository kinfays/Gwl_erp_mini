<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Membership Applications</h2>
            <p>Self-submitted applications awaiting a credit union committee decision.</p>
        </div>
        <div class="ph-right">
            <a class="btn btn-secondary" href="{{ route('credit-union.members') }}">Back to Members</a>
        </div>
    </div>

    @if ($rejectingId)
        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Reject Application</span>
            </div>
            <div style="padding:14px">
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Reason</label>
                        <textarea rows="2" class="form-input" wire:model.defer="rejectionReason"></textarea>
                        @error('rejectionReason') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field" style="justify-content:end">
                        <button type="button" class="btn btn-danger" wire:click="reject">Confirm Rejection</button>
                        <button type="button" class="btn btn-secondary" wire:click="cancelReject">Cancel</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="pg">
        <div class="pg-head">
            <span class="pg-title">Pending Queue</span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Member No.</th>
                    <th>Name</th>
                    <th>Department</th>
                    <th>Applied By</th>
                    <th>Applied On</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($applications as $application)
                    <tr>
                        <td>{{ $application->member_number }}</td>
                        <td>{{ $application->full_name }}</td>
                        <td>{{ $application->employee?->department?->department_name ?? '-' }}</td>
                        <td>{{ $application->applicant?->full_name ?? '-' }}</td>
                        <td>{{ optional($application->created_at)->format('d M Y') }}</td>
                        <td>
                            @if ($approvable[$application->id] ?? false)
                                <button type="button" class="btn btn-primary" wire:click="approve({{ $application->id }})">Approve</button>
                                <button type="button" class="btn btn-secondary" wire:click="startReject({{ $application->id }})">Reject</button>
                            @else
                                <span class="form-hint">Committee decision required</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">No pending applications.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div style="padding:12px">{{ $applications->links() }}</div>
    </div>
</div>
