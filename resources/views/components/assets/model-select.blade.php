{{-- Model picker shared by the Assets, Phones and Network forms: a searchable list (name, manufacturer) that shows the chosen model's thumbnail beside it. The list is already limited to the form's asset type by the component. --}}
@props([
    'models',
    'model' => 'form.ict_asset_model_id',
    'label' => 'Model',
])

@php
    $options = collect($models)->map(fn ($assetModel) => [
        'value' => $assetModel->id,
        'label' => $assetModel->name,
        'description' => $assetModel->manufacturer?->name ?: 'n/a',
        'image' => $assetModel->imageUrl(),
    ])->all();
@endphp

<x-form.searchable-select
    {{ $attributes }}
    :label="$label"
    :model="$model"
    :options="$options"
    placeholder="Search models…"
    empty-text="No models found"
    :reset-key="collect($options)->pluck('value')->implode(',')"
/>
