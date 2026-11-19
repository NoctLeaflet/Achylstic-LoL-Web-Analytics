@props(['queue', 'options' => null, 'all' => false])

@php
    // Leaderboard: just the ranked queues. Other pages pass every tracked game mode and ask for an "all" choice.
    $options ??= \App\Enums\RankedQueue::cases();
@endphp

{{-- Game mode switch. Plain links, so the page stays server-rendered and other query parameters are kept. --}}
<div class="inline-flex flex-wrap gap-0.5 rounded-lg border border-slate-800 bg-slate-900 p-1 text-sm" aria-label="Pilih mode game">
    @if ($all)
        <a href="{{ request()->fullUrlWithQuery(['queue' => 'all']) }}"
           aria-current="{{ $queue === null ? 'page' : 'false' }}"
           @class([
               'rounded-md px-3 py-1.5 font-medium transition',
               'bg-sky-500/20 text-sky-300' => $queue === null,
               'text-slate-400 hover:text-slate-200' => $queue !== null,
           ])>Semua</a>
    @endif

    @foreach ($options as $option)
        <a href="{{ request()->fullUrlWithQuery(['queue' => $option->slug()]) }}"
           aria-current="{{ $option === $queue ? 'page' : 'false' }}"
           @class([
               'rounded-md px-3 py-1.5 font-medium transition',
               'bg-sky-500/20 text-sky-300' => $option === $queue,
               'text-slate-400 hover:text-slate-200' => $option !== $queue,
           ])>{{ $option->label() }}</a>
    @endforeach
</div>
