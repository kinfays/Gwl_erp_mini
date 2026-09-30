@component('mail::message')
# Leave Approved – {{ $scopeLabel }}

Leave has been finally approved for a member of staff in your scope.

@component('mail::table')
| Field | Value |
|---|---|
| Employee | {{ $request->requester->full_name }} ({{ $request->requester->staff_id }}) |
| Department | {{ $request->department->department_name ?? $request->requester->department->department_name ?? '—' }} |
| Location | {{ collect([$request->requester->district->district_name ?? null, $request->requester->region->region_name ?? null])->filter()->join(', ') ?: '—' }} |
| Leave Type | {{ $request->leave_type }} |
| Dates | {{ $request->start_date->format('d M Y') }} → {{ $request->end_date->format('d M Y') }} |
| Working Days | {{ $request->total_days_applied }} |
@foreach ($approvers as $approver)
| {{ $approver['role'] }} | {{ $approver['name'] }} |
@endforeach
@endcomponent

@if ($request->chiefManager_comments)
**Approver's comment:** {{ $request->chiefManager_comments }}
@endif

Regards,
{{ config('app.name') }}
@endcomponent
