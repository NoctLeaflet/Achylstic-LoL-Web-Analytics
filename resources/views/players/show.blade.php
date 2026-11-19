@extends('layouts.app')

@section('title', $player->name.' · '.config('app.name'))

@section('content')
    <x-back-link :fallback="route('leaderboard', ['queue' => $headerQueue->slug()])" />

    {{-- Header --}}
    <div class="mt-3 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-800 bg-slate-900/50 p-4 sm:p-5">
        <div class="flex min-w-0 items-center gap-4">
            <x-profile-icon :player="$player" size="size-16" />
            <div class="min-w-0">
                <h1 class="truncate text-2xl font-semibold tracking-tight text-white">{{ $player->name }}</h1>
                <p class="truncate text-sm text-slate-400">
                    {{ $player->riot_id }}
                    @if ($player->summoner_level)
                        <span class="text-slate-600">·</span> Level {{ $player->summoner_level }}
                    @endif
                    <span class="text-slate-600">·</span> {{ strtoupper($player->platform) }}
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            @if ($current)
                <div class="text-right">
                    <x-rank-badge :tier="$current->tier" :rank="$current->rank" />
                    <p class="mt-1 text-sm tabular-nums text-slate-300">
                        {{ $current->league_points }} LP
                        <span class="text-slate-500">· {{ $current->wins }}M {{ $current->losses }}K</span>
                    </p>
                    {{-- Only Solo/Duo and Flex have a rank; other game modes show the Solo/Duo one. --}}
                    <p class="text-xs text-slate-500">{{ $headerQueue->label() }}</p>
                </div>
            @else
                <span class="text-sm text-slate-500">Unranked di {{ $headerQueue->label() }}</span>
            @endif
        </div>

        <div class="w-full">
            <x-queue-toggle :queue="$queue" :options="$queueOptions" all />
        </div>
    </div>

    {{-- LP over time (only Solo/Duo and Flex have LP) --}}
    <section class="mt-6" aria-labelledby="lp-heading">
        <h2 id="lp-heading" class="mb-3 text-lg font-semibold text-white">LP dari waktu ke waktu</h2>
        <div class="rounded-xl border border-slate-800 bg-slate-900/50 p-4">
            @if ($lpChart === null)
                <p class="py-8 text-center text-sm text-slate-500">
                    Grafik LP hanya ada untuk Solo/Duo dan Flex. Pilih salah satunya di atas untuk melihatnya.
                </p>
            @elseif (count($lpChart['datasets'][0]['points']) > 0)
                <div x-data="lpChart(@js($lpChart))" class="relative h-64 sm:h-80">
                    <canvas x-ref="canvas" role="img" aria-label="Grafik LP {{ $player->name }}"></canvas>
                </div>
            @else
                <p class="py-10 text-center text-sm text-slate-500">
                    Belum ada snapshot LP untuk {{ $rankQueue->label() }}. Jalankan <code class="text-sky-300">php artisan riot:sync-ranks</code>.
                </p>
            @endif
        </div>
    </section>

    {{-- Averages --}}
    <section class="mt-6" aria-labelledby="stats-heading">
        <h2 id="stats-heading" class="mb-3 text-lg font-semibold text-white">Statistik rata-rata</h2>

        @if ($stats['games'] === 0)
            <p class="rounded-xl border border-dashed border-slate-700 p-6 text-center text-sm text-slate-500">
                Belum ada match{{ $queue ? ' '.$queue->label() : '' }} yang tersimpan. Jalankan <code class="text-sky-300">php artisan riot:sync-matches</code>.
            </p>
        @else
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-xl border border-slate-800 bg-slate-900/50 p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Game</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-white">{{ $stats['games'] }}</p>
                    <p class="text-xs tabular-nums text-slate-400">{{ $stats['wins'] }}M {{ $stats['losses'] }}K</p>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-900/50 p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Winrate</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-white">{{ \App\Support\Format::number($stats['winrate']) }}%</p>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-900/50 p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500">KDA</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-white">{{ \App\Support\Format::number($stats['kda'], 2) }}</p>
                    <p class="text-xs tabular-nums text-slate-400">{{ \App\Support\Format::number($stats['avg_kills']) }} / {{ \App\Support\Format::number($stats['avg_deaths']) }} / {{ \App\Support\Format::number($stats['avg_assists']) }}</p>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-900/50 p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500">CS / menit</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-white">{{ \App\Support\Format::number($stats['cs_per_min'], 2) }}</p>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-900/50 p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Damage / menit</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-white">{{ \App\Support\Format::number($stats['damage_per_min'], 0) }}</p>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-900/50 p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Gold / menit</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-white">{{ \App\Support\Format::number($stats['gold_per_min'], 0) }}</p>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-900/50 p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Vision / menit</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-white">{{ \App\Support\Format::number($stats['vision_per_min'], 2) }}</p>
                </div>
            </div>
            <p class="mt-2 text-xs text-slate-500">Match remake (di bawah 5 menit) tidak dihitung.</p>
        @endif
    </section>

    {{-- Top champions --}}
    @if (count($topChampions) > 0)
        <section class="mt-6" aria-labelledby="champions-heading">
            <h2 id="champions-heading" class="mb-3 text-lg font-semibold text-white">Champion teratas</h2>
            <ul class="divide-y divide-slate-800/70 overflow-hidden rounded-xl border border-slate-800 bg-slate-900/50">
                @foreach ($topChampions as $champion)
                    <li class="flex items-center gap-3 px-4 py-3">
                        <x-champion-icon :name="$champion['champion_name']" :id="$champion['champion_id']" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-white">{{ $champion['champion_name'] }}</p>
                            <p class="text-xs tabular-nums text-slate-400">{{ $champion['games'] }} game · {{ $champion['wins'] }}M {{ $champion['games'] - $champion['wins'] }}K</p>
                        </div>
                        <div class="text-right text-sm tabular-nums">
                            <p class="text-slate-200">{{ \App\Support\Format::number($champion['winrate']) }}%</p>
                            <p class="text-xs text-slate-500">{{ \App\Support\Format::number($champion['kda'], 2) }} KDA</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Records against / with the other tracked players --}}
    @if (count($partners) > 0)
        <section class="mt-6" aria-labelledby="partners-heading">
            <h2 id="partners-heading" class="mb-1 text-lg font-semibold text-white">Main bareng teman</h2>
            <p class="mb-3 text-xs text-slate-500">Rekor {{ $player->name }} saat satu tim dan saat berhadapan.</p>

            <div class="overflow-hidden rounded-xl border border-slate-800 bg-slate-900/50">
                <div class="hidden grid-cols-3 gap-4 border-b border-slate-800 px-4 py-2.5 text-xs uppercase tracking-wide text-slate-500 sm:grid">
                    <span>Teman</span>
                    <span>Satu tim</span>
                    <span>Lawan</span>
                </div>

                <ul class="divide-y divide-slate-800/70">
                    @foreach ($partners as $partner)
                        @php
                            $friend = $partnerPlayers[$partner['player_id']];
                        @endphp
                        <li class="grid gap-2 px-4 py-3 sm:grid-cols-3 sm:items-center sm:gap-4">
                            <a href="{{ route('players.show', ['player' => $friend, 'queue' => $queueParam]) }}" class="flex items-center gap-3 font-medium text-white hover:text-sky-300">
                                <x-profile-icon :player="$friend" size="size-8" />
                                {{ $friend->name }}
                            </a>

                            <div>
                                <p class="text-xs uppercase tracking-wide text-slate-500 sm:hidden">Satu tim</p>
                                <x-pair-record :record="$partner['together']" />
                            </div>

                            <div>
                                <p class="text-xs uppercase tracking-wide text-slate-500 sm:hidden">Lawan</p>
                                <x-pair-record :record="$partner['versus']" />
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    {{-- Recent matches --}}
    <section class="mt-6" aria-labelledby="matches-heading">
        <h2 id="matches-heading" class="mb-3 text-lg font-semibold text-white">Match terakhir</h2>

        @if (count($recentMatches) === 0)
            <p class="rounded-xl border border-dashed border-slate-700 p-6 text-center text-sm text-slate-500">Belum ada match.</p>
        @else
            <ul class="space-y-2">
                @foreach ($recentMatches as $match)
                    <li @class([
                        'relative flex items-center gap-3 rounded-xl border border-l-4 border-slate-800 bg-slate-900/50 px-3 py-2.5 transition hover:bg-slate-800/50 sm:gap-4 sm:px-4',
                        'border-l-slate-600' => $match['is_remake'],
                        'border-l-emerald-500' => ! $match['is_remake'] && $match['win'],
                        'border-l-rose-500' => ! $match['is_remake'] && ! $match['win'],
                    ])>
                        {{-- Stretched link: the whole row opens the match detail. --}}
                        <a href="{{ route('matches.show', $match['match_id']) }}" class="absolute inset-0 rounded-xl" aria-label="Detail match {{ $match['champion_name'] }}, {{ $match['kills'] }}/{{ $match['deaths'] }}/{{ $match['assists'] }}"></a>
                        <x-champion-icon :name="$match['champion_name']" :id="$match['champion_id']" />

                        <div class="w-20 shrink-0 text-xs sm:w-28">
                            <p @class([
                                'text-sm font-semibold',
                                'text-slate-400' => $match['is_remake'],
                                'text-emerald-400' => ! $match['is_remake'] && $match['win'],
                                'text-rose-400' => ! $match['is_remake'] && ! $match['win'],
                            ])>{{ $match['is_remake'] ? 'Remake' : ($match['win'] ? 'Menang' : 'Kalah') }}</p>
                            <p class="text-slate-500">{{ $match['queue'] }}</p>
                            <p class="text-slate-500">{{ $match['started_at']->diffForHumans() }}</p>
                            @if ($match['score'] !== null && ! $match['is_remake'])
                                <p class="mt-0.5 flex flex-wrap items-center gap-1 text-slate-400">
                                    Skor <span class="font-semibold tabular-nums text-slate-200">{{ $match['score'] }}</span>
                                    @if ($match['badge'])
                                        <x-score-badge :badge="$match['badge']" />
                                    @endif
                                </p>
                            @endif
                        </div>

                        <div class="min-w-0 flex-1 tabular-nums">
                            <p class="font-medium text-white">{{ $match['kills'] }} / {{ $match['deaths'] }} / {{ $match['assists'] }}</p>
                            <p class="text-xs text-slate-400">{{ \App\Support\Format::number($match['kda'], 2) }} KDA</p>
                        </div>

                        <div class="hidden text-right text-xs tabular-nums text-slate-400 sm:block">
                            <p>{{ $match['cs'] }} CS ({{ \App\Support\Format::number($match['cs_per_min']) }}/mnt)</p>
                            <p>{{ number_format($match['damage'], 0, '.', ',') }} damage</p>
                        </div>

                        <div class="text-right text-xs tabular-nums text-slate-500">
                            {{ \App\Support\Format::duration($match['duration']) }}
                        </div>

                        {{-- Opens the match with the radar pop-up on this player's lane (above the stretched row link). --}}
                        @if ($match['position'] && ! $match['is_remake'])
                            <a href="{{ route('matches.show', ['gameMatch' => $match['match_id'], 'vs' => $match['position']]) }}"
                               title="Compare dengan lawan lane"
                               aria-label="Compare lane {{ $match['champion_name'] }} di match ini"
                               class="relative z-10 inline-flex shrink-0 items-center gap-1 rounded-md border border-slate-700 px-1.5 py-1 text-xs font-medium text-slate-300 transition hover:border-sky-500 hover:text-sky-300">
                                <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true"><path d="M10 2l7 4v8l-7 4-7-4V6z" /><path d="M10 6l3.5 2v4L10 14l-3.5-2V8z" /></svg>
                                <span class="hidden sm:inline">Compare</span>
                            </a>
                        @endif

                        <div class="hidden gap-0.5 lg:flex" aria-hidden="true">
                            @foreach ($match['items'] as $itemId)
                                <x-item-icon :id="$itemId" />
                            @endforeach
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <div class="mt-8 text-center">
        <a href="{{ route('compare', ['players' => $player->id, 'queue' => $queueParam]) }}"
           class="inline-block rounded-lg border border-slate-700 px-4 py-2 text-sm font-medium text-slate-300 hover:border-sky-500 hover:text-sky-300">
            Bandingkan dengan pemain lain
        </a>
    </div>
@endsection
