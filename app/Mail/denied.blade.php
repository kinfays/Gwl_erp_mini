@component('mail::message')
# Leave Request Denied

Your leave request has been denied.

**Manager Comment:** {{ $request->manager_comments ?? '—' }}  
**Final Comment:** {{ $request->chiefManager_comments ?? '—' }}

You may re‑open and resubmit the request.

Thanks,  
{{ config('app.name') }}
@endcomponent