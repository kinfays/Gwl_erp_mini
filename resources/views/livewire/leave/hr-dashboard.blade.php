
//KPI Cards

<div class="stats">
    <div class="stat">
        <div class="stat-lbl">On leave now</div>
        <div class="stat-val">{{ $stats['on_leave_now'] }}</div>
    </div>

    <div class="stat">
        <div class="stat-lbl">Pending requests</div>
        <div class="stat-val">{{ $stats['pending'] }}</div>
    </div>

    <div class="stat">
        <div class="stat-lbl">Approved this month</div>
        <div class="stat-val">{{ $stats['approved_this_month'] }}</div>
    </div>

    <div class="stat">
        <div class="stat-lbl">Denied this month</div>
        <div class="stat-val">{{ $stats['denied_this_month'] }}</div>
    </div>
</div>

//Status Tabs

<div class="tabs">
    @foreach (['all','Pending Approval','Approved','Denied'] as $tab)
        <button
            wire:click="$set('statusTab','{{ $tab === 'all' ? 'all' : $tab }}')"
            class="tab {{ $statusTab === $tab ? 'active' : '' }}"
        >
            {{ ucfirst($tab) }}
        </button>
    @endforeach
</div>

//Pending Approvals Table

<table>
    <thead>
        <tr>
            <th>Employee</th>
            <th>Department</th>
            <th>Type</th>
            <th>Dates</th>
            <th>Days</th>
            <th>Status</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($this->requests as $req)
            <tr>
                <td>{{ $req->requester->full_name }}</td>
                <td>{{ $req->department?->name }}</td>
                <td>{{ $req->leave_type->value }}</td>
                <td>
                    {{ $req->start_date->format('d M') }} –
                    {{ $req->end_date->format('d M') }}
                </td>
                <td>{{ $req->total_days_applied }}</td>
                <td>
                    <span class="pill">
                        {{ $req->leave_status->value }}
                    </span>
                </td>
                <td>
                    <a href="{{ route('leave.review', $req) }}" class="actn">
                        Review
                    </a>
                </td>
            </tr>
        @endforeach
    </tbody>
</table>

//ApexCharts (Leave by Type)

<div id="leaveByType"></div>

<script>
document.addEventListener('livewire:navigated', () => {
    const data = @json($this->leaveByType);

    new ApexCharts(document.querySelector("#leaveByType"), {
        chart: { type: 'bar', height: 220 },
        series: [{
            name: 'Leaves',
            data: Object.values(data)
        }],
        xaxis: {
            categories: Object.keys(data)
        }
    }).render();
});
</script>

<button wire:click="export" class="btn">Export Excel</button>