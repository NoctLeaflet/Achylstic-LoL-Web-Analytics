@props(['player', 'size' => 'size-10'])

@php
    $url = app(\App\Services\Riot\DataDragonService::class)->profileIcon($player->profile_icon_id);
@endphp

@if ($url)
    <img src="{{ $url }}" alt="" loading="lazy"
         {{ $attributes->class([$size, 'shrink-0 rounded-full bg-slate-800 ring-1 ring-slate-700']) }}>
@else
    <span aria-hidden="true"
          {{ $attributes->class([$size, 'flex shrink-0 items-center justify-center rounded-full bg-slate-800 text-sm font-semibold text-slate-400 ring-1 ring-slate-700']) }}>
        {{ mb_strtoupper(mb_substr($player->name, 0, 1)) }}
    </span>
@endif
