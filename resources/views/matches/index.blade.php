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
                                <div class="flex items-baseline justify-between text-sm">
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
                                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-800" aria-hidden="true">
                                    <div class="h-full rounded-full bg-indigo-400" style="width: {{ $role['share'] }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
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
                        <span class="text-xs text-slate-500">{{ $wins }}M {{ $games->count() - $wins }}K</span>
                    </div>
                    <div class="space-y-2">
                        @foreach ($games as $game)
                            <x-game-row :game="$game" :box="$game['win'] ? 'border-sky-500/25 bg-sky-500/5' : 'border-rose-500/25 bg-rose-500/5'" />
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
