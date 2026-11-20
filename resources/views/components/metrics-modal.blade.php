@props(['metrics'])

{{--
    Pop-up with a line chart of gold, damage, exp or cs of all ten players after every minute.
    It lives inside the Alpine scope of the match page, which owns "metricsOpen". The chart is
    only created while the pop-up is open (x-if). Clicking a champion icon shows or hides that
    player's line; the colours say which team a player is on (blue-ish = blue team).
--}}
<div x-show="metricsOpen" x-cloak
     x-transition:enter="transition duration-200 ease-out"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition duration-150 ease-in"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     @keydown.escape.window="metricsOpen = false"
     @click.self="metricsOpen = false"
     class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/80 backdrop-blur-sm sm:items-center sm:p-6"
     role="dialog" aria-modal="true" aria-labelledby="metrics-title">

    <div class="relative flex max-h-[94vh] w-full flex-col overflow-hidden rounded-t-2xl border border-slate-700 bg-slate-900 shadow-2xl shadow-black/50 sm:max-w-4xl sm:rounded-2xl">
        <template x-if="metricsOpen">
            <div x-data="metricsChart(@js($metrics))" class="flex min-h-0 flex-col">
                <div class="border-b border-slate-800 px-4 pb-3 pt-4 sm:px-6">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 id="metrics-title" class="text-lg font-semibold text-white">Metrics</h2>
                            <p class="text-xs text-slate-500">Perkembangan semua pemain tiap menit. Klik ikon champion untuk menampilkan atau menyembunyikan garisnya.</p>
                        </div>
                        <button type="button" @click="metricsOpen = false" aria-label="Tutup"
                                class="shrink-0 rounded-lg border border-slate-700 p-1.5 text-slate-400 outline-none transition hover:border-slate-500 hover:text-white focus-visible:ring-2 focus-visible:ring-sky-400">
                            <svg class="size-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M5 5l10 10M15 5L5 15" /></svg>
                        </button>
                    </div>

                    <div role="tablist" aria-label="Pilih metrik" class="mt-3 flex gap-1 overflow-x-auto pb-1">
                        <template x-for="(label, key) in metrics" :key="key">
                            <button type="button" role="tab" @click="select(key)"
                                    :aria-selected="metric === key"
                                    :class="metric === key ? 'bg-indigo-500/20 text-indigo-200' : 'text-slate-400 hover:text-slate-200'"
                                    class="shrink-0 rounded-md px-4 py-1.5 text-sm font-medium outline-none transition focus-visible:ring-2 focus-visible:ring-sky-400"
                                    x-text="label"></button>
                        </template>
                    </div>
                </div>

                <div class="overflow-y-auto px-4 py-4 sm:px-6">
                    {{-- Champion toggles: blue team left, red team right --}}
                    <div class="flex flex-wrap justify-between gap-x-6 gap-y-2">
                        @foreach (['blue', 'red'] as $side)
                            <div @class(['flex flex-wrap gap-1.5', 'justify-end' => $side === 'red'])>
                                @foreach (collect($metrics['players'])->where('team', $side) as $player)
                                    <button type="button" @click="toggle({{ $player['id'] }})"
                                            :aria-pressed="! hidden.includes({{ $player['id'] }})"
                                            :class="hidden.includes({{ $player['id'] }}) ? 'opacity-30 grayscale' : ''"
                                            title="{{ $player['name'] }} ({{ $player['champion_name'] }})"
                                            class="rounded-lg outline-none transition focus-visible:ring-2 focus-visible:ring-sky-400">
                                        <x-champion-icon :name="$player['champion_name']" :id="$player['champion_id']" size="size-10"
                                                         style="box-shadow: 0 0 0 2px {{ $player['color'] }}" />
                                    </button>
                                @endforeach
                            </div>
                        @endforeach
                    </div>

                    <div class="relative mt-4 h-72 sm:h-96">
                        <canvas x-ref="canvas" role="img" aria-label="Grafik metrik per menit untuk semua pemain"></canvas>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>
