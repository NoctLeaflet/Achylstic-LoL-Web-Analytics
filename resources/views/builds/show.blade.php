@extends('layouts.app')

@section('title', 'Build '.$champion['name'].' · '.config('app.name'))
@section('description', 'Rune dan item '.$champion['name'].' yang dipakai member grup'.($build ? ': '.$build['games'].' game, winrate '.$build['winrate'].'%.' : '.'))

@section('content')
    @php
        $select = 'rounded-md border border-slate-700 bg-slate-950 px-3 py-1.5 text-sm text-white focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500';
    @endphp

    <a href="{{ route('builds', array_filter(['player' => $member?->id, 'mode' => $mode?->slug()])) }}" class="text-sm text-slate-400 hover:text-slate-200">&larr; Semua champion</a>

    <div class="mt-3 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-800 bg-slate-900/50 p-4 sm:p-5">
        <div class="flex items-center gap-4">
            <x-champion-icon :name="$champion['name']" :id="$champion['champion_id']" size="size-16" />
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-white">{{ $champion['name'] }}</h1>
                @if ($build)
                    <p class="text-sm tabular-nums text-slate-400">
                        {{ $build['games'] }} game ·
                        <span @class(['font-semibold', 'text-emerald-400' => $build['winrate'] >= 50, 'text-rose-400' => $build['winrate'] < 50])>{{ $build['winrate'] }}% win</span>
                        · {{ \App\Support\Format::number($build['kda'], 2) }} KDA
                    </p>
                @endif
                <p class="text-xs text-slate-500">{{ $member ? $member->name : 'Semua member' }}{{ $mode ? ' · '.$mode->label() : '' }}</p>
            </div>
        </div>

        <form method="GET" action="{{ route('builds.show', $champion['champion_id']) }}" class="flex flex-wrap gap-2">
            <label for="s-player" class="sr-only">Member</label>
            <select id="s-player" name="player" onchange="this.form.submit()" class="{{ $select }}">
                <option value="">Semua member</option>
                @foreach ($members as $m)
                    <option value="{{ $m->id }}" @selected($member?->id === $m->id)>{{ $m->name }}</option>
                @endforeach
            </select>
            <label for="s-mode" class="sr-only">Mode game</label>
            <select id="s-mode" name="mode" onchange="this.form.submit()" class="{{ $select }}">
                <option value="">Semua mode</option>
                @foreach ($modes as $m)
                    <option value="{{ $m->slug() }}" @selected($mode === $m)>{{ $m->label() }}</option>
                @endforeach
            </select>
            <noscript><button type="submit" class="rounded-md border border-slate-700 px-3 py-1.5 text-sm text-slate-200">Terapkan</button></noscript>
        </form>
    </div>

    @if ($build === null)
        <div class="mt-6 rounded-xl border border-dashed border-slate-700 p-8 text-center">
            <p class="font-medium text-white">
                {{ $member ? $member->name : 'Member' }} belum pernah memainkan {{ $champion['name'] }}{{ $mode ? ' di '.$mode->label() : '' }}.
            </p>
            <p class="mt-2 text-sm text-slate-400">Coba pilih member atau mode lain, atau lihat build semua member.</p>
            <div class="mt-4 flex flex-wrap justify-center gap-2">
                <a href="{{ route('builds.show', $champion['champion_id']) }}"
                   class="rounded-md bg-sky-500 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-sky-400">Lihat semua member</a>
                <a href="{{ route('builds', array_filter(['player' => $member?->id, 'mode' => $mode?->slug()])) }}"
                   class="rounded-md border border-slate-600 px-4 py-2 text-sm font-semibold text-slate-100 transition hover:bg-slate-800">
                    Champion yang dimainkan {{ $member ? $member->name : 'member' }}
                </a>
            </div>
        </div>
    @else
    @if ($build['thin'])
        <p role="status" class="mt-3 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-200">
            Baru {{ $build['games'] }} game (kurang dari {{ $thinSample }}), jadi angka di bawah baru kesan awal, belum patokan.
        </p>
    @endif

    {{-- Runes --}}
    <section class="mt-6" aria-labelledby="runes-heading">
        <h2 id="runes-heading" class="text-lg font-semibold text-white">Rune paling sering</h2>

        @if (count($build['rune_pages']) === 0)
            <p class="mt-3 rounded-xl border border-dashed border-slate-700 p-6 text-center text-sm text-slate-400">Belum ada data rune untuk game ini.</p>
        @else
            <div class="mt-3 grid gap-3 lg:grid-cols-3">
                @foreach ($build['rune_pages'] as $page)
                    <article class="rounded-xl border border-slate-800 bg-slate-900/50 p-4">
                        <p class="flex items-baseline justify-between text-sm tabular-nums">
                            <span class="font-semibold text-white">{{ $page['games'] }} game</span>
                            <span @class(['font-semibold', 'text-emerald-400' => $page['winrate'] >= 50, 'text-rose-400' => $page['winrate'] < 50])>{{ $page['winrate'] }}% win</span>
                        </p>

                        @foreach (['primary' => $page['primary'], 'secondary' => $page['secondary']] as $tree)
                            <div class="mt-3">
                                <p class="flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-slate-500">
                                    @if ($tree['icon'])<img src="{{ $tree['icon'] }}" alt="" class="size-4">@endif
                                    {{ $tree['name'] }}
                                </p>
                                <ul class="mt-1.5 flex flex-wrap gap-1.5">
                                    @foreach ($tree['runes'] as $rune)
                                        <li title="{{ $rune['name'] }}">
                                            @if ($rune['icon'])
                                                <img src="{{ $rune['icon'] }}" alt="{{ $rune['name'] }}" loading="lazy" class="size-9 rounded-full bg-slate-800 p-0.5 ring-1 ring-slate-700">
                                            @else
                                                <span class="text-xs text-slate-400">{{ $rune['name'] }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach

                        <p class="mt-3 text-xs text-slate-500">
                            Shard: {{ collect($page['shards'])->pluck('name')->implode(' · ') }}
                        </p>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Items --}}
    <section class="mt-6" aria-labelledby="items-heading">
        <h2 id="items-heading" class="text-lg font-semibold text-white">Item akhir yang sering dibeli</h2>
        <p class="mt-1 text-sm text-slate-400">Persen = winrate di game yang memakai item itu.</p>

        @if (count($build['items']) === 0)
            <p class="mt-3 rounded-xl border border-dashed border-slate-700 p-6 text-center text-sm text-slate-400">Belum ada data item.</p>
        @else
            <ul class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ($build['items'] as $item)
                    <li class="flex items-center gap-3 rounded-xl border border-slate-800 bg-slate-900/50 p-3">
                        @if ($item['icon'])
                            <img src="{{ $item['icon'] }}" alt="" loading="lazy" class="size-10 shrink-0 rounded-md ring-1 ring-slate-700">
                        @endif
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium text-white" title="{{ $item['name'] }}">{{ $item['name'] }}</span>
                            <span class="block text-xs tabular-nums text-slate-400">
                                {{ $item['games'] }}x ·
                                <span @class(['font-semibold', 'text-emerald-400' => $item['winrate'] >= 50, 'text-rose-400' => $item['winrate'] < 50])>{{ $item['winrate'] }}%</span>
                            </span>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Ability order takes the wide column; the compact spells and the top players sit beside it --}}
    <div class="mt-6 grid gap-4 lg:grid-cols-[minmax(0,1fr)_17rem]">
        <aside class="order-2 space-y-4">
        {{-- Summoner spells: one slim line per pair --}}
        <section class="rounded-xl border border-slate-800 bg-slate-900/50 p-3" aria-labelledby="spells-heading">
            <h2 id="spells-heading" class="text-sm font-semibold text-white">Spell yang sering dipakai</h2>
            @forelse ($build['spells'] as $pair)
                <div class="mt-2 flex items-center gap-2" title="{{ collect($pair['spells'])->pluck('name')->implode(' + ') }}">
                    <span class="flex gap-0.5">
                        @foreach ($pair['spells'] as $spell)
                            @if ($spell['icon'])
                                <img src="{{ $spell['icon'] }}" alt="{{ $spell['name'] }}" loading="lazy" class="size-7 rounded bg-slate-800 ring-1 ring-slate-700">
                            @else
                                <span class="flex size-7 items-center justify-center rounded bg-slate-800 text-[9px] text-slate-400 ring-1 ring-slate-700">{{ mb_substr($spell['name'], 0, 3) }}</span>
                            @endif
                        @endforeach
                    </span>
                    <span class="flex-1 text-xs tabular-nums text-slate-500">{{ $pair['games'] }} game</span>
                    <span @class(['text-xs font-semibold tabular-nums', 'text-emerald-400' => $pair['winrate'] >= 50, 'text-rose-400' => $pair['winrate'] < 50])>{{ $pair['winrate'] }}%</span>
                </div>
            @empty
                <p class="mt-2 text-xs text-slate-400">Belum ada data spell.</p>
            @endforelse
        </section>

        {{-- Top players on this champion: more than TOP_PLAYER_MIN_GAMES games, best winrate first --}}
        <section class="rounded-xl border border-slate-800 bg-slate-900/50 p-3" aria-labelledby="who-heading">
            <div class="flex items-baseline justify-between">
                <h2 id="who-heading" class="text-sm font-semibold text-white">Top Players</h2>
                <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">WR</span>
            </div>
            @if (count($build['players']) === 0)
                <p class="mt-2 text-xs text-slate-400">Belum ada member dengan lebih dari {{ $topMinGames }} game di champion ini.</p>
            @endif
            <ul class="mt-1 divide-y divide-slate-800/70">
                @foreach ($build['players'] as $entry)
                    <li class="flex items-center gap-3 py-2 text-sm">
                        <x-profile-icon :player="$entry['player']" size="size-9" />
                        <a href="{{ route('players.show', $entry['player']) }}" class="min-w-0 flex-1 truncate font-medium text-white hover:underline">{{ $entry['player']->name }}</a>
                        <span class="text-xs tabular-nums text-slate-500">{{ $entry['games'] }} game</span>
                        <span @class(['w-12 text-right font-semibold tabular-nums', 'text-emerald-400' => $entry['winrate'] >= 50, 'text-rose-400' => $entry['winrate'] < 50])>{{ $entry['winrate'] }}%</span>
                    </li>
                @endforeach
            </ul>
        </section>
        </aside>

    {{-- Skill order: one row per ability, one column per level --}}
    @php
        $table = $build['skill_table'];
        $abilityChip = ['Q' => 'bg-amber-500/15 text-amber-300', 'W' => 'bg-sky-500/15 text-sky-300', 'E' => 'bg-emerald-500/15 text-emerald-300', 'R' => 'bg-rose-500/15 text-rose-300'];
        $abilityText = ['Q' => 'text-amber-300', 'W' => 'text-sky-300', 'E' => 'text-emerald-300', 'R' => 'text-rose-300'];
    @endphp
    <section class="order-1 min-w-0 rounded-xl border border-slate-800 bg-slate-900/50 p-4" aria-labelledby="skills-heading">
        <h2 id="skills-heading" class="flex flex-wrap items-center gap-x-3 gap-y-1 text-lg font-semibold uppercase tracking-wide text-white">
            Ability order
            @if ($table)
                <span class="flex items-center gap-1.5 text-sm font-medium normal-case tracking-normal text-slate-500">
                    Max:
                    @foreach ($table['priority'] as $key)
                        <span class="font-bold {{ $abilityText[$key] }}">{{ $key }}</span>
                        @unless ($loop->last)<span aria-hidden="true">&rarr;</span>@endunless
                    @endforeach
                </span>
            @endif
        </h2>

        @if ($table)
            <div class="mt-3 overflow-x-auto">
                <table class="w-full border-separate border-spacing-y-1 text-xs">
                    <thead>
                        <tr>
                            <th class="w-10"></th>
                            @for ($level = 1; $level <= $table['levels']; $level++)
                                <th class="min-w-7 px-0.5 text-center font-medium tabular-nums text-slate-500">{{ $level }}</th>
                            @endfor
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (['Q', 'W', 'E', 'R'] as $index => $key)
                            @php
                                $ability = $table['abilities'][$index] ?? null;
                            @endphp
                            <tr>
                                <th scope="row" class="pr-2 text-left">
                                    @if ($ability && $ability['icon'])
                                        <img src="{{ $ability['icon'] }}" alt="{{ $key }}: {{ $ability['name'] }}" title="{{ $ability['name'] }}" loading="lazy" class="size-8 rounded-md ring-1 ring-slate-700">
                                    @else
                                        <span class="flex size-8 items-center justify-center rounded-md bg-slate-800 font-bold {{ $abilityText[$key] }}">{{ $key }}</span>
                                    @endif
                                </th>
                                @for ($level = 1; $level <= $table['levels']; $level++)
                                    <td class="h-8 border-l border-slate-800/60 px-0.5 text-center">
                                        @if (in_array($level, $table['grid'][$key], true))
                                            <span class="inline-flex size-6 items-center justify-center rounded font-bold {{ $abilityChip[$key] }}">{{ $key }}</span>
                                        @endif
                                    </td>
                                @endfor
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-slate-500">
                Urutan dari game yang paling mirip pola umum, dari {{ $table['games'] }} game dengan data timeline. Skill yang di-max lebih dulu ada di baris Max.
            </p>
        @else
            <p class="mt-2 text-sm text-slate-400">Belum ada data urutan skill (timeline belum diambil).</p>
        @endif
    </section>
    </div>
    @endif
@endsection
