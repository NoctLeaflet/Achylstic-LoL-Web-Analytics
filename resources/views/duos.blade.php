@extends('layouts.app')

@section('title', 'Duo · '.config('app.name'))

@section('content')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-white">Duo terbaik</h1>
            <p class="mt-1 text-sm text-slate-400">
                Pasangan teman diurutkan dari winrate saat satu tim (minimal {{ $minGames }} game bersama){{ $mode ? ', '.$mode->label() : '' }}.
            </p>
        </div>

        <form method="GET" action="{{ route('duos') }}">
            <label for="duo-mode" class="sr-only">Mode game</label>
            <select id="duo-mode" name="mode" onchange="this.form.submit()"
                    class="rounded-md border border-slate-700 bg-slate-950 px-3 py-1.5 text-sm text-white focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500">
                <option value="">Semua mode</option>
                @foreach ($modes as $m)
                    <option value="{{ $m->slug() }}" @selected($mode === $m)>{{ $m->label() }}</option>
                @endforeach
            </select>
            <noscript><button type="submit" class="ml-2 rounded-md border border-slate-700 px-3 py-1.5 text-sm text-slate-200">Terapkan</button></noscript>
        </form>
    </div>

    @if ($mostPlayed || $rivalry)
        <div class="mt-5 grid gap-3 sm:grid-cols-2">
            @foreach ([
                ['Paling sering bareng', $mostPlayed, 'together', 'game satu tim'],
                ['Rival terbesar', $rivalry, 'versus', 'game saling berhadapan'],
            ] as [$title, $pair, $kind, $unit])
                @if ($pair)
                    <article class="rounded-xl border border-slate-800 bg-slate-900/50 p-4">
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ $title }}</p>
                        <p class="mt-2 flex flex-wrap items-center gap-2 font-semibold text-white">
                            <x-profile-icon :player="$pair['first']" size="size-8" />
                            {{ $pair['first']->name }}
                            <span class="text-slate-600">&amp;</span>
                            <x-profile-icon :player="$pair['second']" size="size-8" />
                            {{ $pair['second']->name }}
                        </p>
                        <p class="mt-1 text-sm text-slate-400">{{ $pair[$kind]['games'] }} {{ $unit }}</p>
                    </article>
                @endif
            @endforeach
        </div>
    @endif

    @if (count($ranked) === 0)
        <p class="mt-6 rounded-xl border border-dashed border-slate-700 p-8 text-center text-sm text-slate-400">
            Belum ada duo dengan minimal {{ $minGames }} game bersama.
        </p>
    @else
        <div class="mt-5 overflow-hidden rounded-xl border border-slate-800 bg-slate-900/50">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-800 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="w-10 py-3 pl-4 pr-2 font-medium">#</th>
                        <th scope="col" class="py-3 pr-3 font-medium">Duo</th>
                        <th scope="col" class="py-3 pr-4 font-medium">Satu tim</th>
                        <th scope="col" class="hidden py-3 pr-4 font-medium sm:table-cell">Berhadapan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($ranked as $i => $pair)
                        <tr class="border-b border-slate-800/70 last:border-0 hover:bg-slate-800/30">
                            <td class="py-3 pl-4 pr-2 tabular-nums text-slate-500">{{ $i + 1 }}</td>
                            <td class="py-3 pr-3">
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <a href="{{ route('players.show', $pair['first']) }}" class="flex items-center gap-2 font-medium text-white hover:underline">
                                        <x-profile-icon :player="$pair['first']" size="size-8" />{{ $pair['first']->name }}
                                    </a>
                                    <span class="text-slate-600">&amp;</span>
                                    <a href="{{ route('players.show', $pair['second']) }}" class="flex items-center gap-2 font-medium text-white hover:underline">
                                        <x-profile-icon :player="$pair['second']" size="size-8" />{{ $pair['second']->name }}
                                    </a>
                                </div>
                            </td>
                            <td class="py-3 pr-4"><x-pair-record :record="$pair['together']" /></td>
                            <td class="hidden py-3 pr-4 sm:table-cell">
                                @if ($pair['versus']['games'] > 0)
                                    <span class="text-slate-300">{{ $pair['first']->name }} {{ $pair['versus']['wins'] }}</span>
                                    <span class="text-slate-600">&ndash;</span>
                                    <span class="text-slate-300">{{ $pair['versus']['losses'] }} {{ $pair['second']->name }}</span>
                                @else
                                    <span class="text-slate-600">Belum pernah</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($unranked > 0)
            <p class="mt-2 text-xs text-slate-500">{{ $unranked }} duo lain belum punya {{ $minGames }} game bersama.</p>
        @endif
    @endif
@endsection
