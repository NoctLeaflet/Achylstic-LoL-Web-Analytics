@extends('layouts.app')

@section('title', 'Rekap '.$player->name.' · '.config('app.name'))
@section('description', 'Rekap '.$player->name.($recap ? ': '.$recap['games'].' game, winrate '.$recap['winrate'].'%, KDA '.\App\Support\Format::number($recap['kda'], 2).'.' : '.'))

@section('content')
    <div class="mx-auto w-full max-w-lg">
        <x-back-link :fallback="route('players.show', $player)" />

        {{-- Period tabs --}}
        <nav class="mt-3 flex gap-1" aria-label="Periode">
            @foreach ($periods as $key => $label)
                <a href="{{ route('players.recap', ['player' => $player, 'period' => $key === 'season' ? null : $key]) }}"
                   @class([
                       'rounded-md px-3 py-1.5 text-sm font-medium transition',
                       'bg-indigo-500/20 text-indigo-200' => $period === $key,
                       'text-slate-400 hover:text-slate-200' => $period !== $key,
                   ])>{{ $label }}</a>
            @endforeach
        </nav>

        @if (! $recap)
            <p class="mt-4 rounded-xl border border-dashed border-slate-700 p-8 text-center text-sm text-slate-400">
                {{ $player->name }} belum punya game di periode ini (sejak {{ $since->translatedFormat('j F Y') }}).
            </p>
        @else
            {{-- The card: made to look good in a screenshot --}}
            <article class="mt-4 overflow-hidden rounded-3xl border border-indigo-500/30 bg-gradient-to-b from-indigo-950 via-slate-900 to-slate-950 p-6 shadow-2xl shadow-indigo-950/40" aria-label="Rekap {{ $player->name }}">
                <header class="flex items-center gap-4">
                    <x-profile-icon :player="$player" size="size-16" />
                    <div class="min-w-0">
                        <p class="text-xs font-bold uppercase tracking-widest text-indigo-300">Rekap {{ $periods[$period] }}</p>
                        <h1 class="truncate text-2xl font-semibold text-white">{{ $player->name }}</h1>
                        <p class="truncate text-xs text-slate-400">{{ $player->riot_id }} · sejak {{ $since->translatedFormat('j M Y') }}</p>
                    </div>
                </header>

                <div class="mt-6 grid grid-cols-3 gap-3 text-center">
                    <div class="rounded-xl bg-slate-950/50 p-3">
                        <p class="text-3xl font-extrabold tabular-nums text-white">{{ $recap['games'] }}</p>
                        <p class="text-xs text-slate-400">game</p>
                    </div>
                    <div class="rounded-xl bg-slate-950/50 p-3">
                        <p @class(['text-3xl font-extrabold tabular-nums', 'text-emerald-400' => $recap['winrate'] >= 50, 'text-rose-400' => $recap['winrate'] < 50])>{{ $recap['winrate'] }}%</p>
                        <p class="text-xs text-slate-400">{{ $recap['wins'] }}M {{ $recap['losses'] }}K</p>
                    </div>
                    <div class="rounded-xl bg-slate-950/50 p-3">
                        <p class="text-3xl font-extrabold tabular-nums text-white">{{ \App\Support\Format::number($recap['hours']) }}</p>
                        <p class="text-xs text-slate-400">jam main</p>
                    </div>
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-400">KDA</dt><dd class="font-semibold tabular-nums text-white">{{ \App\Support\Format::number($recap['kda'], 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-400">Kill + assist</dt><dd class="font-semibold tabular-nums text-white">{{ number_format($recap['kills'] + $recap['assists'], 0, ',', '.') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-400">MVP</dt><dd class="font-semibold tabular-nums text-amber-300">{{ $recap['mvp'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-400">ACE</dt><dd class="font-semibold tabular-nums text-amber-300">{{ $recap['ace'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-400">Menang beruntun</dt><dd class="font-semibold tabular-nums text-white">{{ $recap['longest_streak'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-400">Lane utama</dt><dd class="font-semibold text-white">{{ $recap['role']['label'] ?? '–' }}</dd></div>
                </dl>

                <section class="mt-5" aria-label="Champion favorit">
                    <h2 class="text-xs font-bold uppercase tracking-widest text-slate-500">Champion favorit</h2>
                    <ul class="mt-2 space-y-2">
                        @foreach ($recap['champions'] as $champion)
                            <li class="flex items-center gap-3">
                                <x-champion-icon :name="$champion['name']" :id="$champion['champion_id']" size="size-10" />
                                <span class="min-w-0 flex-1 truncate font-medium text-white">{{ $champion['name'] }}</span>
                                <span class="text-xs tabular-nums text-slate-400">{{ $champion['games'] }} game · {{ $champion['winrate'] }}%</span>
                            </li>
                        @endforeach
                    </ul>
                </section>

                @if ($recap['rank'])
                    <section class="mt-5 rounded-xl bg-slate-950/50 p-3" aria-label="Rank">
                        <h2 class="text-xs font-bold uppercase tracking-widest text-slate-500">Rank Solo/Duo</h2>
                        <p class="mt-1 flex flex-wrap items-baseline justify-between gap-2 text-white">
                            <span class="font-semibold">{{ $recap['rank']['from'] }} <span class="text-slate-500">&rarr;</span> {{ $recap['rank']['to'] }}</span>
                            <span @class([
                                'text-sm font-semibold tabular-nums',
                                'text-emerald-400' => $recap['rank']['change'] > 0,
                                'text-rose-400' => $recap['rank']['change'] < 0,
                                'text-slate-400' => $recap['rank']['change'] === 0,
                            ])>{{ $recap['rank']['change'] > 0 ? '+' : '' }}{{ $recap['rank']['change'] }} LP</span>
                        </p>
                    </section>
                @endif

                <section class="mt-5" aria-label="Game terbaik">
                    <h2 class="text-xs font-bold uppercase tracking-widest text-slate-500">Game terbaik</h2>
                    <a href="{{ route('matches.show', $recap['best_game']['match_id']) }}" class="mt-2 flex items-center gap-3 rounded-xl bg-slate-950/50 p-3 hover:bg-slate-950/80">
                        <x-champion-icon :name="$recap['best_game']['champion']" :id="$recap['best_game']['champion_id']" size="size-10" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium text-white">{{ $recap['best_game']['champion'] }}</span>
                            <span class="block text-xs tabular-nums text-slate-400">{{ $recap['best_game']['kda'] }}</span>
                        </span>
                        <span class="text-2xl font-extrabold tabular-nums text-violet-300">{{ $recap['best_game']['score'] }}</span>
                    </a>
                </section>

                @if (count($recap['achievements']) > 0)
                    <section class="mt-5" aria-label="Pencapaian">
                        <h2 class="text-xs font-bold uppercase tracking-widest text-slate-500">Pencapaian baru</h2>
                        <p class="mt-2 flex flex-wrap gap-2">
                            @foreach ($recap['achievements'] as $achievement)
                                <span class="rounded-full bg-amber-500/10 px-3 py-1 text-xs font-medium text-amber-200">{{ $achievement['icon'] }} {{ $achievement['name'] }}</span>
                            @endforeach
                        </p>
                    </section>
                @endif

                <footer class="mt-6 flex items-center justify-between border-t border-slate-800 pt-3 text-xs text-slate-500">
                    <span class="font-semibold text-slate-300">{{ config('app.name') }}</span>
                    <span>League of Legends Analysis</span>
                </footer>
            </article>

            <div class="mt-4 text-center" x-data="{ copied: false }">
                <button type="button"
                        @click="navigator.share ? navigator.share({ title: document.title, url: location.href }).catch(() => {}) : navigator.clipboard.writeText(location.href).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                        class="rounded-md border border-indigo-500/40 bg-indigo-500/10 px-4 py-2 text-sm font-medium text-indigo-200 transition hover:bg-indigo-500/20">
                    <span x-show="! copied">Bagikan tautan</span>
                    <span x-show="copied" x-cloak>Tautan disalin!</span>
                </button>
                <p class="mt-2 text-xs text-slate-500">Mau dibagikan sebagai gambar? Ambil screenshot kartu di atas.</p>
            </div>
        @endif
    </div>
@endsection
