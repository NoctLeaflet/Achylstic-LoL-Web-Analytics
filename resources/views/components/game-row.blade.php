{{-- One game of a tracked player; $game comes from App\Services\Stats\GameRows. Links to the match page. --}}
@props(['game', 'box' => 'border-slate-800 bg-slate-900/50'])

@php
    $g = $game;
@endphp

<a href="{{ route('matches.show', $g['match_id']) }}"
   class="flex flex-wrap items-center gap-x-5 gap-y-3 rounded-xl border p-3 transition hover:brightness-125 sm:flex-nowrap {{ $box }}">
    <div class="w-28 shrink-0 text-xs text-slate-400">
        <p><span class="font-semibold text-slate-200">{{ \App\Support\Format::duration($g['duration']) }}</span> · {{ $g['started_at']->diffForHumans(short: true) }}</p>
        <p class="mt-0.5 truncate font-semibold text-slate-200">{{ $g['queue'] }}</p>
        <p class="truncate font-semibold text-sky-300">{{ $g['player']->name }}</p>
    </div>

    <x-champion-icon :name="$g['champion_name']" :id="$g['champion_id']" size="size-12" />

    <div class="min-w-0 flex-1">
        <p class="text-xl font-extrabold tabular-nums text-white">
            {{ $g['kills'] }} <span class="text-slate-500">/</span>
            <span class="text-rose-300">{{ $g['deaths'] }}</span> <span class="text-slate-500">/</span>
            {{ $g['assists'] }}
        </p>
        <p class="text-xs text-slate-400">
            {{ \App\Support\Format::number($g['kda'], 1) }} KDA · {{ \App\Support\Format::number($g['cs_per_min'], 1) }} CS/m · {{ $g['kp'] }}% KP
        </p>
    </div>

    <div class="ml-auto text-right">
        <p @class([
            'text-3xl font-extrabold tabular-nums',
            'text-violet-300' => $g['score'] >= 70,
            'text-slate-300' => $g['score'] < 70,
        ])>{{ $g['score'] }}</p>
        @if ($g['badge'])
            <p class="text-xs font-bold tracking-wide text-amber-300">{{ $g['badge'] }}</p>
        @else
            <p class="text-xs text-slate-500">{{ $g['win'] ? 'Menang' : 'Kalah' }}</p>
        @endif
    </div>
</a>
