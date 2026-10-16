@props(['checks', 'height' => 'h-9'])

{{-- Eén balkje per meting: groen is bereikbaar, rood een misser --}}
<div {{ $attributes->merge(['class' => "flex {$height} items-stretch gap-px"]) }} role="img" aria-label="{{ __('Laatste :count metingen', ['count' => $checks->count()]) }}">
    @foreach ($checks as $check)
        <span wire:key="bar-{{ $check->id }}" @class(['min-w-0 flex-1 rounded-sm', 'bg-success' => $check->is_up, 'bg-danger' => ! $check->is_up])
            title="{{ $check->checked_at->isoFormat('D MMM HH:mm') }} · {{ $check->is_up ? ($check->response_ms !== null ? $check->response_ms.' ms' : __('Online')) : ($check->error ?? __('Offline')) }}"></span>
    @endforeach
</div>
