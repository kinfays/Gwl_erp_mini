@component('mail::message')
# Leave Request Recommended

**Employee:** {{ $request->requester->full_name }}  
**Recommended By:** {{ $request->manager->full_name }}  
**Leave Type:** {{ $request->leave_type }}  

@component('mail::button', ['url' => route('leave.approvals')])
Finalize Approval
@endcomponent

Thanks,  
{{ config('app.name') }}
@endcomponent