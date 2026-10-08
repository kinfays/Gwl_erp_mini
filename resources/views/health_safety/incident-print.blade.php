<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $incident['reference'] }} - Incident report</title>
    <style>
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 12px; color: #111; margin: 24px; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        h2 { font-size: 13px; margin: 18px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #999; }
        .muted { color: #555; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; vertical-align: top; padding: 4px 6px; border: 1px solid #ccc; }
        th { background: #f0f0f0; width: 28%; }
        .grid th { width: auto; }
        .note { margin-top: 18px; font-size: 11px; color: #555; }
        .noprint { margin-bottom: 14px; }
        @media print { .noprint { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    @unless ($pdf)
        <div class="noprint"><button onclick="window.print()">Print</button></div>
    @endunless

    <h1>Incident report {{ $incident['reference'] }}</h1>
    <p class="muted">Ghana Water Limited - Health &amp; Safety. Status: {{ $incident['status_label'] }}@if ($incident['severity_label']) &middot; Severity: {{ $incident['severity_label'] }}@endif</p>

    <h2>The report</h2>
    <table>
        <tr><th>Type</th><td>{{ $incident['type_label'] }}</td></tr>
        <tr><th>Where it happened</th><td>{{ $incident['context_label'] }}: {{ $incident['place'] }}@if ($incident['region']) ({{ $incident['region'] }})@endif</td></tr>
        <tr><th>When</th><td>{{ $incident['occurred_on']->format('d M Y') }}@if ($incident['occurred_time']), {{ $incident['occurred_time'] }}@endif</td></tr>
        <tr><th>What happened</th><td style="white-space: pre-line">{{ $incident['description'] }}</td></tr>
        <tr><th>First aid given</th><td>{{ $incident['first_aid_label'] }}</td></tr>
        <tr>
            <th>Witness</th>
            <td>
                @if ($incident['no_witness']) No witness
                @elseif ($incident['witness']) {{ implode(', ', $incident['witness']) }}
                @else Not given @endif
            </td>
        </tr>
        <tr>
            <th>Reported by</th>
            <td>{{ $incident['reporter']['name'] }}@if ($incident['reporter']['recorded_by']) (recorded by {{ $incident['reporter']['recorded_by'] }})@endif, {{ $incident['reported_at']->format('d M Y H:i') }}</td>
        </tr>
        @if ($incident['photos'])
            <tr><th>Photos</th><td>{{ collect($incident['photos'])->pluck('name')->implode(', ') }}</td></tr>
        @endif
    </table>

    @if ($incident['closure_note'])
        <h2>Outcome</h2>
        <p style="white-space: pre-line">{{ $incident['closure_note'] }}</p>
        @if ($incident['closed_at'])<p class="muted">Closed {{ $incident['closed_at']->format('d M Y') }}.</p>@endif
    @endif

    @if ($incident['internal'])
        <h2>Investigation</h2>
        <table>
            <tr><th>Root cause</th><td>{{ $incident['root_cause'] ?? 'Not recorded' }}</td></tr>
            <tr><th>Findings</th><td style="white-space: pre-line">{{ $incident['findings'] ?: 'None recorded' }}</td></tr>
        </table>
    @endif

    @if ($incident['persons'] !== null)
        <h2>People affected</h2>
        @if ($incident['persons'])
            <table class="grid">
                <tr><th>Person</th><th>Injury</th><th>Treatment</th><th>First aider</th><th>Days lost</th><th>Back at work</th></tr>
                @foreach ($incident['persons'] as $person)
                    <tr>
                        <td>{{ $person['name'] }} ({{ $person['type'] }})</td>
                        <td>{{ $person['injury_type'] }}@if ($person['body_part']) ({{ $person['body_part'] }})@endif</td>
                        <td>{{ $person['treatment'] }}</td>
                        <td>{{ $person['first_aider'] }}</td>
                        <td>{{ $person['lost_time_days'] }}</td>
                        <td>{{ $person['returned_to_work_on']?->format('d M Y') }}</td>
                    </tr>
                @endforeach
            </table>
        @else
            <p class="muted">Nobody recorded.</p>
        @endif
    @endif

    @if ($incident['actions'] !== null && $incident['actions'])
        <h2>Actions</h2>
        <table class="grid">
            <tr><th>Action</th><th>Assigned to</th><th>Due</th><th>Status</th></tr>
            @foreach ($incident['actions'] as $action)
                <tr><td>{{ $action['description'] }}</td><td>{{ $action['assignee'] }}</td><td>{{ $action['due_on']->format('d M Y') }}</td><td>{{ ucfirst($action['status']) }}</td></tr>
            @endforeach
        </table>
    @endif

    <h2>Timeline</h2>
    <table class="grid">
        @foreach ($incident['timeline'] as $entry)
            <tr>
                <td>{{ $entry['at']?->format('d M Y H:i') }}</td>
                <td>{{ \App\Models\HsIncident::STATUSES[$entry['to']] ?? $entry['to'] }}</td>
                <td>{{ $entry['note'] }}@if ($entry['by']) <span class="muted">{{ $entry['by'] }}</span>@endif</td>
            </tr>
        @endforeach
    </table>

    <p class="note">Printed {{ now()->format('d M Y H:i') }} by {{ $printedBy }}. This copy shows only what its reader is allowed to see.</p>
</body>
</html>
