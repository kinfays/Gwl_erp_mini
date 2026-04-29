<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Historical Visitor Log</h2>
            <p>Read-only visitor activity by date.</p>
        </div>
        <div class="ph-right">
            <a href="{{ route('visitors.export.excel', ['date' => $exportDate]) }}" class="btn">Export Excel</a>
            <a href="{{ route('visitors.export.pdf', ['date' => $exportDate]) }}" class="btn">Export PDF</a>
        </div>
    </div>

    <div class="pg" style="margin-top:14px">
        <div class="pg-head">
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <input type="date" wire:model.live="date" class="form-input">
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
                        <td>{{ $visitor->check_in_at?->format('d M Y h:i A') }}</td>
                        <td>{{ $visitor->check_out_at?->format('d M Y h:i A') ?: '-' }}</td>
                        <td>{{ $visitor->checkout_code }}</td>
                        <td><span class="pill {{ $visitor->check_out_at ? 'p-d' : 'p-g' }}">{{ $visitor->status }}</span></td>
                        <td><button type="button" wire:click="showSignature({{ $visitor->id }})" class="actn">View</button></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" style="text-align:center;color:var(--color-text-secondary);padding:20px">No visitors found for this day.</td>
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
