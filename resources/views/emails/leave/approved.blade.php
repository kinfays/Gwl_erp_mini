@component('mail::message')
# GWL Leave Approval Notification

**Employee:** {{ $request->requester->full_name }}  
**Department:** {{ $request->department->department_name ?? '—' }}  
**Location:** {{ $request->requester->district->district_name ?? '—' }}, {{ $request->requester->region->region_name ?? '—' }}  

@component('mail::table')
| Field | Value |
|---|---|
| Leave Type | {{ $request->leave_type }} |
| Dates | {{ $request->start_date->format('d M Y') }} → {{ $request->end_date->format('d M Y') }} |
| Days | {{ $request->total_days_applied }} |
| Approved By | {{ $request->approvedBy->full_name ?? '—' }} |
| Date Approved | {{ $request->updated_at->format('d M Y, h:i A') }} |
| Remaining Balance | {{ $balance?->remaining_days ?? '—' }} |
@endcomponent

Thanks,  
{{ config('app.name') }}
@endcomponent
