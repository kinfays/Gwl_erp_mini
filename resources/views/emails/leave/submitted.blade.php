<h3>New Leave Request</h3>

<p>{{ $leaveRequest->requester->full_name }} has submitted a leave request.</p>

<table cellpadding="6">
    <tr><td><strong>Type</strong></td><td>{{ $leaveRequest->leave_type->value }}</td></tr>
    <tr><td><strong>Dates</strong></td>
        <td>
            {{ $leaveRequest->start_date->toFormattedDateString() }} –
            {{ $leaveRequest->end_date->toFormattedDateString() }}
        </td>
    </tr>
    <tr><td><strong>Days</strong></td><td>{{ $leaveRequest->total_days_applied }}</td></tr>
</table>

<p>
    <a href="{{ route('leave.review', $leaveRequest) }}">
        Review this request
    </a>
</p>