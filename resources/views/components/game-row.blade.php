{{-- One game of a tracked player; $game comes from App\Services\Stats\GameRows. Links to the match page.
     With GameRows::build(..., detailed: true) it also shows items, spells, runes and both line-ups. --}}
@props(['game', 'box' => 'border-slate-800 bg-slate-900/50'])

@php
    $g = $game;
    $detailed = isset($g['items']);
@endphp

<a href="{{ route('matches.show', $g['match_id']) }}"
   @class([
       'flex flex-wrap items-center gap-x-4 gap-y-3 rounded-xl border p-3 transition hover:brightness-125 sm:flex-nowrap',
       $box,
       'glow-mvp' => $g['badge'] === 'MVP',
       'glow-ace' => $g['badge'] === 'ACE',
   ])>
    <div class="flex shrink-0 items-center gap-3">
        {{-- Summoner (profile) icon of the player's account --}}
        <x-profile-icon :player="$g['player']" size="size-12" />
        <div class="w-28 text-xs text-slate-400">
            <p class="truncate text-sm font-semibold text-sky-300">{{ $g['player']->name }}</p>
            <p class="mt-0.5"><span class="font-semibold text-slate-200">{{ \App\Support\Format::duration($g['duration']) }}</span> · {{ $g['started_at']->diffForHumans(short: true) }}</p>
            <p class="truncate">{{ $g['queue'] }}{{ ! empty($g['role']) ? ' · '.$g['role'] : '' }}</p>
        </div>
    </div>

    <div class="flex shrink-0 items-center gap-1.5">
        <x-champion-icon :name="$g['champion_name']" :id="$g['champion_id']" size="size-12" />
        @if ($detailed)
            {{-- Two summoner spells and the keystone / secondary rune --}}
            <div class="grid grid-cols-2 gap-0.5">
                @foreach ($g['spells'] as $spell)
                    @if ($spell['icon'])
                        <img src="{{ $spell['icon'] }}" alt="{{ $spell['name'] }}" title="{{ $spell['name'] }}" loading="lazy" class="size-5 rounded bg-slate-800 ring-1 ring-slate-700">
                    @else
                        <span class="size-5 rounded bg-slate-900 ring-1 ring-slate-800"></span>
                    @endif
                @endforeach
                @if ($g['keystone'])
                    <img src="{{ $g['keystone']['icon'] }}" alt="{{ $g['keystone']['name'] }}" title="{{ $g['keystone']['name'] }}" loading="lazy" class="size-5 rounded-full bg-slate-950 ring-1 ring-slate-700">
                @else
                    <span class="size-5 rounded-full bg-slate-900 ring-1 ring-slate-800"></span>
                @endif
                @if ($g['secondary'])
                    <img src="{{ $g['secondary']['icon'] }}" alt="{{ $g['secondary']['name'] }}" title="{{ $g['secondary']['name'] }}" loading="lazy" class="size-5 rounded-full bg-slate-950 p-0.5 ring-1 ring-slate-700">
                @else
                    <span class="size-5 rounded-full bg-slate-900 ring-1 ring-slate-800"></span>
                @endif
            </div>
        @endif
    </div>

    <div class="min-w-0 flex-1">
        <p class="text-xl font-extrabold tabular-nums text-white">
            {{ $g['kills'] }} <span class="text-slate-500">/</span>
            <span class="text-rose-300">{{ $g['deaths'] }}</span> <span class="text-slate-500">/</span>
            {{ $g['assists'] }}
        </p>
        <p class="text-xs text-slate-400">
            {{ \App\Support\Format::number($g['kda'], 1) }} KDA · {{ \App\Support\Format::number($g['cs_per_min'], 1) }} CS/m · {{ $g['kp'] }}% KP
        </p>
        @if ($detailed)
            <div class="mt-1.5 flex gap-0.5">
                @foreach ($g['items'] as $item)
                    <x-item-icon :id="$item" size="size-6" />
                @endforeach
            </div>
        @endif
    </div>

    {{-- Both teams: your team on the left, the enemy on the right --}}
    @if ($detailed && count($g['allies']) + count($g['enemies']) > 0)
        <div class="hidden shrink-0 grid-cols-2 gap-x-3 text-[11px] leading-4 lg:grid" aria-label="Line-up">
            @foreach ([$g['allies'], $g['enemies']] as $team)
                <ul class="w-24 space-y-px">
                    @foreach ($team as $person)
                        <li class="flex items-center gap-1">
                            <x-champion-icon :name="$person['champion_name']" :id="$person['champion_id']" size="size-4" class="!rounded-sm" />
                            <span @class(['truncate', 'font-semibold text-white' => $person['self'], 'text-sky-300' => $person['friend'] && ! $person['self'], 'text-slate-500' => ! $person['friend'] && ! $person['self']])>{{ $person['name'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endforeach
        </div>
    @endif

    <div class="ml-auto w-14 shrink-0 text-right">
        <p @class([
            'text-3xl font-extrabold tabular-nums',
            'text-violet-300' => $g['score'] >= 70,
            'text-slate-300' => $g['score'] < 70,
        ])>{{ $g['score'] }}</p>
        @if ($g['badge'])
            <p class="text-xs font-bold tracking-wide text-amber-300">{{ $g['badge'] }}</p>
        @else
            <p @class(['text-xs', 'text-emerald-400' => $g['win'], 'text-rose-400' => ! $g['win']])>{{ $g['win'] ? 'Win' : 'Lose' }}</p>
        @endif
        @if ($g['rank'] && ! $g['badge'])
            <p class="text-xs font-semibold tabular-nums text-slate-400" title="Urutan skor di match ini">#{{ $g['rank'] }}</p>
        @endif
    </div>
</a>
