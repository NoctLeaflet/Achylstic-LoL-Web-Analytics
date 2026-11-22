@props(['performance', 'match'])

{{--
    "Performance" pop-up: how each tracked player did against the player of the same lane on the other
    team. Lives inside the Alpine scope of the match page ("performanceOpen" and "perfId", the player
    shown). The gold chart is only created while its player is shown (x-if).
--}}
<div x-show="performanceOpen" x-cloak
     x-transition:enter="transition duration-200 ease-out"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition duration-150 ease-in"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     @keydown.escape.window="performanceOpen = false"
     @click.self="performanceOpen = false"
     class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/80 backdrop-blur-sm sm:items-center sm:p-6"
     role="dialog" aria-modal="true" aria-labelledby="performance-title">

    <div class="relative flex max-h-[94vh] w-full flex-col overflow-hidden rounded-t-2xl border border-slate-700 bg-slate-900 shadow-2xl shadow-black/50 sm:max-w-3xl sm:rounded-2xl">
        <div class="flex items-start justify-between gap-3 border-b border-slate-800 px-4 pb-3 pt-4 sm:px-6">
            <div>
                <h2 id="performance-title" class="text-lg font-semibold text-white">Performance</h2>
                <p class="text-xs text-slate-500">Pemain melawan lawan di lane yang sama. Ganti pemain lewat ikon di bawah.</p>
            </div>
            <button type="button" @click="performanceOpen = false" aria-label="Tutup"
                    class="shrink-0 rounded-lg border border-slate-700 p-1.5 text-slate-400 outline-none transition hover:border-slate-500 hover:text-white focus-visible:ring-2 focus-visible:ring-sky-400">
                <svg class="size-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M5 5l10 10M15 5L5 15" /></svg>
            </button>
        </div>

        <div class="overflow-y-auto px-4 py-5 sm:px-6">
            @foreach ($performance as $p)
                @php
                    $lane = $p['lane'];
                    $ordinal = fn (int $n) => $n.(in_array($n % 100, [11, 12, 13], true) ? 'th' : [1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th');
                @endphp
                <div x-show="perfId === {{ $p['id'] }}" x-cloak role="tabpanel" aria-label="{{ $p['name'] }}">
                    {{-- Header --}}
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <x-champion-icon :name="$p['champion_name']" :id="$p['champion_id']" size="size-14" class="ring-2 !ring-sky-400/70" />
                            <div>
                                <p class="text-lg font-semibold text-white">{{ $p['name'] }} <span class="text-sm font-normal text-slate-400">on {{ $p['champion_name'] }}</span></p>
                                @if ($p['badge'])
                                    <x-score-badge :badge="$p['badge']" />
                                @endif
                            </div>
                        </div>
                        <div class="text-right">
                            <p @class(['text-3xl font-extrabold uppercase tracking-wide sm:text-4xl', 'text-sky-300' => $p['win'], 'text-rose-300' => ! $p['win']])>{{ $p['win'] ? 'Win' : 'Lose' }}</p>
                            <p class="text-xs text-slate-400">{{ $match['queue'] }} · {{ \App\Support\Format::duration($match['duration']) }} · {{ $match['started_at']->diffForHumans(short: true) }}</p>
                        </div>
                    </div>

                    {{-- Lane headline --}}
                    @if ($p['opponent'])
                        <div class="mt-4 flex items-center gap-3 border-t border-slate-800 pt-4">
                            <x-champion-icon :name="$p['champion_name']" :id="$p['champion_id']" size="size-9" class="ring-2 !ring-sky-400/70" />
                            <span class="text-xs font-bold uppercase tracking-widest text-slate-500">vs</span>
                            <x-champion-icon :name="$p['opponent']['champion_name']" :id="$p['opponent']['champion_id']" size="size-9" class="ring-2 !ring-rose-400/70" />
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-white">{{ $p['opponent']['name'] }}</p>
                                <p class="text-xs text-slate-400">{{ $p['opponent']['champion_name'] }}</p>
                            </div>
                        </div>

                        @if ($lane)
                            <p class="mt-3 text-xl font-semibold text-white sm:text-2xl">
                                {{ $p['name'] }}
                                {{ ['won' => 'won lane,', 'lost' => 'lost lane,', 'even' => 'had an even lane,'][$lane['outcome']] }}
                                <span class="text-sky-300">{{ $lane['gold'] >= 0 ? '+' : '-' }}{{ number_format(abs($lane['gold']), 0, '.', ',') }} gold at {{ $lane['minute'] }} minutes</span>
                            </p>
                            @if ($lane['swing'])
                                <p class="text-sm text-slate-400">Biggest swing {{ $lane['swing'] }}.</p>
                            @endif

                            <template x-if="performanceOpen && perfId === {{ $p['id'] }}">
                                <div x-data="goldDiffChart(@js(['minutes' => $lane['minutes'], 'diff' => $lane['diff'], 'name' => $p['name'], 'opponent' => $p['opponent']['name']]))"
                                     class="relative mt-3 h-56 rounded-xl bg-slate-950/60 p-2 sm:h-64">
                                    <canvas x-ref="canvas" role="img" aria-label="Selisih gold {{ $p['name'] }} dan {{ $p['opponent']['name'] }} tiap menit"></canvas>
                                </div>
                            </template>
                            <p class="mt-1 text-xs text-slate-500">Di atas garis nol = {{ $p['name'] }} unggul gold, di bawah = {{ $p['opponent']['name'] }} unggul. Data kill dan objektif tidak disimpan, jadi grafik hanya menampilkan selisih gold.</p>
                        @else
                            <p class="mt-3 text-sm text-slate-400">Selisih gold per menit belum tersedia (timeline match ini belum diambil).</p>
                        @endif
                    @else
                        <p class="mt-4 border-t border-slate-800 pt-4 text-sm text-slate-400">Tidak ada lawan di lane yang sama di mode ini, jadi hanya angka {{ $p['name'] }} yang ditampilkan.</p>
                    @endif

                    {{-- Numbers --}}
                    <div class="mt-4 grid gap-3 sm:grid-cols-3">
                        @foreach ($p['cards'] as $card)
                            @php
                                $total = max(($card['me'] ?? 0) + ($card['opponent'] ?? 0), 1);
                            @endphp
                            <div class="rounded-xl bg-slate-950/60 p-3">
                                <p class="text-xs text-slate-400">{{ $card['label'] }}</p>
                                <p class="mt-1 flex items-baseline justify-between tabular-nums">
                                    <span class="text-xl font-bold text-white">{{ number_format($card['me'], 0, '.', ',') }}</span>
                                    @if ($card['opponent'] !== null)
                                        <span class="text-sm text-slate-400">{{ number_format($card['opponent'], 0, '.', ',') }}</span>
                                    @endif
                                </p>
                                @if ($card['opponent'] !== null)
                                    <div class="mt-2 flex h-1.5 overflow-hidden rounded-full" aria-hidden="true">
                                        <span class="bg-sky-500" style="width: {{ $card['me'] / $total * 100 }}%"></span>
                                        <span class="bg-rose-500" style="width: {{ $card['opponent'] / $total * 100 }}%"></span>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    {{-- Score --}}
                    <div class="mt-3 rounded-xl bg-slate-950/60 p-4">
                        <p class="text-xs uppercase tracking-widest text-slate-400">Game score</p>
                        <p class="text-5xl font-extrabold tabular-nums text-white">{{ $p['score'] }}</p>
                        <p class="text-sm text-slate-400">{{ $ordinal($p['place']) }} in lobby</p>
                    </div>
                </div>
            @endforeach

            {{-- Other tracked players of this game --}}
            @if (count($performance) > 1)
                <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-800 pt-4">
                    <span class="text-xs font-bold uppercase tracking-widest text-slate-500">Also in this game</span>
                    @foreach ($performance as $p)
                        <button type="button" @click="perfId = {{ $p['id'] }}"
                                :aria-pressed="perfId === {{ $p['id'] }}"
                                :class="perfId === {{ $p['id'] }} ? 'border-violet-400 bg-violet-500/20 text-white' : 'border-slate-700 bg-slate-800/60 text-slate-300 hover:bg-slate-800'"
                                class="inline-flex items-center gap-2 rounded-full border py-1 pl-1 pr-3 text-sm font-medium outline-none transition focus-visible:ring-2 focus-visible:ring-violet-400">
                            <x-champion-icon :name="$p['champion_name']" :id="$p['champion_id']" size="size-6" class="!rounded-full" />
                            {{ $p['name'] }}
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
