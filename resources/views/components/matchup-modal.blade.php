@props(['matchups'])

{{--
    Pop-up with a radar per lane: top vs top, jungle vs jungle, ... of the same match.
    It lives inside the Alpine scope of the match page, which owns "matchup" (the open
    lane, or null) and openMatchup()/closeMatchup(). The chart of a lane is only
    created while that lane is shown (x-if), and destroyed again when it is left.
--}}
<div x-show="matchup !== null" x-cloak
     x-transition:enter="transition duration-200 ease-out"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition duration-150 ease-in"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     @keydown.escape.window="if (matchup !== null) closeMatchup()"
     @click.self="closeMatchup()"
     class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/80 backdrop-blur-sm sm:items-center sm:p-6"
     role="dialog" aria-modal="true" aria-labelledby="matchup-title">

    <div class="relative flex max-h-[94vh] w-full flex-col overflow-hidden rounded-t-2xl border border-slate-700 bg-slate-900 shadow-2xl shadow-black/50 sm:max-w-3xl sm:rounded-2xl"
         x-show="matchup !== null"
         x-transition:enter="transition duration-200 ease-out"
         x-transition:enter-start="translate-y-6 opacity-0 sm:translate-y-0 sm:scale-95"
         x-transition:enter-end="translate-y-0 opacity-100 sm:scale-100">

        {{-- Header: title, lane tabs, close --}}
        <div class="border-b border-slate-800 px-4 pb-3 pt-4 sm:px-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 id="matchup-title" class="text-lg font-semibold text-white">Compare lane</h2>
                    <p class="text-xs text-slate-500">Pemain dengan peran yang sama di tim lawan. Tiap sumbu relatif terhadap yang terbaik dari keduanya (100).</p>
                </div>
                <button type="button" x-ref="matchupClose" @click="closeMatchup()" aria-label="Tutup"
                        class="shrink-0 rounded-lg border border-slate-700 p-1.5 text-slate-400 outline-none transition hover:border-slate-500 hover:text-white focus-visible:ring-2 focus-visible:ring-sky-400">
                    <svg class="size-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M5 5l10 10M15 5L5 15" /></svg>
                </button>
            </div>

            <div role="tablist" aria-label="Pilih lane" class="mt-3 flex gap-1 overflow-x-auto pb-1">
                @foreach ($matchups as $matchup)
                    <button type="button" role="tab"
                            @click="matchup = '{{ $matchup['position'] }}'"
                            :aria-selected="matchup === '{{ $matchup['position'] }}'"
                            :class="matchup === '{{ $matchup['position'] }}' ? 'bg-sky-500/20 text-sky-300' : 'text-slate-400 hover:text-slate-200'"
                            class="shrink-0 rounded-md px-3 py-1.5 text-sm font-medium outline-none transition focus-visible:ring-2 focus-visible:ring-sky-400">
                        {{ $matchup['label'] }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Body: one panel per lane --}}
        <div class="overflow-y-auto px-4 py-5 sm:px-6">
            @foreach ($matchups as $matchup)
                <div x-show="matchup === '{{ $matchup['position'] }}'" x-cloak role="tabpanel" aria-label="{{ $matchup['label'] }}">
                    {{-- The two players --}}
                    <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-2 sm:gap-4">
                        @foreach (['blue' => 'Tim Biru', 'red' => 'Tim Merah'] as $side => $teamName)
                            @php
                                $player = $matchup[$side];
                            @endphp
                            @if ($side === 'red')
                                <span class="px-1 text-xs font-bold uppercase tracking-widest text-slate-600" aria-hidden="true">vs</span>
                            @endif
                            <div @class(['flex min-w-0 items-center gap-3', 'flex-row-reverse text-right' => $side === 'red'])>
                                <x-champion-icon :name="$player['champion_name']" :id="$player['champion_id']" size="size-12 sm:size-14" />
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-white sm:text-base">{{ $player['name'] }}</p>
                                    <p class="truncate text-xs text-slate-400">{{ $player['champion_name'] }}</p>
                                    <p @class(['mt-0.5 flex flex-wrap items-center gap-1 text-xs', 'justify-end' => $side === 'red'])>
                                        <span @class([
                                            'font-semibold',
                                            'text-sky-300' => $side === 'blue',
                                            'text-rose-300' => $side === 'red',
                                        ])>{{ $teamName }}</span>
                                        {{-- The dot only makes sense when team and score share a line (not on narrow screens). --}}
                                        <span class="hidden text-slate-500 sm:inline">·</span>
                                        <span class="tabular-nums text-slate-300">Skor {{ $player['score'] }}</span>
                                        @if ($player['badge'])
                                            <x-score-badge :badge="$player['badge']" />
                                        @endif
                                    </p>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Radar, with its colour legend vertically centred on the right (below the chart on small screens) --}}
                    <template x-if="matchup === '{{ $matchup['position'] }}'">
                        <div class="relative mx-auto mt-4 w-full max-w-2xl">
                            <div x-data="radarChart(@js($matchup['radar']), { legend: false })" class="relative mx-auto h-72 max-w-md sm:h-80">
                                <canvas x-ref="canvas" role="img" aria-label="Grafik radar {{ $matchup['label'] }}: {{ $matchup['blue']['name'] }} melawan {{ $matchup['red']['name'] }}"></canvas>
                            </div>

                            <ul aria-label="Keterangan warna"
                                class="mt-3 flex flex-wrap justify-center gap-x-5 gap-y-1 md:absolute md:right-0 md:top-1/2 md:mt-0 md:w-44 md:-translate-y-1/2 md:flex-col md:flex-nowrap md:items-start md:justify-start md:gap-2.5">
                                @foreach ($matchup['radar']['datasets'] as $dataset)
                                    <li class="flex min-w-0 items-center gap-2 text-xs text-slate-300 md:text-sm">
                                        <span class="size-3 shrink-0 rounded-full" style="background-color: {{ $dataset['color'] }}" aria-hidden="true"></span>
                                        <span class="truncate" title="{{ $dataset['label'] }}">{{ $dataset['label'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </template>

                    {{-- The real numbers, the better value of each pair highlighted --}}
                    <div class="mt-4 overflow-hidden rounded-xl border border-slate-800">
                        @foreach ($matchup['rows'] as $row)
                            <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-3 border-b border-slate-800/70 px-4 py-2 text-sm last:border-0">
                                <span @class([
                                    'text-right tabular-nums',
                                    'font-semibold text-sky-300' => $row['winner'] === 'blue',
                                    'text-slate-400' => $row['winner'] !== 'blue',
                                ])>{{ $row['blue'] }}</span>
                                <span class="w-32 text-center text-xs uppercase tracking-wide text-slate-500 sm:w-40">{{ $row['label'] }}</span>
                                <span @class([
                                    'tabular-nums',
                                    'font-semibold text-rose-300' => $row['winner'] === 'red',
                                    'text-slate-400' => $row['winner'] !== 'red',
                                ])>{{ $row['red'] }}</span>
                            </div>
                        @endforeach
                    </div>

                    <p class="mt-3 text-center text-xs text-slate-500">
                        Unggul di
                        <span class="font-semibold text-sky-300">{{ $matchup['leads']['blue'] }}</span>
                        <span class="text-slate-600">:</span>
                        <span class="font-semibold text-rose-300">{{ $matchup['leads']['red'] }}</span>
                        dari {{ count($matchup['rows']) }} metrik (Biru : Merah)
                    </p>
                </div>
            @endforeach
        </div>
    </div>
</div>
