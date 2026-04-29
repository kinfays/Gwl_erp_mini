<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Letters Dashboard</h2>
            <p>{{ now()->format('F Y') }} correspondence overview</p>
        </div>
        <div class="ph-right">
            <a href="{{ route('letters.active') }}" class="btn">Active Letters</a>
            @if (auth()->user()?->hasRoles('super_admin') || auth()->user()?->hasPermission('letters.create'))
                <a href="{{ route('letters.create') }}" class="btn btn-primary">+ New Letter</a>
            @endif
        </div>
    </div>

    @if ($missingEmployee)
        <div class="erp-card" style="margin-top:14px;background:#fcebeb;border-color:#f7c1c1;color:#a32d2d">
            Your user account is not linked to an employee record, so letters cannot be assigned to you.
        </div>
    @else
        <div class="stats" style="margin-top:14px">
            <div class="stat">
                <div class="stat-lbl">Total</div>
                <div class="stat-val">{{ $stats['total'] }}</div>
            </div>
            <div class="stat">
                <div class="stat-lbl">Pending Review</div>
                <div class="stat-val">{{ $stats['pending'] }}</div>
            </div>
            <div class="stat">
                <div class="stat-lbl">Dispatch</div>
                <div class="stat-val">{{ $stats['dispatched'] }}</div>
            </div>
            <div class="stat">
                <div class="stat-lbl">Closed</div>
                <div class="stat-val">{{ $stats['closed'] }}</div>
            </div>
        </div>

        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Requires your attention</span>
                <a href="{{ route('letters.active') }}" class="actn">View all</a>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>SN#</th>
                        <th>Subject</th>
                        <th>Sender</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($attentionLetters as $letter)
                        @php($status = $letter->latestStatusFor(auth()->user()->employee ?? auth()->user()->employeeByStaffId)?->status ?? 'Received')
                        <tr>
                            <td style="color:#185FA5">{{ $letter->sn_number }}</td>
                            <td>
                                <a href="{{ route('letters.active', ['letter' => $letter->id]) }}" style="color:inherit;text-decoration:none">
                                    {{ $letter->subject }}
                                </a>
                                <div style="font-size:10px;color:var(--color-text-secondary)">{{ $letter->ref_no ?: 'No reference' }}</div>
                            </td>
                            <td>{{ $letter->sender_name }}</td>
                            <td><span class="pill {{ $status === 'Received' ? 'p-g' : 'p-b' }}">{{ $status }}</span></td>
                            <td>{{ $letter->date_on_letter?->format('d M Y') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" style="text-align:center;color:var(--color-text-secondary);padding:20px">
                                No letters need your attention.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
