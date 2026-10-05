<div>
    <x-ui.page-header title="My Signature" description="The signature printed on the leave approval letters you sign. Only you can see, change or apply it." />

    @if (session('success'))
        <x-ui.alert tone="success" role="status">{{ session('success') }}</x-ui.alert>
    @endif

    <div class="ui-grid ui-grid-main">
        <x-ui.card title="Add or replace your signature" description="Draw it, or upload a picture of it. You'll be asked for your password to save.">
            <x-ui.segmented label="How" wire:model.live="tab" :options="['draw' => 'Draw', 'upload' => 'Upload']" />

            @if ($tab === 'draw')
                <div
                    x-data="{
                        canvas: null, ctx: null, strokes: [], stroke: null, drawing: false,
                        init() {
                            this.canvas = this.$refs.pad;
                            const ratio = window.devicePixelRatio || 1;
                            const width = this.canvas.clientWidth || 600;
                            this.canvas.width = width * ratio;
                            this.canvas.height = 200 * ratio;
                            this.ctx = this.canvas.getContext('2d');
                            this.ctx.scale(ratio, ratio);
                            this.ctx.lineWidth = 2.4;
                            this.ctx.lineCap = 'round';
                            this.ctx.lineJoin = 'round';
                            this.ctx.strokeStyle = '#111111';
                        },
                        point(event) {
                            const box = this.canvas.getBoundingClientRect();
                            return { x: event.clientX - box.left, y: event.clientY - box.top };
                        },
                        start(event) {
                            this.canvas.setPointerCapture(event.pointerId);
                            this.drawing = true;
                            this.stroke = [this.point(event)];
                            this.strokes.push(this.stroke);
                            this.redraw();
                        },
                        move(event) {
                            if (! this.drawing) return;
                            this.stroke.push(this.point(event));
                            this.redraw();
                        },
                        end() { this.drawing = false; },
                        redraw() {
                            this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
                            for (const stroke of this.strokes) {
                                this.ctx.beginPath();
                                stroke.forEach((p, i) => i === 0 ? this.ctx.moveTo(p.x, p.y) : this.ctx.lineTo(p.x, p.y));
                                if (stroke.length === 1) this.ctx.lineTo(stroke[0].x + 0.01, stroke[0].y);
                                this.ctx.stroke();
                            }
                        },
                        undo() { this.strokes.pop(); this.redraw(); },
                        clear() { this.strokes = []; this.redraw(); },
                        async save() {
                            if (! this.strokes.length) return;
                            await $wire.set('drawn', this.canvas.toDataURL('image/png'));
                            $wire.saveDrawn();
                        },
                    }"
                >
                    <div wire:ignore>
                    <canvas
                        x-ref="pad"
                        class="form-input"
                        style="width: 100%; height: 200px; touch-action: none; background: #fff; cursor: crosshair;"
                        role="img"
                        aria-label="Signature pad. Draw your signature with a mouse, finger or stylus."
                        x-on:pointerdown.prevent="start($event)"
                        x-on:pointermove.prevent="move($event)"
                        x-on:pointerup="end()"
                        x-on:pointercancel="end()"
                    ></canvas>
                    </div>

                    <div class="ui-form-actions">
                        <button type="button" class="btn btn-ghost btn-sm" x-on:click="undo()" x-bind:disabled="! strokes.length">Undo</button>
                        <button type="button" class="btn btn-ghost btn-sm" x-on:click="clear()" x-bind:disabled="! strokes.length">Clear</button>
                    </div>

                    <x-ui.input type="password" label="Your password" wire:model="password" autocomplete="current-password" required />
                    @error('signature') <p class="ui-error"><span>{{ $message }}</span></p> @enderror

                    <div class="ui-form-actions">
                        <button type="button" class="btn btn-primary" x-on:click="save()" x-bind:disabled="! strokes.length">
                            {{ $current ? 'Replace my signature' : 'Save my signature' }}
                        </button>
                    </div>
                </div>
            @else
                <x-ui.field label="Picture of your signature (PNG or JPG, up to 1 MB)" for="signature-upload" error="upload">
                    <input id="signature-upload" type="file" wire:model="upload" accept="image/png,image/jpeg" class="form-input ui-input">
                </x-ui.field>

                @if ($upload)
                    <div class="signature-preview">
                        <p class="ui-hint">Preview. The background is made transparent and the picture is resized when you save.</p>
                        <img src="{{ $upload->temporaryUrl() }}" alt="Preview of the signature you chose" style="max-height: 120px; background: #fff; border: 1px solid #ddd; padding: 6px">
                    </div>
                @endif

                <x-ui.input type="password" label="Your password" wire:model="password" autocomplete="current-password" required />
                @error('signature') <p class="ui-error"><span>{{ $message }}</span></p> @enderror

                <div class="ui-form-actions">
                    <button type="button" wire:click="saveUpload" wire:loading.attr="disabled" class="btn btn-primary" @disabled(! $upload)>
                        {{ $current ? 'Replace my signature' : 'Save my signature' }}
                    </button>
                </div>
            @endif
        </x-ui.card>

        <x-ui.card title="Your saved signature">
            @if ($current)
                <img src="{{ $currentImage }}" alt="Your saved signature" style="max-width: 100%; max-height: 140px; background: #fff; border: 1px solid #ddd; padding: 6px">
                <p class="ui-hint">Saved {{ $current->created_at->format('d M Y') }} ({{ $current->method }}). It is kept encrypted and only goes on a letter you approve or sign.</p>
                <button
                    type="button"
                    class="btn btn-danger btn-sm"
                    x-data
                    x-on:click.prevent="$dispatch('confirm-action', {
                        title: 'Delete your signature?',
                        message: 'It will no longer appear on any letter. Letters you have already printed keep a blank signing space on any reprint.',
                        confirmLabel: 'Delete',
                        variant: 'danger',
                        action: () => $wire.deleteSignature()
                    })"
                >Delete my signature</button>
            @else
                <p class="ui-hint">You have no saved signature yet. Without one, the signing space on your letters stays blank for a wet signature.</p>
            @endif
        </x-ui.card>
    </div>
</div>
