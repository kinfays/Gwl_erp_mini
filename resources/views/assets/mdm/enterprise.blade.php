<x-erp-layout module="assets" title="Android Enterprise Setup">
    <div class="content">
        <x-ui.page-header title="Android Enterprise Setup" description="One-time step: bind this ERP to the Android Enterprise you just created with Google." />

        <x-ui.card>
            @if ($stage === 'confirm')
                <p>Google sent you back with an enterprise token. Create the enterprise now?</p>
                <form method="POST" action="{{ route('assets.mdm.enterprise.create') }}" class="ui-actions-row">
                    @csrf
                    <input type="hidden" name="enterpriseToken" value="{{ $enterpriseToken }}">
                    <button type="submit" class="btn btn-primary">Create enterprise</button>
                </form>
                <p class="ui-hint">This only works for a signup started by <span class="mono">php artisan mdm:enterprise-signup</span> within the last 24 hours.</p>
            @elseif ($stage === 'done')
                <x-ui.alert tone="success" title="Enterprise created">
                    Add this line to <span class="mono">.env</span>, then clear the config cache:
                </x-ui.alert>
                <pre class="mdm-code mono">ANDROID_MANAGEMENT_ENTERPRISE_ID={{ $enterpriseName }}</pre>
                <p class="ui-hint">Next: create the Pub/Sub topic and subscription, then run <span class="mono">php artisan mdm:enterprise-notifications</span> if the topic was not configured yet. See docs/assets/mdm.md.</p>
            @elseif ($stage === 'error')
                <x-ui.alert tone="danger" title="Could not create the enterprise">{{ $error }}</x-ui.alert>
            @else
                <x-ui.alert tone="warning" title="No enterprise token">
                    Start from <span class="mono">php artisan mdm:enterprise-signup</span>; Google adds the token when it redirects you here.
                </x-ui.alert>
            @endif
        </x-ui.card>
    </div>
</x-erp-layout>
