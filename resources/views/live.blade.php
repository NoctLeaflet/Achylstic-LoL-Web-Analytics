@extends('layouts.app')

@section('title', 'Live · '.config('app.name'))
@section('description', 'Siapa dari grup yang sedang bermain sekarang, lengkap dengan game yang berjalan.')

@section('content')
    {{-- Reloads by itself every 30 seconds, so a game that starts or ends shows up without pressing anything. --}}
    <div x-data x-init="setTimeout(() => location.reload(), 30000)">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-white">Live</h1>
                <p class="mt-1 text-sm text-slate-400">
                    {{ $games->count() > 0 ? $games->count().' game berjalan sekarang.' : 'Tidak ada yang sedang bermain.' }}
                    Dicek ke Riot tiap 2 menit, halaman ini menyegarkan diri tiap 30 detik.
                </p>
            </div>
        </div>

        <p class="mt-3 rounded-lg border border-slate-800 bg-slate-900/40 px-3 py-2 text-xs text-slate-500">
            Riot tidak menyediakan status online. Yang bisa diketahui hanya apakah seseorang <b class="text-slate-400">sedang dalam game</b>,
            baru selesai main (dalam {{ $recentMinutes }} menit terakhir), atau tidak sedang main.
        </p>

        {{-- Running games --}}
        @foreach ($games as $game)
            <section class="mt-5 overflow-hidden rounded-2xl border border-rose-500/30 bg-gradient-to-b from-rose-500/10 via-slate-900/60 to-slate-900/40" aria-label="Game berjalan">
                <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-800 px-4 py-3 sm:px-5">
                    <div class="flex items-center gap-3">
                        <span class="relative flex size-2.5" aria-hidden="true">
                            <span class="absolute inline-flex size-full animate-ping rounded-full bg-rose-400 opacity-75"></span>
                            <span class="relative inline-flex size-2.5 rounded-full bg-rose-400"></span>
                        </span>
                        <div>
                            <p class="font-semibold text-white">{{ $game['queue'] }}</p>
                            <p class="text-xs text-slate-400">
                                {{ collect($game['friends'])->map->name->implode(', ') }} di game ini
                            </p>
                        </div>
                    </div>
                    <p class="text-2xl font-extrabold tabular-nums text-white"
                       x-data="{ s: {{ $game['elapsed'] }} }" x-init="setInterval(() => s++, 1000)"
                       x-text="String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0')">
                        {{ \App\Support\Format::duration($game['elapsed']) }}
                    </p>
                </header>

                @if (count($game['live']->participants ?? []) === 0)
                    <p class="px-5 py-6 text-center text-sm text-slate-400">Daftar pemain game ini belum tersimpan, muncul di pengecekan berikutnya.</p>
                @else
                    <div class="grid gap-px bg-slate-800/60 md:grid-cols-2">
                        @foreach ($game['teams'] as $teamName => $members)
                            <div class="bg-slate-900/80 p-4 sm:p-5">
                                <h2 @class(['text-xs font-bold uppercase tracking-widest', 'text-sky-300' => $teamName === 'Tim Biru', 'text-rose-300' => $teamName === 'Tim Merah'])>{{ $teamName }}</h2>
                                <ul class="mt-3 space-y-2">
                                    @foreach ($members as $member)
                                        @php
                                            $friend = $game['tracked'][$member['puuid']] ?? null;
                                            $name = $friend ?? ($member['riot_id'] !== '' ? $member['riot_id'] : 'Summoner');
                                        @endphp
                                        <li @class([
                                            'flex items-center gap-3 rounded-lg px-2 py-1.5',
                                            'border border-sky-500/30 bg-sky-500/10' => $friend !== null,
                                        ])>
                                            <x-champion-icon :name="$game['names'][$member['champion_id']] ?? ''" :id="$member['champion_id']" size="size-10" />
                                            <div class="min-w-0 flex-1">
                                                <p @class(['truncate text-sm font-medium', 'text-white' => $friend !== null, 'text-slate-300' => $friend === null])>{{ $name }}</p>
                                                <p class="truncate text-xs text-slate-500">{{ $game['names'][$member['champion_id']] ?? 'Champion #'.$member['champion_id'] }}</p>
                                            </div>
                                            @if ($friend !== null)
                                                <span class="rounded-full bg-sky-500/20 px-2 py-0.5 text-xs font-semibold text-sky-200">Teman</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>

                                @php
                                    $teamId = $teamName === 'Tim Biru' ? 100 : 200;
                                @endphp
                                @if (! empty($game['bans'][$teamId]))
                                    <div class="mt-3 flex flex-wrap items-center gap-1.5 border-t border-slate-800 pt-3">
                                        <span class="text-xs text-slate-500">Ban</span>
                                        @foreach ($game['bans'][$teamId] as $banned)
                                            <x-champion-icon :name="$game['names'][$banned] ?? ''" :id="$banned" size="size-7" class="opacity-60 grayscale" />
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach

        {{-- Every player and their status --}}
        <h2 class="mt-8 text-lg font-semibold text-white">Semua pemain</h2>
        <ul class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($rows as $row)
                @php
                    $p = $row['player'];
                    $last = $row['last'];
                @endphp
                <li @class([
                    'flex items-center gap-3 rounded-xl border p-3',
                    'border-rose-500/40 bg-rose-500/5' => $row['status'] === 'in_game',
                    'border-amber-500/30 bg-amber-500/5' => $row['status'] === 'recent',
                    'border-slate-800 bg-slate-900/50' => $row['status'] === 'idle',
                ])>
                    <x-profile-icon :player="$p" size="size-12" />
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('players.show', $p) }}" class="block truncate font-semibold text-white hover:underline">{{ $p->name }}</a>
                        @if ($row['status'] === 'in_game')
                            <x-live-badge :game="$row['live']" class="mt-1" />
                        @elseif ($row['status'] === 'recent')
                            <p class="mt-0.5 text-xs font-medium text-amber-300">Baru selesai main · {{ $last['ended_at']->diffForHumans(short: true) }}</p>
                        @else
                            <p class="mt-0.5 text-xs text-slate-500">Tidak sedang main</p>
                        @endif
                        @if ($last)
                            <a href="{{ route('matches.show', $last['match_id']) }}" class="mt-0.5 block truncate text-xs text-slate-500 hover:text-slate-300">
                                Terakhir: {{ $last['champion_name'] }} · {{ $last['win'] ? 'Win' : 'Lose' }}{{ $last['queue'] ? ' · '.$last['queue'] : '' }} · {{ $last['ended_at']->diffForHumans(short: true) }}
                            </a>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
@endsection
