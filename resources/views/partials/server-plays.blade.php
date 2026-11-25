{{-- "When the Server Plays": a weekday x hour heatmap and a per-hour bar chart, in WIB. --}}
@php
    $activityDays = \App\Services\Stats\ServerActivity::DAYS;
    $maxHour = max(collect($serverActivity['hours'])->max('games'), 1);
    $busiest = $serverActivity['busiest'];
    $hourLabel = fn (int $h) => sprintf('%02d.00', $h);
@endphp

<section class="mt-10" aria-labelledby="server-plays">
    <h2 id="server-plays" class="text-xl font-semibold tracking-tight text-white">When the Server Plays</h2>
    <p class="mt-1 text-sm text-slate-400">
        Kapan grup paling sering main, waktu Indonesia (WIB). Setiap pemain yang ikut main dihitung sekali. Arahkan kursor ke kotak untuk melihat main, menang, dan kalah.
    </p>

    @if ($serverActivity['total'] === 0)
        <p class="mt-4 rounded-xl border border-dashed border-slate-700 p-6 text-center text-sm text-slate-400">Belum ada match tersimpan.</p>
    @else
        @if ($busiest)
            <p class="mt-2 text-sm text-slate-300">
                Paling ramai <span class="font-semibold text-white">{{ $busiest['day'] }} {{ $hourLabel($busiest['hour']) }}-{{ $hourLabel(($busiest['hour'] + 1) % 24) }}</span> &middot; {{ $busiest['games'] }} main
            </p>
        @endif

        <div class="mt-4 overflow-x-auto rounded-xl border border-slate-800 bg-slate-900/50 p-4">
            <div class="min-w-[36rem]">
                <div class="ml-9 grid grid-cols-[repeat(24,minmax(0,1fr))] gap-1 text-[10px] text-slate-500">
                    @foreach (range(0, 23) as $hour)
                        <span>{{ $hour % 3 === 0 ? $hour : '' }}</span>
                    @endforeach
                </div>

                @foreach ($activityDays as $d => $dayLabel)
                    <div class="mt-1 flex items-center gap-2">
                        <span class="w-7 text-xs text-slate-500">{{ $dayLabel }}</span>
                        <div class="grid flex-1 grid-cols-[repeat(24,minmax(0,1fr))] gap-1">
                            @foreach (range(0, 23) as $hour)
                                @php
                                    $cell = $serverActivity['cells'][$d][$hour];
                                    $losses = $cell['games'] - $cell['wins'];
                                    $alpha = round(0.3 + 0.7 * min($cell['games'], 7) / 7, 2);
                                    $rgb = $cell['wins'] > $losses ? '52,211,153' : ($cell['wins'] < $losses ? '251,113,133' : '148,163,184');
                                @endphp
                                @if ($cell['games'] === 0)
                                    <span class="h-4 rounded-sm bg-slate-800/50" title="{{ $dayLabel }} {{ $hourLabel($hour) }}: belum ada main"></span>
                                @else
                                    <span class="h-4 rounded-sm" style="background-color: rgba({{ $rgb }}, {{ $alpha }})"
                                          title="{{ $dayLabel }} {{ $hourLabel($hour) }}: {{ $cell['games'] }} main, {{ $cell['wins'] }}W {{ $losses }}L"></span>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-slate-500">
                    <span class="inline-flex items-center gap-2">
                        Bad day
                        <span class="h-2 w-24 rounded-full" style="background: linear-gradient(90deg, rgb(251,113,133), rgb(148,163,184), rgb(52,211,153))"></span>
                        Good day
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        1 game
                        @foreach ([0.3, 0.5, 0.75, 1] as $a)
                            <span class="size-3 rounded-sm" style="background-color: rgba(148,163,184, {{ $a }})"></span>
                        @endforeach
                        7+ game
                    </span>
                </div>
            </div>
        </div>

        <div class="mt-4 rounded-xl border border-slate-800 bg-slate-900/50 p-4">
            <div class="flex items-baseline justify-between">
                <h3 class="text-sm font-semibold text-white">Per jam</h3>
                <p class="text-xs text-slate-500">Semua hari. Hijau = menang, merah = kalah.</p>
            </div>
            <div class="mt-3 overflow-x-auto">
                <div class="grid min-w-[36rem] grid-cols-[repeat(24,minmax(0,1fr))] gap-1">
                    @foreach (range(0, 23) as $hour)
                        @php
                            $h = $serverActivity['hours'][$hour];
                            $hLosses = $h['games'] - $h['wins'];
                        @endphp
                        <div class="flex h-28 flex-col justify-end" title="{{ $hourLabel($hour) }}: {{ $h['games'] }} main, {{ $h['wins'] }}W {{ $hLosses }}L">
                            @if ($h['games'] > 0)
                                <div class="flex flex-col" style="height: {{ round($h['games'] / $maxHour * 100) }}%">
                                    <div class="min-h-px rounded-t-sm bg-rose-400/80" style="flex-grow: {{ $hLosses }}"></div>
                                    <div class="min-h-px bg-emerald-400/80" style="flex-grow: {{ $h['wins'] }}"></div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="mt-1 grid min-w-[36rem] grid-cols-[repeat(24,minmax(0,1fr))] gap-1 text-[10px] text-slate-500">
                    @foreach (range(0, 23) as $hour)
                        <span>{{ $hour % 3 === 0 ? sprintf('%02d', $hour) : '' }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</section>
