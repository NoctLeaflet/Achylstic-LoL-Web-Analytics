@extends('layouts.app')

@section('title', 'Champion · '.config('app.name'))
@section('description', 'Champion yang paling sering dimainkan satu grup, winrate dan KDA-nya, dan siapa jagonya.')

@section('content')
    @php
        $query = fn (array $extra) => route('champions', array_filter(array_merge(['mode' => $mode?->slug(), 'sort' => $sort === 'games' ? null : $sort], $extra)));
    @endphp

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-white">Champion grup</h1>
            <p class="mt-1 text-sm text-slate-400">
                {{ $total }} champion dimainkan{{ $mode ? ' di '.$mode->label() : ' di semua mode' }}. Diurutkan dari
                {{ ['games' => 'jumlah game', 'winrate' => 'winrate', 'kda' => 'KDA'][$sort] }}.
            </p>
        </div>

        <form method="GET" action="{{ route('champions') }}">
            @if ($sort !== 'games')
                <input type="hidden" name="sort" value="{{ $sort }}">
            @endif
            <label for="champ-mode" class="sr-only">Mode game</label>
            <select id="champ-mode" name="mode" onchange="this.form.submit()"
                    class="rounded-md border border-slate-700 bg-slate-950 px-3 py-1.5 text-sm text-white focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500">
                <option value="">Semua mode</option>
                @foreach ($modes as $m)
                    <option value="{{ $m->slug() }}" @selected($mode === $m)>{{ $m->label() }}</option>
                @endforeach
            </select>
            <noscript><button type="submit" class="ml-2 rounded-md border border-slate-700 px-3 py-1.5 text-sm text-slate-200">Terapkan</button></noscript>
        </form>
    </div>

    @if ($mostPlayed)
        <div class="mt-5 grid gap-3 sm:grid-cols-3">
            @foreach ([
                ['Paling sering dimainkan', $mostPlayed, $mostPlayed['games'].' game'],
                ['Winrate tertinggi', $bestWinrate, ($bestWinrate['winrate'] ?? 0).'% dari '.($bestWinrate['games'] ?? 0).' game'],
                ['KDA tertinggi', $bestKda, \App\Support\Format::number($bestKda['kda'] ?? 0, 2).' KDA ('.($bestKda['games'] ?? 0).' game)'],
            ] as [$title, $champion, $value])
                @if ($champion)
                    <article class="flex items-center gap-3 rounded-xl border border-slate-800 bg-slate-900/50 p-4">
                        <x-champion-icon :name="$champion['champion_name']" :id="$champion['champion_id']" size="size-12" />
                        <div class="min-w-0">
                            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ $title }}</p>
                            <p class="truncate font-semibold text-white">{{ $champion['champion_name'] }}</p>
                            <p class="text-xs text-slate-400">{{ $value }}</p>
                        </div>
                    </article>
                @endif
            @endforeach
        </div>
    @endif

    @if (count($champions) === 0)
        <p class="mt-6 rounded-xl border border-dashed border-slate-700 p-8 text-center text-sm text-slate-400">
            Belum ada champion dengan minimal {{ $minGames }} game untuk urutan ini.
        </p>
    @else
        <div class="mt-5 overflow-hidden rounded-xl border border-slate-800 bg-slate-900/50">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-800 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="py-3 pl-4 pr-3 font-medium">Champion</th>
                        @foreach (['games' => 'Game', 'winrate' => 'Winrate', 'kda' => 'KDA'] as $key => $label)
                            <th scope="col" class="py-3 pr-4 font-medium" aria-sort="{{ $sort === $key ? 'descending' : 'none' }}">
                                <a href="{{ $query(['sort' => $key === 'games' ? null : $key]) }}"
                                   @class(['hover:text-slate-300', 'text-sky-300' => $sort === $key])>{{ $label }}{{ $sort === $key ? ' ▼' : '' }}</a>
                            </th>
                        @endforeach
                        <th scope="col" class="hidden py-3 pr-4 font-medium md:table-cell">Jagonya</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($champions as $champion)
                        <tr class="border-b border-slate-800/70 last:border-0 hover:bg-slate-800/30">
                            <td class="py-2.5 pl-4 pr-3">
                                <span class="flex items-center gap-3">
                                    <x-champion-icon :name="$champion['champion_name']" :id="$champion['champion_id']" size="size-9" />
                                    <span class="font-medium text-white">{{ $champion['champion_name'] }}</span>
                                </span>
                            </td>
                            <td class="py-2.5 pr-4 tabular-nums text-slate-200">{{ $champion['games'] }}</td>
                            <td class="py-2.5 pr-4 tabular-nums">
                                <span @class(['font-semibold', 'text-emerald-400' => $champion['winrate'] >= 50, 'text-rose-400' => $champion['winrate'] < 50])>{{ $champion['winrate'] }}%</span>
                            </td>
                            <td class="py-2.5 pr-4 tabular-nums text-slate-200">{{ \App\Support\Format::number($champion['kda'], 2) }}</td>
                            <td class="hidden py-2.5 pr-4 md:table-cell">
                                <span class="flex flex-wrap gap-1.5">
                                    @foreach ($champion['players'] as $entry)
                                        <a href="{{ route('players.show', $entry['player']) }}"
                                           title="{{ $entry['games'] }} game, winrate {{ $entry['winrate'] }}%"
                                           class="rounded-full bg-slate-800 px-2.5 py-1 text-xs text-slate-300 hover:bg-slate-700">
                                            {{ $entry['player']->name }} <span class="text-slate-500">{{ $entry['games'] }}</span>
                                        </a>
                                    @endforeach
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($sort !== 'games')
            <p class="mt-2 text-xs text-slate-500">Hanya champion dengan minimal {{ $minGames }} game yang ditampilkan di urutan ini.</p>
        @endif
    @endif
@endsection
