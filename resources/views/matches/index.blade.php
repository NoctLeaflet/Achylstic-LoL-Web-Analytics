@extends('layouts.app')

@section('title', 'Matches · '.config('app.name'))

@section('content')
    @php
        $select = 'rounded-md border border-slate-700 bg-slate-950 px-3 py-1.5 text-sm text-white focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500';
    @endphp

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-white">Matches</h1>
            <p class="mt-1 text-sm text-slate-400">
                {{ number_format($total, 0, ',', '.') }} game {{ $player ? 'milik '.$player->name : 'dari semua pemain' }}{{ $mode ? ' di '.$mode->label() : '' }}, terbaru di atas.
            </p>
        </div>

        <form method="GET" action="{{ route('matches.index') }}" class="flex flex-wrap gap-2">
            <label for="filter-player" class="sr-only">Pemain</label>
            <select id="filter-player" name="player" onchange="this.form.submit()" class="{{ $select }}">
                <option value="">Semua pemain</option>
                @foreach ($players as $p)
                    <option value="{{ $p->id }}" @selected($player?->id === $p->id)>{{ $p->name }}</option>
                @endforeach
            </select>

            <label for="filter-mode" class="sr-only">Mode game</label>
            <select id="filter-mode" name="mode" onchange="this.form.submit()" class="{{ $select }}">
                <option value="">Semua mode</option>
                @foreach ($modes as $m)
                    <option value="{{ $m->slug() }}" @selected($mode === $m)>{{ $m->label() }}</option>
                @endforeach
            </select>
            <noscript><button type="submit" class="rounded-md border border-slate-700 px-3 py-1.5 text-sm text-slate-200">Terapkan</button></noscript>
        </form>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-[17rem_1fr]">
        {{-- Most played champions and roles of the current selection --}}
        <aside class="space-y-4 lg:order-first">
            <section class="rounded-xl border border-slate-800 bg-slate-900/50 p-4" aria-labelledby="most-played">
                <h2 id="most-played" class="text-xs font-bold uppercase tracking-wide text-slate-500">Most played</h2>

                @forelse ($summary['champions'] as $champion)
                    <div class="mt-3 flex items-center gap-3">
                        <x-champion-icon :name="$champion['champion_name']" :id="$champion['champion_id']" size="size-10" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold text-white">{{ $champion['champion_name'] }}</p>
                            <p class="text-xs text-slate-500">{{ $champion['games'] }} game · {{ \App\Support\Format::number($champion['kda'], 1) }} KDA</p>
                            @unless ($player)
                                <p class="truncate text-xs text-slate-500">{{ implode(', ', $champion['players']) }}</p>
                            @endunless
                        </div>
                        <span @class([
                            'text-sm font-semibold tabular-nums',
                            'text-emerald-400' => $champion['winrate'] >= 50,
                            'text-rose-400' => $champion['winrate'] < 50,
                        ])>{{ $champion['winrate'] }}%</span>
                    </div>
                @empty
                    <p class="mt-3 text-sm text-slate-500">Belum ada data.</p>
                @endforelse
            </section>

            <section class="rounded-xl border border-slate-800 bg-slate-900/50 p-4" aria-labelledby="roles">
                <h2 id="roles" class="text-xs font-bold uppercase tracking-wide text-slate-500">Roles</h2>

                @if (collect($summary['roles'])->sum('games') === 0)
                    <p class="mt-3 text-sm text-slate-500">Belum ada data lane.</p>
                @else
                    <div class="mt-3 space-y-3">
                        @foreach ($summary['roles'] as $role)
                            <div>
                                <div class="flex items-baseline justify-between gap-2 text-sm">
                                    <span class="font-medium text-slate-200">{{ $role['label'] }}</span>
                                    <span class="text-xs text-slate-500">{{ $role['games'] }} ({{ $role['share'] }}%)</span>
                                    @if ($role['winrate'] !== null)
                                        <span @class([
                                            'w-10 text-right text-xs font-semibold tabular-nums',
                                            'text-emerald-400' => $role['winrate'] >= 50,
                                            'text-rose-400' => $role['winrate'] < 50,
                                        ])>{{ $role['winrate'] }}%</span>
                                    @else
                                        <span class="w-10 text-right text-xs text-slate-600">–</span>
                                    @endif
                                </div>
                                {{-- Green = wins, red = losses --}}
                                <div class="mt-1 flex h-1.5 overflow-hidden rounded-full bg-slate-800" aria-hidden="true">
                                    <div class="h-full bg-emerald-400" style="width: {{ $role['games'] > 0 ? $role['wins'] / $role['games'] * 100 : 0 }}%"></div>
                                    <div class="h-full bg-rose-400" style="width: {{ $role['games'] > 0 ? $role['losses'] / $role['games'] * 100 : 0 }}%"></div>
                                </div>
                                <p class="mt-0.5 text-xs tabular-nums">
                                    <span class="font-semibold text-emerald-400">{{ $role['wins'] }}W</span>
                                    <span class="font-semibold text-rose-400">{{ $role['losses'] }}L</span>
                                </p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            {{-- Players on a win or lose streak right now --}}
            <section class="rounded-xl border border-slate-800 bg-slate-900/50 p-4" aria-labelledby="streaks">
                <h2 id="streaks" class="text-xs font-bold uppercase tracking-wide text-slate-500">Streak sekarang</h2>
                <p class="mt-1 text-xs text-slate-500">Dari game terakhir, {{ $mode ? $mode->label() : 'semua mode' }}. Minimal {{ \App\Services\Stats\CurrentStreaks::MIN_STREAK }} game.</p>

                @foreach (['wins' => ['Win streak', 'text-emerald-400'], 'losses' => ['Lose streak', 'text-rose-400']] as $key => [$title, $color])
                    @if (count($streaks[$key]) > 0)
                        <p class="mt-3 text-xs font-bold {{ $color }}">{{ $title }}</p>
                        @foreach ($streaks[$key] as $streak)
                            <a href="{{ route('players.show', $streak['player']) }}" class="mt-2 flex items-center gap-2.5 hover:opacity-80">
                                <x-profile-icon :player="$streak['player']" size="size-8" />
                                <span class="min-w-0 flex-1 truncate text-sm font-medium text-white">{{ $streak['player']->name }}</span>
                                <span class="text-xs font-semibold tabular-nums {{ $color }}">{{ $streak['games'] }} game</span>
                            </a>
                        @endforeach
                    @endif
                @endforeach

                @if (count($streaks['wins']) + count($streaks['losses']) === 0)
                    <p class="mt-3 text-sm text-slate-500">Tidak ada yang sedang streak.</p>
                @endif
            </section>
        </aside>

        {{-- The games, grouped by day --}}
        <div class="min-w-0">
            @forelse ($days as $day => $games)
                @php
                    $wins = $games->where('win', true)->count();
                @endphp
                <section class="mb-6" aria-label="{{ \Illuminate\Support\Carbon::parse($day)->translatedFormat('j F Y') }}">
                    <div class="mb-2 flex items-baseline justify-between">
                        <h2 class="text-sm font-semibold text-slate-300">{{ \Illuminate\Support\Carbon::parse($day)->translatedFormat('j F Y') }}</h2>
                        <span class="text-xs text-slate-500">{{ $wins }}W {{ $games->count() - $wins }}L</span>
                    </div>
                    {{-- Friends who played the same match are shown together in one framed group, with a wider gap to the next match --}}
                    <div class="space-y-4">
                        @foreach ($games->groupBy('match_id') as $group)
                            @if ($group->count() === 1)
                                @php $game = $group->first(); @endphp
                                <x-game-row :game="$game" :box="$game['win'] ? 'border-sky-500/25 bg-sky-500/5' : 'border-rose-500/25 bg-rose-500/5'" />
                            @else
                                @php
                                    $first = $group->first();
                                    $allWon = $group->every(fn ($g) => $g['win']);
                                    $allLost = $group->every(fn ($g) => ! $g['win']);
                                @endphp
                                <div @class([
                                    'rounded-2xl border-l-4 border-y border-r bg-slate-900/40 p-2 sm:p-2.5',
                                    'border-sky-400/60 border-y-slate-700/60 border-r-slate-700/60' => $allWon,
                                    'border-rose-400/60 border-y-slate-700/60 border-r-slate-700/60' => $allLost,
                                    'border-violet-400/60 border-y-slate-700/60 border-r-slate-700/60' => ! $allWon && ! $allLost,
                                ]) role="group" aria-label="{{ $group->count() }} teman main bareng di match yang sama">
                                    <p class="mb-2 flex flex-wrap items-center gap-x-2 gap-y-1 px-1 text-xs text-slate-400">
                                        <span class="font-semibold text-slate-200">{{ $group->count() }} teman main bareng</span>
                                        <span class="text-slate-600">&middot;</span>
                                        <span>{{ $first['queue'] }}</span>
                                        <span class="text-slate-600">&middot;</span>
                                        <span @class(['font-semibold', 'text-sky-300' => $allWon, 'text-rose-300' => $allLost, 'text-violet-300' => ! $allWon && ! $allLost])>
                                            {{ $allWon ? 'Win' : ($allLost ? 'Lose' : 'Teman melawan teman') }}
                                        </span>
                                    </p>
                                    <div class="space-y-1.5">
                                        @foreach ($group as $game)
                                            <x-game-row :game="$game" :box="$game['win'] ? 'border-sky-500/25 bg-sky-500/5' : 'border-rose-500/25 bg-rose-500/5'" />
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </section>
            @empty
                <p class="rounded-xl border border-dashed border-slate-700 p-8 text-center text-sm text-slate-400">
                    Belum ada match untuk pilihan ini.
                </p>
            @endforelse

            @if ($paginator->hasPages())
                <nav class="mt-4 flex items-center justify-between text-sm" aria-label="Halaman">
                    @if ($paginator->onFirstPage())
                        <span class="rounded-md border border-slate-800 px-3 py-1.5 text-slate-600">&larr; Lebih baru</span>
                    @else
                        <a href="{{ $paginator->previousPageUrl() }}" class="rounded-md border border-slate-700 px-3 py-1.5 text-slate-200 hover:bg-slate-800">&larr; Lebih baru</a>
                    @endif
                    <span class="text-slate-500">Halaman {{ $paginator->currentPage() }} dari {{ $paginator->lastPage() }}</span>
                    @if ($paginator->hasMorePages())
                        <a href="{{ $paginator->nextPageUrl() }}" class="rounded-md border border-slate-700 px-3 py-1.5 text-slate-200 hover:bg-slate-800">Lebih lama &rarr;</a>
                    @else
                        <span class="rounded-md border border-slate-800 px-3 py-1.5 text-slate-600">Lebih lama &rarr;</span>
                    @endif
                </nav>
            @endif
        </div>
    </div>
@endsection
