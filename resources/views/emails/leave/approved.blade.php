<h2 style="color:#185FA5">GWL – Leave Approval</h2>

<table cellpadding="6" width="100%" border="1" cellspacing="0">
    <tr><td><strong>Employee</strong></td><td>{{ $leaveRequest->requester->full_name }}</td></tr>
    <tr><td><strong>Department</strong></td><td>{{ $leaveRequest->department?->name }}</td></tr>
    <tr><td><strong>Location</strong></td><td>{{ $leaveRequest->region?->name ?? 'Head Office' }}</td></tr>
    <tr><td><strong>Leave Type</strong></td><td>{{ $leaveRequest->leave_type->value }}</td></tr>
    <tr><td><strong>Dates</strong></td>
        <td>
            {{ $leaveRequest->start_date->toFormattedDateString() }} –
            {{ $leaveRequest->end_date->toFormattedDateString() }}
        </td>
    </tr>
    <tr><td><strong>Total Days</strong></td><td>{{ $leaveRequest->total_days_applied }}</td></tr>
    <tr><td><strong>Approved By</strong></td><td>{{ $leaveRequest->approver->full_name }}</td></tr>
    <tr><td><strong>Date Approved</strong></td><td>{{ now()->toFormattedDateString() }}</td></tr>
</table>