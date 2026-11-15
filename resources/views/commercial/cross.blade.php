@php
    $can = fn (string $slug) => auth()->user()->hasRoles('super_admin') || auth()->user()->hasPermission($slug);
    $analyses = \App\Services\Commercial\Cross\CrossFileReport::ANALYSES;
@endphp
<x-erp-layout module="commercial" title="Across the files">
    <div class="content">
        <x-ui.page-header title="Across the files" description="The analyses that put the reading report by route, the Detailed Billing Report and the customer list side by side, route by route. The three files are at different points in time: every page says which period each part used." />

        <x-ui.card title="How to read these pages" class="dash-row">
            <ul style="padding-left:18px;list-style:disc">
                <li>By default each part uses <strong>its own newest upload</strong> (the files are not for the same days). Type a date range and choose "same range" to make every part use it.</li>
                <li>Each block needs the permission of its data: reading figures <code>view_reading</code>, billing figures <code>view_billing</code>, customer-list figures <code>view_customer_analytics</code>; a CCA's name needs <code>view_reader_performance</code>. A block you may not see is replaced by a line saying what is hidden; the other blocks stay.</li>
                <li>A district user sees only their district. The customer list is limited to the accounts it puts in their district, which can differ from the district billing and reading put a route in (see X10).</li>
            </ul>
            <p class="ui-hint" style="margin-top:8px">You have: reading {{ $can('commercial.view_reading') ? 'yes' : 'no' }} · billing {{ $can('commercial.view_billing') ? 'yes' : 'no' }} · customer list {{ $can('commercial.view_customer_analytics') ? 'yes' : 'no' }} · named CCAs {{ $can('commercial.view_reader_performance') ? 'yes' : 'no' }}.</p>
        </x-ui.card>

        <div class="ui-stat-grid dash-row">
            @foreach ($analyses as $key => $a)
                <a href="{{ route('commercial.cross.show', $key) }}" class="ui-card" style="display:block;padding:16px;text-decoration:none" data-analysis="{{ $key }}">
                    <strong>{{ $a['code'] }} · {{ $a['title'] }}</strong>
                    <p class="ui-hint" style="margin-top:6px">{{ $a['question'] }}</p>
                </a>
            @endforeach
        </div>

        <x-ui.card title="Route 360" description="One route on one page: reading, billing, customers and the checks that tie them together. Open it from any route name on these pages." class="dash-row">
            <p class="ui-hint">A route outside your district is not shown to you.</p>
        </x-ui.card>
    </div>
</x-erp-layout>
