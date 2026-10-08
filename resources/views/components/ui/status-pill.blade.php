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
            'damaged' => ['danger'],
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
        // commercial_import_batches.status / commercial_reading_stats.match_status
        'commercial' => [
            'imported' => ['success'],
            'superseded' => ['muted'],
            'voided' => ['danger'],
            'matched' => ['success'],
            'unmatched' => ['warning'],
            'system_account' => ['muted', 'System account'],
            'pass' => ['success', 'Passed'],
            'fail' => ['danger', 'Failed'],
        ],
        // hs_incidents.status
        'hs-incident' => [
            'reported' => ['warning'],
            'acknowledged' => ['info'],
            'investigating' => ['lagoon'],
            'pending_closure' => ['warning', 'Awaiting approval'],
            'closed' => ['muted'],
            'cancelled' => ['muted'],
        ],
        // hs_incident_actions.status
        'hs-action' => [
            'open' => ['warning'],
            'done' => ['info'],
            'verified' => ['success'],
        ],
        // hs_fire_extinguishers: the computed state, and the lifecycle status for a unit that has none
        'hs-extinguisher' => [
            'expired' => ['danger'],
            'service_overdue' => ['danger'],
            'hydro_overdue' => ['danger'],
            'expiring' => ['warning'],
            'service_due_soon' => ['warning'],
            'check_failed' => ['danger'],
            'check_overdue' => ['warning'],
            'ok' => ['success', 'OK'],
            'pass' => ['success', 'Passed'],
            'fail' => ['danger', 'Failed'],
            'in_service' => ['success'],
            'out_for_service' => ['info'],
            'discharged' => ['danger'],
            'decommissioned' => ['muted'],
        ],
        // hs_ppe_issues: the computed state of an open issue, and the status of a closed one
        'hs-ppe-issue' => [
            'overdue' => ['danger'],
            'replacement_due' => ['warning', 'Replacement due'],
            'ok' => ['success', 'In date'],
            'issued' => ['info'],
            'returned' => ['muted'],
            'worn_out' => ['muted', 'Worn out'],
            'damaged' => ['warning'],
            'lost' => ['danger'],
        ],
        // PpeComplianceService states (staff x entitled PPE type)
        'hs-ppe-state' => [
            'missing' => ['danger'],
            'overdue' => ['danger'],
            'short' => ['warning'],
            'replacement_due' => ['warning', 'Replacement due'],
            'ok' => ['success', 'OK'],
        ],
        // ExpiryRegisterService buckets (the dated-items register)
        'hs-expiry' => [
            'overdue' => ['danger', 'Overdue'],
            'critical' => ['danger', 'Critical'],
            'due_soon' => ['warning', 'Due soon'],
            'later' => ['muted', 'Later'],
        ],
        // hs_first_aid_kits: the computed state, and the lifecycle status for a kit that has none
        'hs-kit' => [
            'missing' => ['danger'],
            'item_expired' => ['danger'],
            'item_expiring' => ['warning'],
            'incomplete' => ['warning'],
            'check_failed' => ['danger'],
            'check_overdue' => ['warning'],
            'ok' => ['success', 'OK'],
            'pass' => ['success', 'Passed'],
            'fail' => ['danger', 'Failed'],
            'in_service' => ['success'],
            'decommissioned' => ['muted'],
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
