<x-uac-layout>
<div x-data="auditLogViewer()" x-on:keydown.escape.window="close()">
    <x-ui.page-header title="Audit Log" description="Who changed what, where and when across protected modules." />

    <x-ui.card :padded="false">
        <form method="GET" class="ui-toolbar" role="search">
            <label for="audit-search" class="sr-only-text">Search audit log</label>
            <div class="ui-input-wrap toolbar-grow">
                <x-ui.icon name="search" class="ui-input-icon" />
                <input type="text" id="audit-search" name="search" value="{{ $search }}" placeholder="Search by action, module, target, or IP address" class="form-input ui-input has-icon">
            </div>
            <button type="submit" class="btn btn-primary">Search</button>
        </form>

        <x-ui.table label="Audit log" pin-first>
            <x-slot:head>
                <tr>
                    <th>Action</th>
                    <th>User</th>
                    <th>Module</th>
                    <th>Target</th>
                    <th>IP Address</th>
                    <th>Date</th>
                    <th class="actions"><span class="sr-only-text">Actions</span></th>
                </tr>
            </x-slot:head>

            @forelse ($logs as $log)
                @php
                    $target = trim(($log->target_type ?: '-') . ($log->target_id ? ' #' . $log->target_id : ''));
                    $viewerPayload = [
                        'id' => $log->id,
                        'action' => $log->action,
                        'user' => $log->actorLabelFor(auth()->user()),
                        'module' => strtoupper($log->module ?: 'general'),
                        'target' => $target,
                        'ip_address' => $log->ip_address ?: '-',
                        'date' => $log->created_at?->format('d M Y, h:i A') ?: '-',
                        'old_values' => $log->old_values,
                        'new_values' => $log->new_values,
                        'metadata' => $log->metadata,
                    ];
                @endphp
                <tr>
                    <td><x-ui.badge>{{ $log->action }}</x-ui.badge></td>
                    <td>{{ $viewerPayload['user'] }}</td>
                    <td class="cell-muted">{{ $viewerPayload['module'] }}</td>
                    <td class="mono">{{ $target }}</td>
                    <td class="mono">{{ $viewerPayload['ip_address'] }}</td>
                    <td class="nowrap cell-muted">{{ $viewerPayload['date'] }}</td>
                    <td class="actions">
                        <button type="button" x-on:click="open(@js($viewerPayload))" class="btn btn-ghost btn-sm btn-icon" title="View audit details" aria-label="View audit details">
                            <x-ui.icon name="eye" />
                        </button>
                    </td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="7" icon="scroll-text" title="No audit records found." description="Try a broader search." />
            @endforelse

            @if ($logs->hasPages())
                <x-slot:footer>
                    {{ $logs->links() }}
                </x-slot:footer>
            @endif
        </x-ui.table>
    </x-ui.card>

    <div x-cloak x-show="isOpen" class="ui-modal-backdrop" x-on:click.self="close()">
        <div class="ui-modal ui-modal-lg" role="dialog" aria-modal="true" aria-labelledby="audit-log-title" x-trap.inert.noscroll="isOpen">
            <header class="ui-modal-head">
                <span class="ui-chip ui-chip-primary" aria-hidden="true"><x-ui.icon name="scroll-text" /></span>
                <div class="ui-modal-titles">
                    <p class="ui-eyebrow">Audit Log</p>
                    <h2 id="audit-log-title" class="ui-modal-title" x-text="selected.action || 'Audit details'"></h2>
                </div>
            </header>

            <div class="ui-modal-body ui-stack">
                <dl class="ui-dl">
                    <div>
                        <dt>User</dt>
                        <dd x-text="selected.user || '-'"></dd>
                    </div>
                    <div>
                        <dt>Module</dt>
                        <dd x-text="selected.module || '-'"></dd>
                    </div>
                    <div>
                        <dt>Target</dt>
                        <dd class="mono" x-text="selected.target || '-'"></dd>
                    </div>
                    <div>
                        <dt>IP Address</dt>
                        <dd class="mono" x-text="selected.ip_address || '-'"></dd>
                    </div>
                    <div class="span-2">
                        <dt>Date</dt>
                        <dd x-text="selected.date || '-'"></dd>
                    </div>
                </dl>

                <div class="ui-grid ui-grid-2">
                    <section>
                        <h3 class="ui-panel-title">Old Values</h3>
                        <pre class="code-block" x-text="pretty(selected.old_values)"></pre>
                    </section>
                    <section>
                        <h3 class="ui-panel-title">New Values</h3>
                        <pre class="code-block" x-text="pretty(selected.new_values)"></pre>
                    </section>
                </div>

                <section>
                    <h3 class="ui-panel-title">Metadata</h3>
                    <pre class="code-block" x-text="pretty(selected.metadata)"></pre>
                </section>
            </div>

            <button type="button" x-on:click="close()" class="icon-btn ui-modal-close" aria-label="Close audit details">
                <x-ui.icon name="x" />
            </button>
        </div>
    </div>
</div>

<script>
    function auditLogViewer() {
        return {
            isOpen: false,
            selected: {},
            open(log) {
                this.selected = log || {};
                this.isOpen = true;
            },
            close() {
                this.isOpen = false;
            },
            pretty(value) {
                if (!this.hasData(value)) {
                    return 'None recorded';
                }

                return JSON.stringify(value, null, 2);
            },
            hasData(value) {
                if (value === null || value === undefined || value === '') {
                    return false;
                }

                if (Array.isArray(value)) {
                    return value.length > 0;
                }

                if (typeof value === 'object') {
                    return Object.keys(value).length > 0;
                }

                return true;
            },
        };
    }
</script>
</x-uac-layout>
