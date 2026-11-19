@props(['id', 'size' => 'size-6'])

@php
    $url = $id ? app(\App\Services\Riot\DataDragonService::class)->itemIcon($id) : null;
@endphp

@if ($url)
    <img src="{{ $url }}" alt="" loading="lazy" {{ $attributes->class([$size, 'rounded bg-slate-800 ring-1 ring-slate-700']) }}>
@else
    <span aria-hidden="true" {{ $attributes->class([$size, 'rounded bg-slate-900 ring-1 ring-slate-800']) }}></span>
@endif
