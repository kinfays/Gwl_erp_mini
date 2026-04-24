@component('mail::message')
# Leave Request Recommended

**Employee:** {{ $request->requester->full_name }}  
**Recommended By:** {{ $request->manager->full_name }}  
**Leave Type:** {{ $request->leave_type }}  
**Dates:** {{ $request->start_date->format('d M Y') }} → {{ $request->end_date->format('d M Y') }}  
**Working Days:** {{ $request->total_days_applied }}

@component('mail::button', ['url' => url('/leave/requests')])
Approve / Deny
@endcomponent

Thanks,  
{{ config('app.name') }}
@endcomponent