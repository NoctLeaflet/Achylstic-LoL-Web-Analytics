@extends('layouts.app')

@section('title', 'Leaderboard · '.config('app.name'))

@section('content')
    @php
        $palette = app(\App\Services\Stats\PlayerPalette::class);
    @endphp

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-white">Leaderboard</h1>
            <p class="mt-1 text-sm text-slate-400">Peringkat Ranked {{ $queue->label() }}, diperbarui otomatis.</p>
        </div>

        <x-queue-toggle :queue="$queue" />
    </div>

    {{-- Time window of the LP columns, the trend line and the climber --}}
    <div class="mt-4 flex flex-wrap items-center gap-2 text-sm" aria-label="Periode">
        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Periode</span>
        <div class="inline-flex flex-wrap gap-0.5 rounded-lg border border-slate-800 bg-slate-900 p-1">
            @foreach ($periods as $option)
                <a href="{{ request()->fullUrlWithQuery(['period' => $option->value]) }}"
                   aria-current="{{ $option === $period ? 'page' : 'false' }}"
                   @class([
                       'rounded-md px-3 py-1 font-medium transition',
                       'bg-sky-500/20 text-sky-300' => $option === $period,
                       'text-slate-400 hover:text-slate-200' => $option !== $period,
                   ])>{{ $option->label() }}</a>
            @endforeach
        </div>
    </div>

    {{-- Friends who are in a game right now --}}
    @if ($liveGames->isNotEmpty())
        <div class="mt-5 flex flex-wrap items-center gap-2" aria-label="Sedang bermain">
            @foreach ($liveGames as $live)
                <a href="{{ route('players.show', $live->player) }}" class="inline-flex items-center gap-2 rounded-full border border-slate-800 bg-slate-900/60 py-1 pl-1 pr-3 text-sm text-white hover:bg-slate-800">
                    <x-profile-icon :player="$live->player" size="size-6" />
                    {{ $live->player->name }}
                    <x-live-badge :game="$live" />
                </a>
            @endforeach
        </div>
    @endif

    {{-- Biggest LP gain of the chosen period (only shown when somebody actually gained LP) --}}
    @if ($climber)
        <div class="mt-5 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4">
            <a href="{{ route('players.show', ['player' => $climber['player'], 'queue' => $queue->slug()]) }}"
               class="flex items-center gap-4 transition hover:opacity-90">
                <x-profile-icon :player="$climber['player']" size="size-12" />
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-semibold uppercase tracking-wide text-emerald-300">{{ $period->climberTitle() }}</p>
                    <p class="truncate text-lg font-semibold text-white">{{ $climber['player']->name }}</p>
                    <p class="truncate text-sm text-slate-400">{{ $climber['from'] }} &rarr; {{ $climber['to'] }}</p>
                </div>
                <p class="text-right text-2xl font-semibold tabular-nums text-emerald-300">
                    +{{ $climber['gain'] }}
                    <span class="block text-xs font-normal text-slate-400">LP / {{ $period->unit() }}</span>
                </p>
            </a>

            {{-- What the climber played to get there --}}
            @if (count($climberChampions) > 0)
                <div class="mt-3 flex flex-wrap gap-2 border-t border-emerald-500/20 pt-3">
                    @foreach ($climberChampions as $champion)
                        <span class="inline-flex items-center gap-2 rounded-lg bg-slate-950/40 py-1 pl-1 pr-3 text-xs text-slate-400">
                            <x-champion-icon :name="$champion['champion_name']" :id="$champion['champion_id']" size="size-7" />
                            <span class="font-semibold text-white">{{ $champion['champion_name'] }}</span>
                            @if ($champion['role'])
                                <span>{{ $champion['role'] }}</span>
                            @endif
                            <span class="text-slate-500">{{ $champion['games'] }} game</span>
                        </span>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    @if (count($rows) === 0)
        <div class="mt-6 rounded-xl border border-dashed border-slate-700 p-8 text-center">
            <p class="font-medium text-white">Belum ada pemain.</p>
            @auth('admin')
                <p class="mt-2 text-sm text-slate-400">Tambahkan teman lewat halaman admin.</p>
                <a href="{{ route('admin.dashboard') }}"
                   class="mt-3 inline-block rounded-md bg-sky-500 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-sky-400">Buka admin</a>
            @else
                <p class="mt-2 text-sm text-slate-400">Admin bisa menambahkan teman lewat tombol Login Admin, atau lewat terminal:</p>
                <code class="mt-3 inline-block rounded-md bg-slate-900 px-3 py-2 text-sm text-sky-300">php artisan players:add GameName TAG --name=Nama</code>
            @endauth
        </div>
    @else
        <div class="mt-5 overflow-x-auto rounded-xl border border-slate-800 bg-slate-900/50">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-800 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="w-10 whitespace-nowrap py-3 pl-4 pr-2 font-medium">#</th>
                        <th scope="col" class="w-full py-3 pr-3 font-medium">Pemain</th>
                        <th scope="col" class="whitespace-nowrap py-3 pr-4 font-medium">Rank</th>
                        <th scope="col" class="hidden whitespace-nowrap py-3 pr-4 font-medium md:table-cell">Form</th>
                        <th scope="col" class="hidden py-3 pr-4 font-medium sm:table-cell">Winrate</th>
                        <th scope="col" class="hidden py-3 pr-4 text-right font-medium sm:table-cell">Game</th>
                        <th scope="col" class="hidden whitespace-nowrap py-3 pr-4 text-right font-medium sm:table-cell">LP {{ $period->unit() }}</th>
                        <th scope="col" class="hidden py-3 pr-4 font-medium lg:table-cell">Tren</th>
                        <th scope="col" class="hidden py-3 pr-4 font-medium lg:table-cell">Target</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php
                            $player = $row['player'];
                            $snapshot = $row['snapshot'];
                            $playerForm = $form[$player->id] ?? ['results' => [], 'streak' => 0, 'streak_type' => null];
                            $points = $trend[$player->id] ?? [];
                            $goal = $goals[$player->id] ?? null;
                        @endphp
                        <tr class="border-b border-slate-800/70 last:border-0 hover:bg-slate-800/30">
                            <td class="whitespace-nowrap py-3 pl-4 pr-2 tabular-nums text-slate-500">{{ $row['position'] ?? '–' }}</td>

                            {{-- max-w-0 + w-full: take the remaining width and let long names truncate --}}
                            <td class="w-full max-w-0 py-3 pr-3">
                                <a href="{{ route('players.show', ['player' => $player, 'queue' => $queue->slug()]) }}"
                                   class="flex items-center gap-3">
                                    <x-profile-icon :player="$player" />
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium text-white">{{ $player->name }}</span>
                                        <span class="block truncate text-xs text-slate-500">{{ $player->riot_id }}</span>
                                    </span>
                                </a>
                            </td>

                            <td class="whitespace-nowrap py-3 pr-4">
                                @if ($snapshot)
                                    <x-rank-badge :tier="$snapshot->tier" :rank="$snapshot->rank" />
                                    <span class="mt-1 block text-xs tabular-nums text-slate-400">
                                        {{ $snapshot->league_points }} LP
                                        {{-- The winrate column is hidden on small screens, so show it here instead. --}}
                                        @if ($row['winrate'] !== null)
                                            <span class="sm:hidden">· {{ \App\Support\Format::number($row['winrate']) }}%</span>
                                        @endif
                                    </span>
                                @else
                                    <span class="text-slate-500">Unranked</span>
                                @endif
                            </td>

                            {{-- Form: the last results as dots (green = win, red = lose) and the current streak --}}
                            <td class="hidden whitespace-nowrap py-3 pr-4 md:table-cell">
                                @if (count($playerForm['results']) > 0)
                                    <span class="inline-flex items-center gap-2">
                                        <span class="inline-flex gap-1" role="img" aria-label="Hasil {{ count($playerForm['results']) }} game terakhir">
                                            @foreach ($playerForm['results'] as $won)
                                                <span title="{{ $won ? 'Win' : 'Lose' }}" @class(['size-2.5 rounded-full', 'bg-emerald-400' => $won, 'bg-rose-400' => ! $won])></span>
                                            @endforeach
                                        </span>
                                        @if ($playerForm['streak'] >= 2)
                                            <span title="{{ $playerForm['streak_type'] === 'W' ? 'Win streak' : 'Lose streak' }} {{ $playerForm['streak'] }}x"
                                                  @class([
                                                      'rounded px-1.5 py-0.5 text-xs font-bold tabular-nums',
                                                      'bg-emerald-500/15 text-emerald-300' => $playerForm['streak_type'] === 'W',
                                                      'bg-rose-500/15 text-rose-300' => $playerForm['streak_type'] === 'L',
                                                  ])>{{ $playerForm['streak_type'] }}{{ $playerForm['streak'] }}</span>
                                        @endif
                                    </span>
                                @else
                                    <span class="text-slate-600">–</span>
                                @endif
                            </td>

                            <td class="hidden py-3 pr-4 sm:table-cell">
                                @if ($row['winrate'] !== null)
                                    <span class="tabular-nums text-slate-200">{{ \App\Support\Format::number($row['winrate']) }}%</span>
                                    <span class="mt-1 block h-1.5 w-20 overflow-hidden rounded-full bg-slate-800" aria-hidden="true">
                                        <span class="block h-full rounded-full bg-sky-400" style="width: {{ $row['winrate'] }}%"></span>
                                    </span>
                                @else
                                    <span class="text-slate-600">–</span>
                                @endif
                            </td>

                            <td class="hidden whitespace-nowrap py-3 pr-4 text-right tabular-nums text-slate-400 sm:table-cell">
                                @if ($snapshot)
                                    <span class="text-slate-200">{{ $row['games'] }}</span>
                                    <span class="text-xs text-slate-500">({{ $snapshot->wins }}W {{ $snapshot->losses }}L)</span>
                                @else
                                    <span class="text-slate-600">–</span>
                                @endif
                            </td>

                            <td class="hidden whitespace-nowrap py-3 pr-4 text-right tabular-nums sm:table-cell">
                                @php
                                    $gain = $weeklyGains[$player->id] ?? null;
                                @endphp
                                @if ($gain === null)
                                    <span class="text-slate-600">–</span>
                                @elseif ($gain > 0)
                                    <span class="font-medium text-emerald-400">+{{ $gain }} LP</span>
                                @elseif ($gain < 0)
                                    <span class="font-medium text-rose-400">{{ $gain }} LP</span>
                                @else
                                    <span class="text-slate-500">0 LP</span>
                                @endif
                            </td>

                            {{-- Trend: the LP line of the period, drawn as a small SVG --}}
                            <td class="hidden py-3 pr-4 lg:table-cell">
                                @if (count($points) >= 2)
                                    @php
                                        $min = min($points);
                                        $span = max(max($points) - $min, 1);
                                        $last = count($points) - 1;
                                        $line = collect($points)->map(fn ($y, $i) => round($i / $last * 76 + 2, 1).','.round(22 - ($y - $min) / $span * 20, 1))->implode(' ');
                                    @endphp
                                    <svg viewBox="0 0 80 24" class="h-6 w-20" role="img" aria-label="Tren LP {{ $period->label() }}">
                                        <polyline points="{{ $line }}" fill="none" stroke="{{ $palette->colorFor($player->id) }}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                @else
                                    <span class="text-slate-600">–</span>
                                @endif
                            </td>

                            {{-- Target: progress towards the rank goal set by the admin --}}
                            <td class="hidden whitespace-nowrap py-3 pr-4 lg:table-cell">
                                @if ($goal)
                                    <span class="text-xs text-slate-300">{{ $goal['label'] }} <span class="tabular-nums text-slate-500">{{ $goal['percent'] }}%</span></span>
                                    <span class="mt-1 block h-1.5 w-24 overflow-hidden rounded-full bg-slate-800" aria-hidden="true">
                                        <span class="block h-full rounded-full bg-sky-400" style="width: {{ $goal['percent'] }}%"></span>
                                    </span>
                                @else
                                    <span class="text-slate-600">–</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @include('partials.weekly')
        @include('partials.group-overview')
        @include('partials.server-plays')
    @endif
@endsection
