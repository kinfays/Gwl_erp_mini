<div wire:poll.30s>
    <div class="page-head">
        <div class="ph-left">
            <h2>Today's Visitor Log</h2>
            <p>{{ now()->format('l, d F Y') }}</p>
        </div>
        <div class="ph-right">
            <a href="{{ route('visitors.export.excel', ['date' => today()->toDateString()]) }}" class="btn">Export Excel</a>
            <a href="{{ route('visitors.export.pdf', ['date' => today()->toDateString()]) }}" class="btn">Export PDF</a>
            <a href="{{ route('visitors.kiosk') }}" target="_blank" class="btn btn-primary">Kiosk Screen</a>
        </div>
    </div>

    <div class="stats" style="margin-top:14px">
        <div class="stat">
            <div class="stat-lbl">Total Today</div>
            <div class="stat-val">{{ $stats['total'] }}</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Currently Inside</div>
            <div class="stat-val">{{ $stats['inside'] }}</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Checked Out</div>
            <div class="stat-val">{{ $stats['out'] }}</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Auto-checkout Time</div>
            <div class="stat-val">{{ $stats['autoTime'] }}</div>
        </div>
    </div>

    <div class="pg">
        <div class="pg-head">
            <span class="pg-title">Live visitor table</span>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <input type="text" wire:model.live="search" class="form-input" placeholder="Search visitor or staff">
                <select wire:model.live="status" class="form-input">
                    <option value="">All statuses</option>
                    <option value="inside">Inside</option>
                    <option value="out">Out</option>
                </select>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Visitor Name</th>
                    <th>Visiting</th>
                    <th>Purpose</th>
                    <th>Check-in Time</th>
                    <th>Check-out Time</th>
                    <th>Code</th>
                    <th>Status</th>
                    <th>Signature</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($visitors as $visitor)
                    <tr>
                        <td>
                            <div>{{ $visitor->visitor_name }}</div>
                            <div style="font-size:10px;color:var(--color-text-secondary)">{{ $visitor->phone ?: 'No phone' }}</div>
                        </td>
                        <td>
                            <div>{{ $visitor->staff?->full_name ?? '-' }}</div>
                            <div style="font-size:10px;color:var(--color-text-secondary)">{{ $visitor->staff?->department?->department_name ?? '-' }}</div>
                        </td>
                        <td>{{ $visitor->purpose ?: '-' }}</td>
                        <td>{{ $visitor->check_in_at?->format('h:i A') }}</td>
                        <td>{{ $visitor->check_out_at?->format('h:i A') ?: '-' }}</td>
                        <td>
                            @if ($visitor->check_out_at)
                                {{ $visitor->checkout_code }}
                            @else
                                <span class="pill p-g">Still Inside</span>
                            @endif
                        </td>
                        <td><span class="pill {{ $visitor->check_out_at ? 'p-d' : 'p-g' }}">{{ $visitor->status }}</span></td>
                        <td><button type="button" wire:click="showSignature({{ $visitor->id }})" class="actn">View</button></td>
                        <td>
                            @if (! $visitor->check_out_at)
                                <button type="button" wire:click="checkOut({{ $visitor->id }})" class="actn actn-p">Check Out</button>
                            @else
                                <button type="button" wire:click="showSignature({{ $visitor->id }})" class="actn">View</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" style="text-align:center;color:var(--color-text-secondary);padding:20px">No visitors logged today.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-top:0.5px solid var(--color-border-tertiary);gap:12px;flex-wrap:wrap">
            <div style="font-size:11px;color:var(--color-text-secondary)">
                Showing {{ $visitors->firstItem() ?? 0 }} - {{ $visitors->lastItem() ?? 0 }} of {{ $visitors->total() }} visitors
            </div>
            <div>{{ $visitors->links() }}</div>
        </div>
    </div>

    @if ($signatureVisitor)
        <div class="letter-panel-backdrop">
            <div class="visitor-signature-modal">
                <div class="pg-head">
                    <span class="pg-title">{{ $signatureVisitor->visitor_name }} signature</span>
                    <button type="button" wire:click="closeSignature" class="actn">Close</button>
                </div>
                <div style="padding:14px">
                    <img src="{{ $signatureVisitor->signature }}" alt="Visitor signature" style="width:100%;border:0.5px solid var(--color-border-tertiary);border-radius:8px;background:#fff">
                </div>
            </div>
        </div>
    @endif
</div>
