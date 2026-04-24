@component('mail::message')
# Leave Approved – GWCL ERP

@component('mail::table')
| Field | Value |
|---|---|
| Employee | {{ $request->requester->full_name }} |
| Department | {{ $request->department->department_name ?? '—' }} |
| Leave Type | {{ $request->leave_type }} |
| Dates | {{ $request->start_date->format('d M Y') }} → {{ $request->end_date->format('d M Y') }} |
| Days | {{ $request->total_days_applied }} |
| Remaining Balance | {{ $balance?->remaining_days ?? '—' }} |
@endcomponent

Approved by **{{ $request->approvedBy->full_name }}**

Regards,  
{{ config('app.name') }}
@endcomponent