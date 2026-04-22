<h3>Leave Request Recommended</h3>

<p>
    {{ $leaveRequest->manager->full_name }}
    has recommended a leave request for
    {{ $leaveRequest->requester->full_name }}.
</p>

<p>
    <a href="{{ route('leave.review', $leaveRequest) }}">
        Review & approve
    </a>
</p>