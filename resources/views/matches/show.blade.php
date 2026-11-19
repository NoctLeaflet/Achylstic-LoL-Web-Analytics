@extends('layouts.app')

@section('title', 'Match '.$match['match_id'].' · '.config('app.name'))

@section('content')
    <a href="{{ route('leaderboard') }}" class="text-sm text-slate-400 hover:text-slate-200">&larr; Leaderboard</a>

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
        <p class="mt-1 text-xs text-slate-600">{{ $match['match_id'] }}</p>
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
                            <tr @class([
                                'border-b border-slate-800/70 last:border-0',
                                'bg-sky-500/10' => $participant['player'] !== null,
                            ])>
                                <td class="py-2.5 pl-4 pr-3">
                                    <div class="flex items-center gap-3">
                                        <x-champion-icon :name="$participant['champion_name']" size="size-9" />
                                        <div class="min-w-0">
                                            @if ($participant['player'])
                                                <a href="{{ route('players.show', $participant['player']) }}" class="block truncate font-medium text-sky-300 hover:text-sky-200">{{ $participant['name'] }}</a>
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
                                    </div>
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
        </section>
    @endforeach
@endsection
