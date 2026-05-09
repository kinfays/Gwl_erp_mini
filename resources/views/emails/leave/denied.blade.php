@component('mail::message')
# Leave Request Denied

Your leave request has been denied.

**Employee:** {{ $request->requester->full_name }}  
**Leave Type:** {{ $request->leave_type }}  
**Dates:** {{ $request->start_date->format('d M Y') }} to {{ $request->end_date->format('d M Y') }}  
**Working Days:** {{ $request->total_days_applied }}  
**Manager Comment:** {{ $request->manager_comments ?? 'N/A' }}  
**Final Comment:** {{ $request->chiefManager_comments ?? 'N/A' }}

You may reopen and resubmit the request.

Thanks,  
{{ config('app.name') }}
@endcomponent
