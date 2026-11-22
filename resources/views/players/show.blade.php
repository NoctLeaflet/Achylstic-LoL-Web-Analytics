@extends('layouts.app')

@section('title', $player->name.' · '.config('app.name'))
@section('description', $player->name.' ('.$player->riot_id.'): '.($current ? \App\Services\Stats\RankScore::label($current->tier, $current->rank, $current->league_points).' di '.$headerQueue->label().', ' : '').'statistik, grafik LP, dan match terakhir.')

@section('content')
  {{-- One Alpine scope for the page: "panel" is the open pop-up (mastery, achievements) or null. ?panel=mastery opens it right away. --}}
  <div x-data="{ panel: ['mastery', 'achievements'].includes(new URLSearchParams(location.search).get('panel')) ? new URLSearchParams(location.search).get('panel') : null }"
       x-effect="document.body.classList.toggle('overflow-hidden', panel !== null)">
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
                @if ($liveGame)
                    <x-live-badge :game="$liveGame" class="mt-1" />
                @endif
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-3">
            {{-- Menu: opens the pop-ups at the end of this page --}}
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('players.recap', $player) }}"
                   class="inline-flex items-center gap-2 rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-3 py-1.5 text-sm font-medium text-emerald-300 outline-none transition hover:bg-emerald-500/20 focus-visible:ring-2 focus-visible:ring-emerald-400">
                    <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="14" height="14" rx="2" /><path d="M7 13V9M10 13V6M13 13v-2" /></svg>
                    Rekap season
                </a>
                <button type="button" @click="panel = 'mastery'"
                        class="inline-flex items-center gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-1.5 text-sm font-medium text-amber-300 outline-none transition hover:bg-amber-500/20 focus-visible:ring-2 focus-visible:ring-amber-400">
                    <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true"><path d="M10 2l2.4 5 5.6.7-4 3.9 1 5.6L10 14.6 5 17.2l1-5.6-4-3.9 5.6-.7z" /></svg>
                    Mastery
                </button>
                <button type="button" @click="panel = 'achievements'"
                        class="inline-flex items-center gap-2 rounded-lg border border-violet-500/40 bg-violet-500/10 px-3 py-1.5 text-sm font-medium text-violet-300 outline-none transition hover:bg-violet-500/20 focus-visible:ring-2 focus-visible:ring-violet-400">
                    <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3h8v4a4 4 0 01-8 0V3zM6 5H3v1a3 3 0 003 3M14 5h3v1a3 3 0 01-3 3M10 11v3M7 17h6" /></svg>
                    Achievement
                    <span class="rounded-full bg-violet-500/20 px-1.5 text-xs tabular-nums">{{ collect($achievements)->whereNotNull('earned_at')->count() }}/{{ count($achievements) }}</span>
                </button>
            </div>

            @if ($current)
                <div class="text-right">
                    <x-rank-badge :tier="$current->tier" :rank="$current->rank" />
                    <p class="mt-1 text-sm tabular-nums text-slate-300">
                        {{ $current->league_points }} LP
                        <span class="text-slate-500">· {{ $current->wins }}W {{ $current->losses }}L</span>
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

    {{-- Rank goal set by the admin --}}
    @if ($goal)
        <section class="mt-6 rounded-xl border border-indigo-500/30 bg-indigo-500/5 p-4" aria-labelledby="goal-heading">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="goal-heading" class="text-lg font-semibold text-white">Target: {{ $goal['label'] }}</h2>
                <span class="text-sm text-slate-400">Sekarang {{ $goal['current'] }}</span>
            </div>

            <div class="mt-3 h-2.5 overflow-hidden rounded-full bg-slate-800" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $goal['percent'] }}" aria-label="Progres menuju {{ $goal['label'] }}">
                <div @class(['h-full rounded-full', 'bg-emerald-400' => $goal['reached'], 'bg-indigo-400' => ! $goal['reached']]) style="width: {{ $goal['percent'] }}%"></div>
            </div>

            <p class="mt-2 text-sm text-slate-300">
                @if ($goal['reached'])
                    <span class="font-semibold text-emerald-300">Target tercapai! 🎉</span>
                @else
                    <span class="font-semibold text-white">{{ $goal['percent'] }}%</span> menuju target, kurang
                    <span class="font-semibold text-white">{{ $goal['remaining'] }} LP</span>.
                    @if ($goal['eta_days'] !== null)
                        Dengan laju {{ $goal['per_day'] }} LP per hari, perkiraan tercapai dalam
                        <span class="font-semibold text-white">{{ $goal['eta_days'] }} hari</span>.
                    @else
                        <span class="text-slate-500">Belum ada kemajuan dalam {{ \App\Services\Stats\RankGoal::PACE_DAYS }} hari terakhir, jadi belum ada perkiraan.</span>
                    @endif
                @endif
            </p>
        </section>
    @endif

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
                    <p class="text-xs tabular-nums text-slate-400">{{ $stats['wins'] }}W {{ $stats['losses'] }}L</p>
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

    {{-- Trend: the last games against the ones before them --}}
    @if ($trend)
        <section class="mt-6" aria-labelledby="trend-heading">
            <h2 id="trend-heading" class="text-lg font-semibold text-white">Tren performa</h2>
            <p class="mb-3 text-sm text-slate-400">
                {{ $trend['recent_games'] }} game terakhir dibanding {{ $trend['before_games'] }} game sebelumnya ({{ $queueLabel }}).
            </p>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                @foreach ($trend['metrics'] as $metric)
                    @php
                        $change = $metric['change'];
                    @endphp
                    <div class="rounded-xl border border-slate-800 bg-slate-900/50 p-4">
                        <p class="text-xs uppercase tracking-wide text-slate-500">{{ $metric['label'] }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-white">{{ \App\Support\Format::number($metric['recent'], $metric['decimals']) }}</p>
                        <p class="mt-1 text-xs tabular-nums text-slate-500">
                            @if ($change === null || $change === 0)
                                <span>sama seperti sebelumnya</span>
                            @else
                                <span @class(['font-semibold', 'text-emerald-400' => $change > 0, 'text-rose-400' => $change < 0])>{{ $change > 0 ? '▲ +' : '▼ ' }}{{ $change }}%</span>
                                dari {{ \App\Support\Format::number($metric['before'], $metric['decimals']) }}
                            @endif
                        </p>
                    </div>
                @endforeach

                @php
                    $winrate = $trend['winrate'];
                @endphp
                <div class="rounded-xl border border-slate-800 bg-slate-900/50 p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Winrate</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-white">{{ $winrate['recent'] }}%</p>
                    <p class="mt-1 text-xs tabular-nums text-slate-500">
                        @if ($winrate['change'] === 0)
                            <span>sama seperti sebelumnya</span>
                        @else
                            <span @class(['font-semibold', 'text-emerald-400' => $winrate['change'] > 0, 'text-rose-400' => $winrate['change'] < 0])>{{ $winrate['change'] > 0 ? '▲ +' : '▼ ' }}{{ $winrate['change'] }} poin</span>
                            dari {{ $winrate['before'] }}%
                        @endif
                    </p>
                </div>
            </div>
        </section>
    @endif

    {{-- Top champions (left) and lanes (right) side by side; stacked on a phone --}}
    <div class="mt-6 grid items-start gap-6 lg:grid-cols-2">
    @if (count($topChampions) > 0)
        <section aria-labelledby="champions-heading">
            <h2 id="champions-heading" class="mb-3 text-lg font-semibold text-white">Champion teratas</h2>
            <ul class="divide-y divide-slate-800/70 overflow-hidden rounded-xl border border-slate-800 bg-slate-900/50">
                @foreach ($topChampions as $champion)
                    <li class="flex items-center gap-3 px-4 py-3">
                        <x-champion-icon :name="$champion['champion_name']" :id="$champion['champion_id']" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-white">{{ $champion['champion_name'] }}</p>
                            <p class="text-xs tabular-nums text-slate-400">{{ $champion['games'] }} game · {{ $champion['wins'] }}W {{ $champion['games'] - $champion['wins'] }}L</p>
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

    {{-- Lanes --}}
    @if (count($roles) > 0)
        <section aria-labelledby="roles-heading">
            <h2 id="roles-heading" class="mb-3 text-lg font-semibold text-white">Lane</h2>
            <ul class="divide-y divide-slate-800/70 overflow-hidden rounded-xl border border-slate-800 bg-slate-900/50">
                @foreach ($roles as $role)
                    <li class="px-4 py-3">
                        <div class="flex items-baseline justify-between gap-3 text-sm">
                            <span class="font-medium text-white">{{ $role['label'] }}</span>
                            <span class="text-xs tabular-nums text-slate-400">{{ $role['games'] }} game ({{ $role['share'] }}%) · {{ \App\Support\Format::number($role['kda'], 2) }} KDA</span>
                            <span @class([
                                'w-12 text-right font-semibold tabular-nums',
                                'text-emerald-400' => $role['winrate'] >= 50,
                                'text-rose-400' => $role['winrate'] < 50,
                            ])>{{ $role['winrate'] }}%</span>
                        </div>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-800" aria-hidden="true">
                            <div class="h-full rounded-full bg-indigo-400" style="width: {{ $role['share'] }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
    </div>

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
        <div class="mb-3 flex items-baseline justify-between gap-3">
            <h2 id="matches-heading" class="text-lg font-semibold text-white">Match terakhir</h2>
            <a href="{{ route('matches.index', array_filter(['player' => $player->id, 'mode' => $queue?->slug()])) }}" class="text-sm text-sky-400 hover:text-sky-300">Semua match &rarr;</a>
        </div>

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
                        'glow-mvp' => $match['badge'] === 'MVP',
                        'glow-ace' => $match['badge'] === 'ACE',
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
                            ])>{{ $match['is_remake'] ? 'Remake' : ($match['win'] ? 'Win' : 'Lose') }}</p>
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

    {{-- Pop-ups opened from the buttons in the header --}}
    <x-player-panel name="mastery" title="Champion mastery" subtitle="Level dan poin mastery dari Riot, diperbarui tiap hari.">
        @if ($masteries->isEmpty())
            <p class="rounded-xl border border-dashed border-slate-700 p-6 text-center text-sm text-slate-400">
                Belum ada data mastery. Data ini diambil dari Riot saat sinkron berikutnya.
            </p>
        @else
            <ul class="divide-y divide-slate-800/70 overflow-hidden rounded-xl border border-slate-800 bg-slate-950/40">
                @foreach ($masteries as $mastery)
                    <li class="flex items-center gap-3 px-4 py-3">
                        <x-champion-icon :name="$championNames[$mastery->champion_id] ?? ''" :id="$mastery->champion_id" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-white">{{ $championNames[$mastery->champion_id] ?? 'Champion #'.$mastery->champion_id }}</p>
                            <p class="text-xs text-slate-500">
                                {{ $mastery->last_played_at ? 'Terakhir dimainkan '.$mastery->last_played_at->diffForHumans() : 'Belum ada data waktu main' }}
                            </p>
                        </div>
                        <div class="text-right text-sm tabular-nums">
                            <p class="font-semibold text-amber-300">Level {{ $mastery->level }}</p>
                            <p class="text-xs text-slate-400">{{ number_format($mastery->points, 0, ',', '.') }} poin</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-player-panel>

    <x-player-panel name="achievements" title="Achievement"
                    :subtitle="collect($achievements)->whereNotNull('earned_at')->count().' dari '.count($achievements).' pencapaian diraih. Yang sudah diraih tampil di atas.'">
        <ul class="grid gap-3 sm:grid-cols-2">
            @foreach ($achievements as $achievement)
                <li @class([
                    'flex items-center gap-3 rounded-xl border p-3',
                    'border-amber-500/30 bg-amber-500/5' => $achievement['earned_at'],
                    'border-slate-800 bg-slate-900/30 opacity-50' => ! $achievement['earned_at'],
                ])>
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-slate-800 text-xl {{ $achievement['earned_at'] ? '' : 'grayscale' }}" aria-hidden="true">{{ $achievement['icon'] }}</span>
                    <div class="min-w-0">
                        <p class="truncate font-semibold text-white">{{ $achievement['name'] }}</p>
                        <p class="text-xs text-slate-400">{{ $achievement['description'] }}</p>
                        <p class="text-xs text-slate-500">
                            {{ $achievement['earned_at'] ? 'Diraih '.$achievement['earned_at']->translatedFormat('j M Y') : 'Belum diraih' }}
                        </p>
                    </div>
                </li>
            @endforeach
        </ul>
    </x-player-panel>
  </div>
@endsection
