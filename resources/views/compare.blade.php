@extends('layouts.app')

@section('title', 'Compare · '.config('app.name'))

@section('content')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-white">Compare</h1>
            <p class="mt-1 text-sm text-slate-400">Pilih {{ $min }} sampai {{ $max }} pemain. Data: {{ $queueLabel }}.</p>
        </div>

        <x-queue-toggle :queue="$queue" :options="$queueOptions" all />
    </div>

    {{-- Player picker. Submits as /compare?players=1,2,3&queue=solo --}}
    @if ($available->isEmpty())
        <p class="mt-6 rounded-xl border border-dashed border-slate-700 p-8 text-center text-sm text-slate-400">
            Belum ada pemain. Tambahkan lewat <code class="text-sky-300">php artisan players:add</code>.
        </p>
    @else
        <form method="GET" action="{{ route('compare') }}"
              x-data="{ selected: @js($selectedIds), max: {{ $max }}, min: {{ $min }} }"
              class="mt-5 rounded-xl border border-slate-800 bg-slate-900/50 p-4">
            <input type="hidden" name="queue" value="{{ $queueParam }}">
            <input type="hidden" name="players" :value="selected.join(',')">

            <fieldset>
                <legend class="sr-only">Pemain yang dibandingkan</legend>
                <div class="flex flex-wrap gap-2">
                    @foreach ($available as $candidate)
                        <label class="cursor-pointer">
                            {{-- .number keeps the ids numeric; the Number() map guards the server-rendered initial values. --}}
                            <input type="checkbox" class="peer sr-only" :value="{{ $candidate->id }}" x-model.number="selected"
                                   :disabled="selected.length >= max && ! selected.map(Number).includes({{ $candidate->id }})">
                            <span class="inline-flex items-center gap-2 rounded-full border border-slate-700 px-3 py-1.5 text-sm text-slate-300 transition peer-checked:border-sky-400 peer-checked:bg-sky-500/15 peer-checked:text-sky-100 peer-focus-visible:ring-2 peer-focus-visible:ring-sky-400 peer-disabled:cursor-not-allowed peer-disabled:opacity-40">
                                <span class="size-2.5 rounded-full" style="background-color: {{ $colors[$candidate->id] }}" aria-hidden="true"></span>
                                {{ $candidate->name }}
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="mt-4 flex items-center gap-3">
                <button type="submit" :disabled="selected.length < min"
                        class="rounded-lg bg-sky-500 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-sky-400 disabled:cursor-not-allowed disabled:opacity-40">
                    Bandingkan
                </button>
                <span class="text-sm text-slate-400" x-text="selected.length + ' dari ' + max + ' dipilih'">{{ count($selectedIds) }} dari {{ $max }} dipilih</span>
            </div>
        </form>
    @endif

    @if ($tooMany)
        <p class="mt-4 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-200">
            Maksimal {{ $max }} pemain. Hanya {{ $max }} pemain pertama yang dibandingkan.
        </p>
    @endif

    @if (! $ready)
        @if ($available->isNotEmpty())
            <p class="mt-6 rounded-xl border border-dashed border-slate-700 p-8 text-center text-sm text-slate-400">
                Pilih minimal {{ $min }} pemain lalu tekan "Bandingkan".
            </p>
        @endif
    @else
        {{-- LP over time (only Solo/Duo and Flex have LP) --}}
        <section class="mt-6" aria-labelledby="lp-heading">
            <h2 id="lp-heading" class="mb-3 text-lg font-semibold text-white">LP dari waktu ke waktu</h2>
            <div class="rounded-xl border border-slate-800 bg-slate-900/50 p-4">
                @if ($lpChart === null)
                    <p class="py-8 text-center text-sm text-slate-500">Grafik LP hanya ada untuk Solo/Duo dan Flex. Pilih salah satunya di atas untuk melihatnya.</p>
                @elseif (collect($lpChart['datasets'])->contains(fn ($dataset) => count($dataset['points']) > 0))
                    <div x-data="lpChart(@js($lpChart))" class="relative h-72 sm:h-96">
                        <canvas x-ref="canvas" role="img" aria-label="Grafik LP semua pemain yang dibandingkan"></canvas>
                    </div>
                @else
                    <p class="py-10 text-center text-sm text-slate-500">Belum ada snapshot LP untuk {{ $rankQueue->label() }}.</p>
                @endif
            </div>
        </section>

        {{-- Radar + numbers --}}
        <section class="mt-6 grid gap-4 xl:grid-cols-2" aria-labelledby="radar-heading">
            <div class="rounded-xl border border-slate-800 bg-slate-900/50 p-4">
                <h2 id="radar-heading" class="mb-1 text-lg font-semibold text-white">Radar performa</h2>
                <p class="mb-3 text-xs text-slate-500">Setiap sumbu relatif terhadap yang terbaik di grup ini (100). Arahkan kursor untuk nilai asli.</p>
                <div x-data="radarChart(@js($radar))" class="relative mx-auto h-80 max-w-md">
                    <canvas x-ref="canvas" role="img" aria-label="Radar performa pemain"></canvas>
                </div>
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-900/50">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-slate-800 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Pemain</th>
                            <th scope="col" class="px-2 py-3 text-right font-medium">Game</th>
                            @foreach ($metrics as $label)
                                <th scope="col" class="px-2 py-3 text-right font-medium last:pr-4">{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($players as $comparePlayer)
                            @php
                                $row = $stats[$comparePlayer->id];
                            @endphp
                            <tr class="border-b border-slate-800/70 last:border-0">
                                <th scope="row" class="whitespace-nowrap px-4 py-3 font-medium text-white">
                                    <span class="mr-2 inline-block size-2.5 rounded-full" style="background-color: {{ $colors[$comparePlayer->id] ?? '#94a3b8' }}" aria-hidden="true"></span>
                                    <a href="{{ route('players.show', ['player' => $comparePlayer, 'queue' => $queueParam]) }}" class="hover:text-sky-300">{{ $comparePlayer->name }}</a>
                                </th>
                                <td class="px-2 py-3 text-right tabular-nums text-slate-400">{{ $row['games'] }}</td>
                                @foreach ($metrics as $key => $label)
                                    <td class="px-2 py-3 text-right tabular-nums text-slate-200 last:pr-4">
                                        {{ \App\Support\Format::number($row[$key], in_array($key, ['kda', 'cs_per_min', 'vision_per_min'], true) ? 2 : ($key === 'winrate' ? 1 : 0)) }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Duo (same team) and head-to-head (opposing teams) records for every pair --}}
        <section class="mt-6" aria-labelledby="pairs-heading">
            <h2 id="pairs-heading" class="mb-1 text-lg font-semibold text-white">Duo dan head-to-head</h2>
            <p class="mb-3 text-xs text-slate-500">
                Hanya match yang diikuti kedua pemain. Satu tim = rekor saat main bersama. Lawan = rekor saat berada di tim berlawanan.
            </p>

            <div class="overflow-hidden rounded-xl border border-slate-800 bg-slate-900/50">
                <div class="hidden grid-cols-3 gap-4 border-b border-slate-800 px-4 py-2.5 text-xs uppercase tracking-wide text-slate-500 sm:grid">
                    <span>Pasangan</span>
                    <span>Satu tim</span>
                    <span>Lawan</span>
                </div>

                <ul class="divide-y divide-slate-800/70">
                    @foreach ($pairs as $pair)
                        @php
                            $first = $playersById[$pair['a']];
                            $second = $playersById[$pair['b']];
                        @endphp
                        <li class="grid gap-2 px-4 py-3 sm:grid-cols-3 sm:items-center sm:gap-4">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 font-medium text-white">
                                <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $colors[$first->id] ?? '#94a3b8' }}" aria-hidden="true"></span>
                                {{ $first->name }}
                                <span class="text-slate-600">&amp;</span>
                                <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $colors[$second->id] ?? '#94a3b8' }}" aria-hidden="true"></span>
                                {{ $second->name }}
                            </div>

                            <div>
                                <p class="text-xs uppercase tracking-wide text-slate-500 sm:hidden">Satu tim</p>
                                <x-pair-record :record="$pair['together']" />
                            </div>

                            <div>
                                <p class="text-xs uppercase tracking-wide text-slate-500 sm:hidden">Lawan</p>
                                @if ($pair['versus']['games'] === 0)
                                    <span class="text-slate-600">Belum pernah</span>
                                @else
                                    <span class="tabular-nums text-slate-200">
                                        {{ $first->name }} <span class="font-semibold text-white">{{ $pair['versus']['wins'] }}</span>
                                        <span class="text-slate-600">–</span>
                                        <span class="font-semibold text-white">{{ $pair['versus']['losses'] }}</span> {{ $second->name }}
                                    </span>
                                    <span class="text-xs tabular-nums text-slate-500">({{ $pair['versus']['games'] }} game)</span>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>

        {{-- One bar chart per metric (the scales differ too much for a single chart) --}}
        <section class="mt-6" aria-labelledby="bars-heading">
            <h2 id="bars-heading" class="mb-3 text-lg font-semibold text-white">Perbandingan per metrik</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($bars as $bar)
                    <div class="rounded-xl border border-slate-800 bg-slate-900/50 p-4">
                        <h3 class="mb-2 text-sm font-medium text-slate-300">{{ $bar['label'] }}</h3>
                        <div x-data="barChart(@js($bar))" class="relative h-48">
                            <canvas x-ref="canvas" role="img" aria-label="Grafik batang {{ $bar['label'] }}"></canvas>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
@endsection
