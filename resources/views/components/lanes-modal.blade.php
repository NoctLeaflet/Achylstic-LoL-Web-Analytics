@props(['lanes'])

{{--
    "Lanes" pop-up: the two players of each lane with their scores, a bar that leans towards the
    better one (the difference in points), and the categories each of them won. It lives inside the
    Alpine scope of the match page, which owns "lanesOpen".
--}}
<div x-show="lanesOpen" x-cloak
     x-transition:enter="transition duration-200 ease-out"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition duration-150 ease-in"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     @keydown.escape.window="lanesOpen = false"
     @click.self="lanesOpen = false"
     class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/80 backdrop-blur-sm sm:items-center sm:p-6"
     role="dialog" aria-modal="true" aria-labelledby="lanes-title">

    <div class="relative flex max-h-[94vh] w-full flex-col overflow-hidden rounded-t-2xl border border-slate-700 bg-slate-900 shadow-2xl shadow-black/50 sm:max-w-4xl sm:rounded-2xl">
        <div class="flex items-start justify-between gap-3 border-b border-slate-800 px-4 pb-3 pt-4 sm:px-6">
            <div>
                <h2 id="lanes-title" class="text-lg font-semibold text-white">Lanes</h2>
                <p class="text-xs text-slate-500">Tiap lane melawan lane yang sama di tim lawan. Batang condong ke pemain dengan skor lebih tinggi.</p>
            </div>
            <button type="button" @click="lanesOpen = false" aria-label="Tutup"
                    class="shrink-0 rounded-lg border border-slate-700 p-1.5 text-slate-400 outline-none transition hover:border-slate-500 hover:text-white focus-visible:ring-2 focus-visible:ring-sky-400">
                <svg class="size-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M5 5l10 10M15 5L5 15" /></svg>
            </button>
        </div>

        <div class="overflow-y-auto">
            @foreach ($lanes as $lane)
                <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-2 border-b border-slate-800/70 bg-gradient-to-r from-sky-500/5 via-transparent to-rose-500/5 px-3 py-3 last:border-0 sm:grid-cols-[1fr_minmax(8rem,16rem)_1fr] sm:gap-4 sm:px-5">
                    {{-- Blue side --}}
                    <div class="flex min-w-0 items-center gap-2 sm:gap-3">
                        <span @class([
                            'flex size-9 shrink-0 items-center justify-center rounded-lg text-sm font-extrabold tabular-nums sm:size-11 sm:text-base',
                            'bg-amber-400/15 text-amber-300 ring-1 ring-amber-400/50' => $lane['blue']['badge'] === 'MVP',
                            'bg-slate-800 text-white' => $lane['leader'] === 'blue' && $lane['blue']['badge'] !== 'MVP',
                            'bg-slate-800/60 text-slate-400' => $lane['leader'] !== 'blue' && $lane['blue']['badge'] !== 'MVP',
                        ])>{{ $lane['blue']['score'] }}</span>
                        <x-champion-icon :name="$lane['blue']['champion_name']" :id="$lane['blue']['champion_id']" size="size-9 sm:size-11" class="ring-2 !ring-sky-400/70" />
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-white">{{ $lane['blue']['name'] }}</p>
                            <p class="truncate text-xs text-slate-400">
                                @if ($lane['blue_wins'])
                                    Won <span class="font-semibold text-violet-300">{{ implode(' · ', $lane['blue_wins']) }}</span>
                                @else
                                    No stat wins
                                @endif
                            </p>
                        </div>
                    </div>

                    {{-- Bar --}}
                    <div class="w-24 text-center sm:w-auto">
                        <p class="text-[0.65rem] font-bold uppercase tracking-widest text-slate-400">{{ $lane['label'] }}</p>
                        <div class="relative mt-1.5 h-2 rounded-full bg-slate-800" role="img"
                             aria-label="{{ $lane['label'] }}: {{ $lane['leader'] === null ? 'seimbang' : ($lane['leader'] === 'blue' ? $lane['blue']['name'] : $lane['red']['name']).' unggul '.abs($lane['diff']).' poin' }}">
                            <span class="absolute inset-y-0 left-1/2 w-px bg-slate-600" aria-hidden="true"></span>
                            @if ($lane['leader'] === 'blue')
                                <span class="absolute inset-y-0 right-1/2 rounded-l-full bg-gradient-to-l from-sky-400 to-indigo-500" style="width: {{ $lane['width'] / 2 }}%"></span>
                            @elseif ($lane['leader'] === 'red')
                                <span class="absolute inset-y-0 left-1/2 rounded-r-full bg-gradient-to-r from-rose-400 to-fuchsia-500" style="width: {{ $lane['width'] / 2 }}%"></span>
                            @else
                                <span class="absolute left-1/2 top-1/2 size-2 -translate-x-1/2 -translate-y-1/2 rounded-full bg-slate-400"></span>
                            @endif
                        </div>
                        <p class="mt-1 text-xs font-semibold tabular-nums text-slate-300">
                            @if ($lane['leader'] === 'blue')
                                &blacktriangleleft; +{{ abs($lane['diff']) }}
                            @elseif ($lane['leader'] === 'red')
                                +{{ abs($lane['diff']) }} &blacktriangleright;
                            @else
                                Even
                            @endif
                        </p>
                    </div>

                    {{-- Red side --}}
                    <div class="flex min-w-0 flex-row-reverse items-center gap-2 text-right sm:gap-3">
                        <span @class([
                            'flex size-9 shrink-0 items-center justify-center rounded-lg text-sm font-extrabold tabular-nums sm:size-11 sm:text-base',
                            'bg-amber-400/15 text-amber-300 ring-1 ring-amber-400/50' => $lane['red']['badge'] === 'MVP',
                            'bg-slate-800 text-white' => $lane['leader'] === 'red' && $lane['red']['badge'] !== 'MVP',
                            'bg-slate-800/60 text-slate-400' => $lane['leader'] !== 'red' && $lane['red']['badge'] !== 'MVP',
                        ])>{{ $lane['red']['score'] }}</span>
                        <x-champion-icon :name="$lane['red']['champion_name']" :id="$lane['red']['champion_id']" size="size-9 sm:size-11" class="ring-2 !ring-rose-400/70" />
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-white">{{ $lane['red']['name'] }}</p>
                            <p class="truncate text-xs text-slate-400">
                                @if ($lane['red_wins'])
                                    Won <span class="font-semibold text-violet-300">{{ implode(' · ', $lane['red_wins']) }}</span>
                                @else
                                    No stat wins
                                @endif
                            </p>
                        </div>
                    </div>
                </div>
            @endforeach

            <p class="px-5 py-3 text-center text-xs text-slate-500">KP = kill participation, DMG = damage per menit. Kategori dihitung dari enam angka: KDA, KP, DMG, Gold, CS, dan Vision per menit.</p>
        </div>
    </div>
</div>
