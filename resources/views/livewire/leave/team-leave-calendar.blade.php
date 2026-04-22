
<div>
    <h2 class="page-title">Team Leave</h2>

    <table>
        <thead>
            <tr>
                <th>Employee</th>
                <th>Type</th>
                <th>Dates</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($this->teamLeaves as $leave)
                <tr>
                    <td>{{ $leave->requester->full_name }}</td>
                    <td>{{ $leave->leave_type->value }}</td>
                    <td>
                        {{ $leave->start_date->toDateString() }} –
                        {{ $leave->end_date->toDateString() }}
                    </td>
                    <td>{{ $leave->leave_status->value }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

