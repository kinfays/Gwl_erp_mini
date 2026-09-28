{{-- Model dropdown shared by the Assets, Phones and Network forms. Native <option>s can't hold images, so the selected model's thumbnail is shown beside the select instead. --}}
@props([
    'models',
    'model' => 'form.ict_asset_model_id',
    'label' => 'Model',
])

@php
    $selectId = 'f-'.\Illuminate\Support\Str::slug(str_replace(['.', '_'], '-', $model));
    $invalid = isset($errors) && $errors->has($model);
@endphp

<div {{ $attributes->class(['ui-field']) }}>
    <label class="ui-label" for="{{ $selectId }}">{{ $label }}</label>
    <div
        class="model-select"
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
            class="model-select-thumb"
        >
        <select
            id="{{ $selectId }}"
            x-ref="select"
            x-on:change="sync()"
            wire:model.defer="{{ $model }}"
            @class(['form-input', 'ui-input', 'is-invalid' => $invalid])
            @if ($invalid) aria-invalid="true" aria-describedby="{{ $selectId }}-error" @endif
        >
            <option value="">Select model</option>
            @foreach ($models as $assetModel)
                <option value="{{ $assetModel->id }}" data-image="{{ $assetModel->imageUrl() }}">
                    {{ $assetModel->name }} ({{ $assetModel->manufacturer?->name ?: 'n/a' }})
                </option>
            @endforeach
        </select>
    </div>
    @error($model)
        <p class="ui-error" id="{{ $selectId }}-error">
            <x-ui.icon name="circle-alert" class="icon-sm" />
            <span>{{ $message }}</span>
        </p>
    @enderror
</div>
