@props(['model', 'label', 'error' => null, 'type' => 'text', 'disabled' => false])
@php
    $errorKey = $error ?? $model;
    $fieldId = 'si-'.str_replace(['.', '_'], '-', $model);
@endphp
<div class="si-field">
    <x-input-label :for="$fieldId" :value="$label" />
    <x-text-input :id="$fieldId" :type="$type" :disabled="$disabled"
        wire:model.live.debounce.300ms="{{ $model }}"
        :aria-invalid="$errors->has($errorKey) ? 'true' : 'false'"
        :aria-describedby="$errors->has($errorKey) ? $fieldId.'-error' : null"
        {{ $attributes->merge(['class' => 'block w-full']) }} />
    <div id="{{ $fieldId }}-error"><x-input-error :messages="$errors->get($errorKey)" /></div>
</div>
