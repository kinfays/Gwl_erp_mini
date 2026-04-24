@component('mail::message')
# Leave Request Submitted

**Employee:** {{ $request->requester->full_name }}  
**Leave Type:** {{ $request->leave_type }}  
**Dates:** {{ $request->start_date->format('d M Y') }} → {{ $request->end_date->format('d M Y') }}  
**Working Days:** {{ $request->total_days_applied }}

@component('mail::button', ['url' => route('leave.approvals')])
Review Request
@endcomponent

Thanks,  
{{ config('app.name') }}
@endcomponent