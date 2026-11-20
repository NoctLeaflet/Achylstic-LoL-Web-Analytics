@props(['game'])

{{-- "Sedang main" badge for a LiveGame: pulsing dot, mode and how long the game has been running. --}}
@php
    $champions = app(\App\Services\Riot\DataDragonService::class)->championNames();
    $queue = \App\Enums\MatchQueue::fromQueueId($game->queue_id)?->label();
    $champion = $game->champion_id ? ($champions[$game->champion_id] ?? null) : null;
    $minutes = max((int) $game->started_at->diffInMinutes(now(), true), 0);
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-full border border-rose-500/40 bg-rose-500/10 px-2.5 py-0.5 text-xs font-medium text-rose-200']) }}>
    <span class="relative flex size-2" aria-hidden="true">
        <span class="absolute inline-flex size-full animate-ping rounded-full bg-rose-400 opacity-75"></span>
        <span class="relative inline-flex size-2 rounded-full bg-rose-400"></span>
    </span>
    Sedang main{{ $queue ? ' · '.$queue : '' }}{{ $champion ? ' · '.$champion : '' }} · {{ $minutes }} mnt
</span>
