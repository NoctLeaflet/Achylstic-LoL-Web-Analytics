@props(['name', 'id' => null, 'size' => 'size-10'])

@php
    $url = app(\App\Services\Riot\DataDragonService::class)->championIcon($name, $id);
@endphp

@if ($url)
    <img src="{{ $url }}" alt="{{ $name }}" title="{{ $name }}" loading="lazy"
         {{ $attributes->class([$size, 'shrink-0 rounded-lg bg-slate-800 ring-1 ring-slate-700']) }}>
@else
    <span title="{{ $name }}"
          {{ $attributes->class([$size, 'flex shrink-0 items-center justify-center rounded-lg bg-slate-800 text-sm font-semibold text-slate-400 ring-1 ring-slate-700']) }}>
        {{ mb_strtoupper(mb_substr($name, 0, 1)) }}
    </span>
@endif
