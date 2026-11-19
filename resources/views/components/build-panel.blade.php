@props(['participant', 'hasMatchup' => false])

@php
    $build = $participant['build'];
    $skills = $build['skills'];
    $runes = $build['runes'];

    // Full class names on purpose: Tailwind only generates classes it can find in the source.
    $skillColors = ['Q' => 'bg-rose-500', 'W' => 'bg-violet-500', 'E' => 'bg-sky-500', 'R' => 'bg-amber-500'];
    $skillText = ['Q' => 'text-rose-300', 'W' => 'text-violet-300', 'E' => 'text-sky-300', 'R' => 'text-amber-300'];
@endphp

{{-- The build of one player: items over time, skill order and runes. Shown when the player is clicked in the scoreboard. --}}
<div class="rounded-xl border border-slate-700 bg-slate-900 p-4 sm:p-5">
    <div class="flex items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3">
            <x-champion-icon :name="$participant['champion_name']" :id="$participant['champion_id']" size="size-12" />
            <div class="min-w-0">
                <p class="truncate text-base font-semibold text-white">{{ $participant['name'] }}</p>
                <p class="truncate text-sm text-slate-400">
                    {{ $participant['champion_name'] }}
                    <span class="text-slate-600">·</span>
                    Skor <span class="font-semibold text-slate-200">{{ $participant['score'] }}</span>
                    @if ($participant['badge'])
                        <x-score-badge :badge="$participant['badge']" />
                    @endif
                </p>
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            @if ($hasMatchup)
                <button type="button" @click="openMatchup('{{ $participant['position'] }}', $event)"
                        class="inline-flex items-center gap-1.5 rounded-md border border-sky-500/40 bg-sky-500/10 px-2.5 py-1 text-xs font-medium text-sky-300 hover:bg-sky-500/20">
                    <svg class="size-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true"><path d="M10 2l7 4v8l-7 4-7-4V6z" /><path d="M10 6l3.5 2v4L10 14l-3.5-2V8z" /></svg>
                    <span class="hidden sm:inline">Compare dengan lawan lane</span>
                    <span class="sm:hidden">Compare</span>
                </button>
            @endif
            <button type="button" @click="open = null"
                    class="rounded-md border border-slate-700 px-2.5 py-1 text-xs font-medium text-slate-300 hover:border-slate-500 hover:text-white">
                Tutup
            </button>
        </div>
    </div>

    {{-- Items --}}
    <section class="mt-5" aria-label="Urutan item">
        <h3 class="mb-2 text-sm font-semibold text-white">Item</h3>

        @if (! $build['has_timeline'])
            <p class="text-sm text-slate-500">
                Urutan item belum tersedia untuk match ini. Jalankan <code class="text-sky-300">php artisan riot:sync-timelines</code>.
            </p>
        @elseif ($build['items'] === [])
            <p class="text-sm text-slate-500">Tidak ada pembelian item yang tercatat.</p>
        @else
            <div class="flex flex-wrap items-start gap-x-1.5 gap-y-3">
                @foreach ($build['items'] as $group)
                    <div class="flex items-start gap-1.5">
                        <div class="flex flex-col items-center gap-1">
                            <div class="flex gap-0.5">
                                @foreach ($group['items'] as $item)
                                    <x-item-icon :id="$item['id']" size="size-8" :title="$item['name']" />
                                @endforeach
                            </div>
                            <span class="text-xs tabular-nums text-slate-500">{{ $group['minute'] }}m</span>
                        </div>
                        @unless ($loop->last)
                            <span class="mt-1.5 text-slate-600" aria-hidden="true">&rsaquo;</span>
                        @endunless
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Skill order --}}
    @if ($skills)
        <section class="mt-6" aria-label="Urutan skill">
            <h3 class="mb-2 flex flex-wrap items-center gap-2 text-sm font-semibold text-white">
                Urutan skill
                <span class="flex items-center gap-1 text-xs font-medium text-slate-500">
                    @foreach ($skills['priority'] as $key)
                        <span class="{{ $skillText[$key] }} font-semibold">{{ $key }}</span>
                        @unless ($loop->last)
                            <span aria-hidden="true">&rsaquo;</span>
                        @endunless
                    @endforeach
                </span>
            </h3>

            <div class="overflow-x-auto">
                <table class="border-separate border-spacing-0.5 text-[11px]">
                    @foreach ($skillColors as $key => $color)
                        <tr>
                            <th scope="row" class="pr-2 text-left font-semibold {{ $skillText[$key] }}">{{ $key }}</th>
                            @for ($level = 1; $level <= $skills['levels']; $level++)
                                @php
                                    $taken = in_array($level, $skills['grid'][$key], true);
                                @endphp
                                <td @class([
                                    'size-5 rounded text-center font-semibold tabular-nums',
                                    $color.' text-white' => $taken,
                                    'bg-slate-800/70' => ! $taken,
                                ])>{{ $taken ? $level : '' }}</td>
                            @endfor
                        </tr>
                    @endforeach
                </table>
            </div>
        </section>
    @endif

    {{-- Runes --}}
    <section class="mt-6" aria-label="Rune">
        <h3 class="mb-2 text-sm font-semibold text-white">Rune</h3>

        @if ($runes === null)
            <p class="text-sm text-slate-500">Data rune tidak tersedia untuk match ini.</p>
        @else
            <div class="grid gap-6 sm:grid-cols-3">
                @foreach (['primary' => 'Utama', 'secondary' => 'Sekunder'] as $slot => $label)
                    <div>
                        @if ($runes[$slot])
                            <p class="mb-2 flex items-center justify-center gap-2 text-sm font-medium text-slate-300">
                                <img src="{{ $runes[$slot]['icon'] }}" alt="" class="size-5" loading="lazy">
                                {{ $runes[$slot]['name'] }}
                                <span class="text-xs text-slate-500">({{ $label }})</span>
                            </p>
                            <div class="space-y-1.5">
                                @foreach ($runes[$slot]['rows'] as $row)
                                    <div class="flex items-center justify-center gap-2">
                                        @foreach ($row as $rune)
                                            <img src="{{ $rune['icon'] }}" alt="{{ $rune['name'] }}" title="{{ $rune['name'] }}" loading="lazy"
                                                 @class([
                                                     'rounded-full bg-slate-800',
                                                     'size-11' => $slot === 'primary' && $loop->parent->first,
                                                     'size-8' => ! ($slot === 'primary' && $loop->parent->first),
                                                     'ring-2 ring-amber-400' => $rune['selected'],
                                                     'opacity-30 grayscale' => ! $rune['selected'],
                                                 ])>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="text-center text-sm text-slate-500">Rune {{ strtolower($label) }} tidak tersedia.</p>
                        @endif
                    </div>
                @endforeach

                <div>
                    <p class="mb-2 text-center text-sm font-medium text-slate-300">Rune shard</p>
                    <ul class="space-y-1.5">
                        @foreach ($runes['shards'] as $shard)
                            <li class="flex items-center justify-center gap-2 text-sm text-slate-300">
                                @if ($shard['icon'])
                                    <img src="{{ $shard['icon'] }}" alt="" class="size-6 rounded-full bg-slate-800 ring-2 ring-amber-400" loading="lazy">
                                @endif
                                {{ $shard['name'] }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
    </section>
</div>
