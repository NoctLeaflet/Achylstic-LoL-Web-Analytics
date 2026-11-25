{{-- "Best this week", "This week" and "Role coverage" sections of the leaderboard page. --}}
@php
    $tints = [
        'carry' => ['border-amber-500/30 bg-amber-500/5', 'bg-amber-500/15 text-amber-300'],
        'comeback' => ['border-sky-500/30 bg-sky-500/5', 'bg-sky-500/15 text-sky-300'],
        'chaos' => ['border-rose-500/30 bg-rose-500/5', 'bg-rose-500/15 text-rose-300'],
        'tilt' => ['border-slate-600/50 bg-slate-800/40', 'bg-slate-700 text-slate-300'],
    ];
@endphp

@if (count($weekly['best']) > 0 || $bestMode !== null || $weekly['games'] > 0)
    <section class="mt-10" aria-labelledby="best-week" id="best">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 id="best-week" class="text-xl font-semibold tracking-tight text-white">Best this week</h2>
                <p class="mt-1 text-sm text-slate-400">Game paling menonjol dari semua pemain dalam {{ \App\Services\Stats\WeeklyHighlights::DAYS }} hari terakhir.</p>
            </div>

            <form method="GET" action="{{ route('leaderboard') }}#best">
                <input type="hidden" name="queue" value="{{ $queue->slug() }}">
                <input type="hidden" name="period" value="{{ $period->value }}">
                <label for="best-mode" class="sr-only">Mode game</label>
                <select id="best-mode" name="best" onchange="this.form.submit()"
                        class="rounded-md border border-slate-700 bg-slate-950 px-3 py-1.5 text-sm text-white focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500">
                    <option value="">Semua mode</option>
                    @foreach ($trackedModes as $mode)
                        <option value="{{ $mode->slug() }}" @selected($bestMode === $mode)>{{ $mode->label() }}</option>
                    @endforeach
                </select>
                <noscript><button type="submit" class="ml-2 rounded-md border border-slate-700 px-3 py-1.5 text-sm text-slate-200">Terapkan</button></noscript>
            </form>
        </div>

        @if (count($weekly['best']) === 0)
            <p class="mt-4 rounded-xl border border-dashed border-slate-700 p-6 text-center text-sm text-slate-400">
                Belum ada game {{ $bestMode?->label() }} minggu ini.
            </p>
        @endif

        <div class="mt-4 space-y-3">
            @foreach ($weekly['best'] as $item)
                @php
                    $g = $item['game'];
                    [$box, $chip] = $tints[$item['key']];
                @endphp
                <div>
                    <p class="mb-1.5 flex items-center gap-2 text-sm text-slate-400">
                        <span class="rounded px-2 py-0.5 text-xs font-bold tracking-wide {{ $chip }}">{{ $item['label'] }}</span>
                        {{ $item['blurb'] }}
                    </p>
                    <x-game-row :game="$g" :box="$box" />
                </div>
            @endforeach
        </div>
    </section>
@endif

<div class="mt-10 grid gap-8 lg:grid-cols-2">
    <section aria-labelledby="this-week">
        <h2 id="this-week" class="text-xl font-semibold tracking-tight text-white">This week</h2>
        <p class="mt-1 text-sm text-slate-400">
            Penghargaan {{ \App\Services\Stats\WeeklyHighlights::DAYS }} hari terakhir (minimal {{ \App\Services\Stats\WeeklyHighlights::MIN_GAMES }} game).
        </p>

        @if (count($weekly['awards']) === 0)
            <p class="mt-4 rounded-xl border border-dashed border-slate-700 p-6 text-center text-sm text-slate-400">
                Belum ada pemain dengan cukup game minggu ini.
            </p>
        @else
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                @foreach ($weekly['awards'] as $award)
                    <article class="flex flex-col justify-between rounded-xl border border-slate-800 bg-slate-900/50 p-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ $award['title'] }}</p>
                            <p class="mt-1.5 text-xl font-extrabold leading-tight text-white">{{ $award['value'] }}</p>
                        </div>
                        <a href="{{ route('players.show', $award['player']) }}" class="mt-4 flex items-center gap-2.5">
                            <x-profile-icon :player="$award['player']" size="size-8" />
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-white">{{ $award['player']->name }}</span>
                                <span class="block truncate text-xs text-slate-500">{{ $award['blurb'] }}</span>
                            </span>
                        </a>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section aria-labelledby="role-coverage">
        <h2 id="role-coverage" class="text-xl font-semibold tracking-tight text-white">Role Coverage</h2>
        <p class="mt-1 text-sm text-slate-400">Siapa main di lane mana, dan lane mana yang masih kosong.</p>

        <div class="mt-4 divide-y divide-slate-800/70 overflow-hidden rounded-xl border border-slate-800 bg-slate-900/50">
            @foreach ($roles as $role)
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 p-3">
                    <p class="w-20 shrink-0 font-semibold text-white">{{ $role['label'] }}</p>
                    <p class="w-24 shrink-0 text-xs text-slate-400">
                        <span class="font-semibold text-slate-200">{{ count($role['mains']) }}</span> main · {{ count($role['fills']) }} fill
                    </p>
                    <div class="flex min-w-0 flex-1 flex-wrap justify-end gap-1.5">
                        @forelse ([...$role['mains'], ...$role['fills']] as $i => $entry)
                            <a href="{{ route('players.show', $entry['player']) }}"
                               title="{{ $entry['games'] }} game"
                               @class([
                                   'max-w-full truncate rounded-full px-2.5 py-1 text-xs font-medium',
                                   'bg-slate-700 text-white' => $i < count($role['mains']),
                                   'bg-slate-800/70 text-slate-400' => $i >= count($role['mains']),
                               ])>{{ $entry['player']->name }}</a>
                        @empty
                            <span class="text-xs text-slate-600">Belum ada</span>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
        <p class="mt-2 text-xs text-slate-500">
            Main = minimal {{ round(\App\Services\Stats\RoleCoverage::MAIN_SHARE * 100) }}% game di lane itu, fill = minimal {{ round(\App\Services\Stats\RoleCoverage::FILL_SHARE * 100) }}% (dari semua match tersimpan).
        </p>
    </section>
</div>
