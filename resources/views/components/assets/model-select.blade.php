{{-- Model dropdown shared by the Assets, Phones and Network forms. Native <option>s can't hold images, so the selected model's thumbnail is shown beside the select instead. --}}
@props([
    'models',
    'model' => 'form.ict_asset_model_id',
    'label' => 'Model',
])

<div class="form-field">
    <label class="form-label">{{ $label }}</label>
    <div
        style="display:flex;gap:8px;align-items:center"
        x-data="{
            src: null,
            sync() {
                const option = this.$refs.select.selectedOptions[0];
                this.src = option?.dataset.image || null;
            },
        }"
        x-init="$nextTick(() => sync())"
    >
        <img
            x-show="src"
            x-cloak
            x-bind:src="src"
            alt=""
            style="flex:none;width:34px;height:34px;object-fit:cover;border-radius:6px;border:0.5px solid var(--color-border-tertiary)"
        >
        <select x-ref="select" x-on:change="sync()" wire:model.defer="{{ $model }}" class="form-input">
            <option value="">Select model</option>
            @foreach ($models as $assetModel)
                <option value="{{ $assetModel->id }}" data-image="{{ $assetModel->imageUrl() }}">
                    {{ $assetModel->name }} ({{ $assetModel->manufacturer?->name ?: 'n/a' }})
                </option>
            @endforeach
        </select>
    </div>
    @error($model) <div class="txt-err">{{ $message }}</div> @enderror
</div>
