@extends('layouts.app')

@section('title', 'Match '.$match['match_id'].' · '.config('app.name'))

@section('content')
    <x-back-link :fallback="route('leaderboard')" />

    {{-- Header --}}
    <div class="mt-3 rounded-xl border border-slate-800 bg-slate-900/50 p-4 sm:p-5">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <h1 class="text-2xl font-semibold tracking-tight text-white">{{ $match['queue'] }}</h1>
            @if ($match['is_remake'])
                <span class="rounded-md bg-slate-500/20 px-2 py-0.5 text-xs font-semibold text-slate-300 ring-1 ring-inset ring-slate-500/40">Remake</span>
            @endif
        </div>
        <p class="mt-1 text-sm text-slate-400">
            {{ \App\Support\Format::duration($match['duration']) }}
            <span class="text-slate-600">·</span>
            <time datetime="{{ $match['started_at']->toIso8601String() }}" title="{{ $match['started_at']->toDayDateTimeString() }} UTC">{{ $match['started_at']->diffForHumans() }}</time>
            @if ($match['version'])
                <span class="text-slate-600">·</span> Patch {{ implode('.', array_slice(explode('.', $match['version']), 0, 2)) }}
            @endif
        </p>
        @if ($mvp)
            <p class="mt-2 flex flex-wrap items-center gap-2 text-sm text-slate-300">
                <x-score-badge badge="MVP" />
                <span class="font-medium text-white">{{ $mvp['name'] }}</span>
                <span class="text-slate-500">{{ $mvp['champion_name'] }} · skor {{ $mvp['score'] }}</span>
            </p>
        @endif
        <p class="mt-1 text-xs text-slate-600">{{ $match['match_id'] }}</p>
    </div>

    @php
        // Lanes that can be compared (empty for ARAM and remakes).
        $matchupPositions = array_column($matchups, 'position');
    @endphp

    {{--
        One Alpine scope for the whole page:
          open     = the participant whose build panel is shown
          matchup  = the lane shown in the radar pop-up (null = closed); ?vs=TOP in the URL opens it right away
    --}}
    <div x-data="{
            open: null,
            matchup: null,
            returnFocus: null,
            toggle(id) { this.open = this.open === id ? null : id },
            openMatchup(position, event) {
                this.returnFocus = event ? event.currentTarget : document.activeElement;
                this.matchup = position;
                this.$nextTick(() => this.$refs.matchupClose && this.$refs.matchupClose.focus());
            },
            closeMatchup() {
                this.matchup = null;
                this.$nextTick(() => this.returnFocus && this.returnFocus.focus());
            },
            init() {
                const lane = new URLSearchParams(window.location.search).get('vs');
                if (lane && @js($matchupPositions).includes(lane)) { this.matchup = lane; }
            },
         }"
         x-effect="document.body.classList.toggle('overflow-hidden', matchup !== null)">
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p class="max-w-3xl text-xs text-slate-500">
                Klik seorang pemain untuk melihat item, urutan skill, dan rune-nya. Skor 0-100 adalah penilaian kami dari KDA, partisipasi kill, damage, vision, gold, dan CS dibanding 9 pemain lain di match ini (bukan skor resmi Riot).
            </p>

            @if ($matchups !== [])
                <button type="button" @click="openMatchup('{{ $matchups[0]['position'] }}', $event)"
                        class="inline-flex shrink-0 items-center gap-2 rounded-lg border border-sky-500/40 bg-sky-500/10 px-3 py-1.5 text-sm font-medium text-sky-300 outline-none transition hover:bg-sky-500/20 focus-visible:ring-2 focus-visible:ring-sky-400">
                    <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true"><path d="M10 2l7 4v8l-7 4-7-4V6z" /><path d="M10 6l3.5 2v4L10 14l-3.5-2V8z" /></svg>
                    Compare lane
                </button>
            @endif
        </div>

        @foreach ($teams as $team)
            <section class="mt-6" aria-labelledby="team-{{ $team['team_id'] }}">
                <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="team-{{ $team['team_id'] }}" class="flex items-center gap-2 text-lg font-semibold text-white">
                        {{ $team['name'] }}
                        <span @class([
                            'rounded-md px-2 py-0.5 text-xs font-semibold ring-1 ring-inset',
                            'bg-slate-500/20 text-slate-300 ring-slate-500/40' => $match['is_remake'],
                            'bg-emerald-500/15 text-emerald-300 ring-emerald-500/30' => ! $match['is_remake'] && $team['win'],
                            'bg-rose-500/15 text-rose-300 ring-rose-500/30' => ! $match['is_remake'] && ! $team['win'],
                        ])>{{ $match['is_remake'] ? 'Remake' : ($team['win'] ? 'Menang' : 'Kalah') }}</span>
                    </h2>
                    <p class="text-sm tabular-nums text-slate-400">
                        {{ $team['kills'] }} / {{ $team['deaths'] }} / {{ $team['assists'] }}
                        <span class="text-slate-600">·</span> {{ number_format($team['gold']) }} gold
                        <span class="hidden sm:inline"><span class="text-slate-600">·</span> {{ number_format($team['damage']) }} damage</span>
                    </p>
                </div>

                <div class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-900/50">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-slate-800 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="py-2.5 pl-4 pr-3 font-medium">Pemain</th>
                                <th scope="col" class="whitespace-nowrap px-2 py-2.5 text-center font-medium">Skor</th>
                                <th scope="col" class="whitespace-nowrap px-2 py-2.5 font-medium">K / D / A</th>
                                <th scope="col" class="hidden whitespace-nowrap px-2 py-2.5 text-right font-medium sm:table-cell">CS</th>
                                <th scope="col" class="whitespace-nowrap px-2 py-2.5 font-medium">Damage</th>
                                <th scope="col" class="hidden whitespace-nowrap px-2 py-2.5 text-right font-medium md:table-cell">Gold</th>
                                <th scope="col" class="hidden whitespace-nowrap px-2 py-2.5 text-right font-medium md:table-cell">Vision</th>
                                <th scope="col" class="hidden px-4 py-2.5 font-medium lg:table-cell">Item</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($team['participants'] as $participant)
                                <tr @click="toggle({{ $participant['id'] }})"
                                    :class="open === {{ $participant['id'] }} ? 'bg-slate-800/70' : ''"
                                    @class([
                                        'cursor-pointer border-b border-slate-800/70 transition last:border-0 hover:bg-slate-800/50',
                                        'bg-sky-500/10' => $participant['player'] !== null,
                                    ])>
                                    <td class="py-2.5 pl-4 pr-3">
                                        <div class="flex items-center gap-3">
                                            <button type="button" @click.stop="toggle({{ $participant['id'] }})"
                                                    :aria-expanded="open === {{ $participant['id'] }}"
                                                    aria-controls="build-{{ $participant['id'] }}"
                                                    aria-label="Lihat build {{ $participant['name'] }} ({{ $participant['champion_name'] }})"
                                                    class="rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-sky-400">
                                                <x-champion-icon :name="$participant['champion_name']" :id="$participant['champion_id']" size="size-9" />
                                            </button>
                                            <div class="min-w-0 flex-1">
                                                @if ($participant['player'])
                                                    <a href="{{ route('players.show', $participant['player']) }}" @click.stop class="block truncate font-medium text-sky-300 hover:text-sky-200">{{ $participant['name'] }}</a>
                                                @else
                                                    <span class="block truncate font-medium text-slate-200">{{ $participant['name'] }}</span>
                                                @endif
                                                <span class="block truncate text-xs text-slate-500">
                                                    {{ $participant['champion_name'] }}
                                                    @if ($participant['position'])
                                                        <span class="text-slate-600">·</span> {{ ucfirst(strtolower($participant['position'])) }}
                                                    @endif
                                                </span>
                                            </div>

                                            {{-- Opens the radar pop-up on this player's lane (only when there is an opponent in the same role). --}}
                                            @if ($participant['position'] && in_array($participant['position'], $matchupPositions, true))
                                                <button type="button" @click.stop="openMatchup('{{ $participant['position'] }}', $event)"
                                                        aria-label="Bandingkan {{ $participant['name'] }} dengan lawan lane ({{ \App\Services\Stats\LaneMatchups::LANES[$participant['position']] }})"
                                                        title="Compare dengan lawan lane"
                                                        class="inline-flex shrink-0 items-center gap-1 rounded-md border border-slate-700 px-1.5 py-1 text-xs font-medium text-slate-300 outline-none transition hover:border-sky-500 hover:text-sky-300 focus-visible:ring-2 focus-visible:ring-sky-400">
                                                    <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true"><path d="M10 2l7 4v8l-7 4-7-4V6z" /><path d="M10 6l3.5 2v4L10 14l-3.5-2V8z" /></svg>
                                                    <span class="hidden lg:inline">Compare</span>
                                                </button>
                                            @endif
                                        </div>
                                    </td>

                                    <td class="whitespace-nowrap px-2 py-2.5 text-center">
                                        <span @class([
                                            'text-base font-semibold tabular-nums',
                                            'text-emerald-300' => $participant['score'] >= 70,
                                            'text-slate-100' => $participant['score'] >= 45 && $participant['score'] < 70,
                                            'text-rose-300' => $participant['score'] < 45,
                                        ])>{{ $participant['score'] }}</span>
                                        @if ($participant['badge'])
                                            <span class="mt-0.5 block"><x-score-badge :badge="$participant['badge']" /></span>
                                        @endif
                                    </td>

                                    <td class="whitespace-nowrap px-2 py-2.5 tabular-nums">
                                        <span class="font-medium text-white">{{ $participant['kills'] }} / {{ $participant['deaths'] }} / {{ $participant['assists'] }}</span>
                                        {{-- The CS column is hidden on small screens, so CS is shown here instead. --}}
                                        <span class="block text-xs text-slate-500">{{ \App\Support\Format::number($participant['kda'], 2) }} KDA<span class="sm:hidden"> · {{ $participant['cs'] }} CS</span></span>
                                    </td>

                                    <td class="hidden whitespace-nowrap px-2 py-2.5 text-right tabular-nums text-slate-300 sm:table-cell">
                                        {{ $participant['cs'] }}
                                        <span class="block text-xs text-slate-500">{{ \App\Support\Format::number($participant['cs_per_min']) }}/mnt</span>
                                    </td>

                                    <td class="whitespace-nowrap px-2 py-2.5 tabular-nums text-slate-300">
                                        {{ number_format($participant['damage']) }}
                                        <span class="mt-1 block h-1.5 w-20 overflow-hidden rounded-full bg-slate-800" aria-hidden="true">
                                            <span class="block h-full rounded-full bg-rose-400/80" style="width: {{ $participant['damage_share'] }}%"></span>
                                        </span>
                                    </td>

                                    <td class="hidden whitespace-nowrap px-2 py-2.5 text-right tabular-nums text-slate-300 md:table-cell">{{ number_format($participant['gold']) }}</td>
                                    <td class="hidden whitespace-nowrap px-2 py-2.5 text-right tabular-nums text-slate-300 md:table-cell">{{ $participant['vision'] }}</td>

                                    <td class="hidden px-4 py-2.5 lg:table-cell">
                                        <div class="flex gap-0.5" aria-hidden="true">
                                            @foreach ($participant['items'] as $itemId)
                                                <x-item-icon :id="$itemId" />
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- The build panels of this team. They sit outside the table so they do not scroll sideways with it. --}}
                @foreach ($team['participants'] as $participant)
                    <div x-show="open === {{ $participant['id'] }}" x-cloak id="build-{{ $participant['id'] }}" class="mt-3">
                        <x-build-panel :participant="$participant" :has-matchup="$participant['position'] && in_array($participant['position'], $matchupPositions, true)" />
                    </div>
                @endforeach
            </section>
        @endforeach

        @if ($matchups !== [])
            <x-matchup-modal :matchups="$matchups" />
        @endif
    </div>
@endsection
