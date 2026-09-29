{{--
    Coloured status pill. The label is always visible text; colour never carries the meaning alone.
    Pass the raw stored value plus its domain, e.g.
        <x-ui.status-pill domain="leave" :status="$request->leave_status" />
        <x-ui.status-pill domain="checkout" :status="$visitor->checked_out_by" />
    `label` / `tone` override the mapping. Unknown values fall back to a neutral pill with the
    value itself as the label. Docs: docs/11-ui-components.md#status-pill
--}}
@props([
    'status' => null,
    'domain' => null,
    'label' => null,
    'tone' => null,
    'live' => false,
])

@php
    $raw = trim((string) $status);
    $key = \Illuminate\Support\Str::lower($raw);

    // [tone, label override] — tones: success · warning · danger · info · muted · lagoon
    $maps = [
        'leave' => [
            'planned' => ['muted'],
            'pending approval' => ['warning'],
            'approved' => ['success'],
            'denied' => ['danger'],
        ],
        'recommendation' => [
            'pending' => ['warning'],
            'recommended' => ['info'],
            'rejected' => ['danger'],
        ],
        'letter' => [
            'received' => ['info'],
            'in review' => ['warning'],
            'dispatched' => ['lagoon'],
            'closed' => ['muted'],
        ],
        // visitors.checked_out_by
        'checkout' => [
            'self' => ['success', 'Self checkout'],
            'receptionist' => ['info', 'By reception'],
            'auto' => ['warning', 'Auto checkout'],
        ],
        // whether a visitor is still on the premises
        'presence' => [
            'inside' => ['lagoon', 'On site'],
            'on site' => ['lagoon', 'On site'],
            'out' => ['muted', 'Checked out'],
            'checked out' => ['muted', 'Checked out'],
        ],
        'account' => [
            'active' => ['success'],
            'inactive' => ['muted'],
            'deactivated' => ['muted'],
            'on leave' => ['lagoon'],
            'on_leave' => ['lagoon', 'On Leave'],
            'invited' => ['info'],
        ],
        'vehicle' => [
            'active' => ['success'],
            'maintenance' => ['warning'],
            'retired' => ['muted'],
        ],
        'issue' => [
            'open' => ['warning'],
            'in_review' => ['info', 'In Review'],
            'in review' => ['info'],
            'in_maintenance' => ['lagoon', 'In Maintenance'],
            'in progress' => ['info'],
            'resolved' => ['success'],
            'closed' => ['muted'],
        ],
        // ict_asset_maintenances.status
        'maintenance' => [
            'open' => ['warning'],
            'in progress' => ['info'],
            'completed' => ['success'],
            'cancelled' => ['muted'],
        ],
        'severity' => [
            'low' => ['muted'],
            'medium' => ['info'],
            'high' => ['warning'],
            'critical' => ['danger'],
        ],
        'asset' => [
            'active' => ['success'],
            'in repair' => ['warning'],
            'retired' => ['muted'],
            'lost' => ['danger'],
        ],
        // mdm_device_commands.status
        'mdm-command' => [
            'requested' => ['muted', 'Queued'],
            'sent' => ['info', 'Sent to Google'],
            'acknowledged' => ['success', 'Acknowledged'],
            'failed' => ['danger'],
        ],
        'credit-union' => [
            'draft' => ['muted'],
            'pending' => ['warning'],
            'awaiting_guarantor' => ['warning', 'Awaiting Guarantor'],
            'imported' => ['info'],
            'computed' => ['info'],
            'approved' => ['success'],
            'accepted' => ['success'],
            'disbursed' => ['info'],
            'active' => ['success'],
            'posted' => ['success'],
            'reconciled' => ['success'],
            'paid' => ['success'],
            'completed' => ['muted'],
            'exited' => ['muted'],
            'inactive' => ['muted'],
            'variance' => ['warning'],
            'rejected' => ['danger'],
            'declined' => ['danger'],
            'defaulted' => ['danger'],
        ],
    ];

    // Fallback for values used outside a declared domain.
    $generic = [
        'approved' => 'success', 'active' => 'success', 'resolved' => 'success', 'completed' => 'success',
        'paid' => 'success', 'posted' => 'success', 'recommended' => 'info', 'received' => 'info',
        'pending' => 'warning', 'pending approval' => 'warning', 'in review' => 'warning', 'open' => 'warning',
        'maintenance' => 'warning', 'denied' => 'danger', 'rejected' => 'danger', 'lost' => 'danger',
        'critical' => 'danger', 'failed' => 'danger', 'closed' => 'muted', 'planned' => 'muted',
        'inactive' => 'muted', 'retired' => 'muted', 'draft' => 'muted',
    ];

    $entry = $domain ? ($maps[$domain][$key] ?? null) : null;
    $resolvedTone = $tone ?? ($entry[0] ?? ($generic[$key] ?? 'muted'));
    $resolvedLabel = $label ?? ($entry[1] ?? (
        $raw !== '' && ($raw === \Illuminate\Support\Str::lower($raw) || str_contains($raw, '_'))
            ? \Illuminate\Support\Str::headline($raw)
            : $raw
    ));
@endphp

<span {{ $attributes->class(['ui-pill', 'ui-pill-'.$resolvedTone, 'ui-pill-live' => $live]) }}>{{ $resolvedLabel !== '' ? $resolvedLabel : '—' }}</span>
