@extends('layouts.app')

@section('title', 'Admin · '.config('app.name'))

@section('content')
    @php
        $inputClass = 'mt-1.5 block w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white placeholder-slate-500 focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500';
    @endphp

    <div>
        <h1 class="text-2xl font-semibold tracking-tight text-white">Admin</h1>
        <p class="mt-1 text-sm text-slate-400">
            Kelola siapa saja yang tampil di leaderboard. Login sebagai
            <span class="font-medium text-slate-200">{{ auth('admin')->user()->username }}</span>.
        </p>
    </div>

    @if (session('status'))
        <p role="status" class="mt-5 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
            {{ session('status') }}
        </p>
    @endif

    @if ($errors->has('player'))
        <p role="alert" class="mt-5 rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
            {{ $errors->first('player') }}
        </p>
    @endif

    @if ($errors->has('display_name'))
        <p role="alert" class="mt-5 rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
            {{ $errors->first('display_name') }}
        </p>
    @endif

    @unless ($scheduler['alive'])
        <div role="alert" class="mt-5 rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 text-sm text-amber-100">
            <p class="font-semibold text-amber-200">Penjadwal (scheduler) tidak berjalan.</p>
            <p class="mt-1 text-amber-200/80">
                {{ $scheduler['last'] ? 'Terakhir aktif '.$scheduler['last']->diffForHumans().'.' : 'Belum pernah terdeteksi aktif.' }}
                Tanpa itu rank dan match tidak diperbarui otomatis, dan bot Discord tidak mendapat kabar baru.
                Jalankan <code class="rounded bg-slate-950/60 px-1">start-achylstic.bat</code> (atau
                <code class="rounded bg-slate-950/60 px-1">php artisan schedule:work</code> di terminal), dan biarkan jendelanya terbuka.
            </p>
        </div>
    @endunless

    @if ($keyRejection)
        <div role="alert" class="mt-5 rounded-xl border border-rose-500/40 bg-rose-500/10 p-4 text-sm text-rose-100">
            <p class="font-semibold text-rose-200">API key Riot ditolak (HTTP {{ $keyRejection['status'] }}), sejak {{ $keyRejection['since']->diffForHumans() }}.</p>
            <p class="mt-1 text-rose-200/80">
                Key kedaluwarsa atau tidak valid, jadi rank dan match tidak diperbarui. Ambil key baru di developer.riotgames.com,
                isi <code class="rounded bg-slate-950/60 px-1">RIOT_API_KEY</code> di file <code class="rounded bg-slate-950/60 px-1">.env</code>,
                lalu tekan Sinkron sekarang. Peringatan ini hilang sendiri setelah Riot menerima key.
            </p>
        </div>
    @endif

    @error('sync')
        <p role="alert" class="mt-5 rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">{{ $message }}</p>
    @enderror

    {{-- Sync now --}}
    <section class="mt-5 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-800 bg-slate-900/50 p-4 sm:p-5">
        <div class="min-w-0">
            <h2 class="text-lg font-semibold text-white">Sinkronisasi data</h2>
            <p class="mt-1 text-sm text-slate-400">
                @if ($sync && $sync['state'] === 'running')
                    <span class="font-medium text-amber-300">Sedang berjalan</span> sejak {{ $sync['at']->diffForHumans() }}. Muat ulang halaman ini nanti untuk melihat hasilnya.
                @elseif ($sync && $sync['state'] === 'failed')
                    <span class="font-medium text-rose-300">Gagal</span> {{ $sync['at']->diffForHumans() }}: {{ $sync['message'] }}
                @elseif ($sync)
                    <span class="font-medium text-emerald-300">Selesai</span> {{ $sync['at']->diffForHumans() }}.
                    @if ($sync['message'])
                        <span class="text-amber-300">{{ $sync['message'] }}</span>
                    @endif
                @else
                    Ambil rank, match, dan timeline terbaru dari Riot sekarang, tanpa menunggu jadwal otomatis.
                @endif
            </p>
            <p class="mt-1 text-xs text-slate-500">
                Snapshot rank terakhir: {{ $lastRankSnapshot ? \Illuminate\Support\Carbon::parse($lastRankSnapshot)->diffForHumans() : 'belum ada' }}.
                Backup database terakhir:
                @if ($backup)
                    {{ $backup['at']->diffForHumans() }} ({{ number_format($backup['size'] / 1024, 0, ',', '.') }} KB).
                @else
                    <span class="text-amber-300">belum ada</span> (jalankan <code>php artisan db:backup</code>).
                @endif
            </p>
        </div>

        <form method="POST" action="{{ route('admin.sync') }}" x-data="{ busy: false }" @submit="busy = true">
            @csrf
            <button type="submit" x-bind:disabled="busy || {{ $sync && $sync['state'] === 'running' ? 'true' : 'false' }}"
                    class="rounded-md bg-sky-500 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-sky-400 disabled:opacity-60">
                Sinkron sekarang
            </button>
        </form>
    </section>

    {{-- Add a friend --}}
    <section class="mt-5 rounded-xl border border-slate-800 bg-slate-900/50 p-4 sm:p-5">
        <h2 class="text-lg font-semibold text-white">Tambah teman</h2>
        <p class="mt-1 text-sm text-slate-400">
            Masukkan Riot ID persis seperti di game. Akunnya dicek ke Riot saat tombol ditekan.
        </p>

        <form method="POST" action="{{ route('admin.players.store') }}"
              class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[2fr_1.5fr_1.5fr_auto] lg:items-end"
              x-data="{ busy: false }" @submit="busy = true">
            @csrf

            <div>
                <label for="riot_id" class="block text-sm font-medium text-slate-300">Riot ID</label>
                <input id="riot_id" name="riot_id" type="text" value="{{ old('riot_id') }}" placeholder="Nama#1234"
                       required autocomplete="off" spellcheck="false" maxlength="60" class="{{ $inputClass }}">
            </div>

            <div>
                <label for="nickname" class="block text-sm font-medium text-slate-300">
                    Nama panggilan <span class="font-normal text-slate-500">(opsional)</span>
                </label>
                <input id="nickname" name="nickname" type="text" value="{{ old('nickname') }}" placeholder="Nama di grup"
                       autocomplete="off" maxlength="30" class="{{ $inputClass }}">
            </div>

            <div>
                <label for="platform" class="block text-sm font-medium text-slate-300">Server</label>
                <select id="platform" name="platform" class="{{ $inputClass }}">
                    @foreach ($platforms as $code => $label)
                        <option value="{{ $code }}" @selected(old('platform', $defaultPlatform) === $code)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <button type="submit" x-bind:disabled="busy"
                    class="rounded-md bg-sky-500 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-sky-400 disabled:opacity-60 sm:col-span-2 lg:col-span-1">
                <span x-show="! busy">Tambah</span>
                <span x-show="busy" x-cloak>Mengecek ke Riot…</span>
            </button>

            @if ($errors->hasAny(['riot_id', 'nickname', 'platform']))
                <div role="alert" class="space-y-1 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-200 sm:col-span-2 lg:col-span-full">
                    @foreach (['riot_id', 'nickname', 'platform'] as $field)
                        @foreach ($errors->get($field) as $message)
                            <p>{{ $message }}</p>
                        @endforeach
                    @endforeach
                </div>
            @endif
        </form>

        <p class="mt-3 text-xs leading-relaxed text-slate-500">
            Pemain baru langsung masuk daftar, tetapi rank dan match-nya baru muncul setelah sync berikutnya
            (otomatis lewat <code class="text-slate-400">php artisan schedule:work</code>), atau jalankan
            <code class="text-slate-400">php artisan riot:sync-ranks</code> dan
            <code class="text-slate-400">php artisan riot:sync-matches</code>.
        </p>
    </section>

    {{-- Current players --}}
    <section class="mt-6">
        <h2 class="text-lg font-semibold text-white">
            Pemain <span class="font-normal text-slate-500">({{ $players->count() }})</span>
        </h2>

        @if ($players->isEmpty())
            <div class="mt-3 rounded-xl border border-dashed border-slate-700 p-8 text-center">
                <p class="font-medium text-white">Belum ada pemain.</p>
                <p class="mt-2 text-sm text-slate-400">Tambahkan teman pertamamu lewat formulir di atas.</p>
            </div>
        @else
            <ul class="mt-3 divide-y divide-slate-800/70 overflow-hidden rounded-xl border border-slate-800 bg-slate-900/50">
                @foreach ($players as $player)
                    <li @class(['flex flex-col gap-3 p-4 md:flex-row md:flex-wrap md:items-center', 'opacity-60' => ! $player->is_active])>
                        <div class="flex min-w-0 flex-1 items-center gap-3">
                            <x-profile-icon :player="$player" />
                            <div class="min-w-0">
                                <a href="{{ route('players.show', $player) }}" class="block truncate font-medium text-white hover:underline">
                                    {{ $player->name }}
                                </a>
                                <p class="truncate text-xs text-slate-500">
                                    {{ $player->riot_id }} · {{ strtoupper($player->platform) }} · {{ $player->participations_count }} match
                                </p>
                            </div>
                            @unless ($player->is_active)
                                <span class="shrink-0 rounded-full bg-slate-800 px-2 py-0.5 text-xs text-slate-400">Disembunyikan</span>
                            @endunless
                        </div>

                        <form method="POST" action="{{ route('admin.players.update', $player) }}" class="flex items-center gap-2">
                            @csrf
                            @method('PATCH')
                            <label for="display_name_{{ $player->id }}" class="sr-only">Nama panggilan {{ $player->riot_id }}</label>
                            <input id="display_name_{{ $player->id }}" name="display_name" type="text"
                                   value="{{ $player->display_name }}" placeholder="Nama panggilan" maxlength="30" autocomplete="off"
                                   class="block w-full min-w-0 rounded-md border border-slate-700 bg-slate-950 px-3 py-1.5 text-sm text-white placeholder-slate-500 focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500 md:w-44">
                            <button type="submit"
                                    class="shrink-0 rounded-md border border-slate-700 px-3 py-1.5 text-sm font-medium text-slate-200 transition hover:bg-slate-800">
                                Simpan
                            </button>
                        </form>

                        <form method="POST" action="{{ route('admin.players.goal', $player) }}">
                            @csrf
                            @method('PATCH')
                            <label for="goal_{{ $player->id }}" class="sr-only">Target rank {{ $player->riot_id }}</label>
                            <select id="goal_{{ $player->id }}" name="goal" onchange="this.form.submit()"
                                    class="block w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-1.5 text-sm text-white focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500 md:w-36">
                                <option value="">Tanpa target</option>
                                @foreach ($goalOptions as $value => $label)
                                    <option value="{{ $value }}" @selected($player->goal_tier && $value === $player->goal_tier.':'.$player->goal_division)>Target {{ $label }}</option>
                                @endforeach
                            </select>
                            <noscript><button type="submit" class="mt-1 rounded-md border border-slate-700 px-2 py-1 text-xs text-slate-200">Simpan target</button></noscript>
                        </form>

                        <form method="POST" action="{{ route('admin.players.toggle', $player) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit"
                                    @class([
                                        'w-full rounded-md border px-3 py-1.5 text-sm font-medium transition md:w-auto',
                                        'border-slate-700 text-slate-300 hover:border-rose-500/50 hover:text-rose-300' => $player->is_active,
                                        'border-emerald-500/40 text-emerald-300 hover:bg-emerald-500/10' => ! $player->is_active,
                                    ])>
                                {{ $player->is_active ? 'Sembunyikan' : 'Tampilkan' }}
                            </button>
                        </form>

                        {{-- Less common actions, kept out of the way --}}
                        <details class="w-full basis-full text-sm">
                            <summary class="cursor-pointer text-xs text-slate-500 hover:text-slate-300">Lainnya (server, Riot ID, hapus)</summary>
                            <div class="mt-2 flex flex-wrap items-center gap-3 rounded-lg border border-slate-800 bg-slate-950/40 p-3">
                                <form method="POST" action="{{ route('admin.players.platform', $player) }}" class="flex items-center gap-2">
                                    @csrf
                                    @method('PATCH')
                                    <label for="platform_{{ $player->id }}" class="text-xs text-slate-400">Server</label>
                                    <select id="platform_{{ $player->id }}" name="platform" onchange="this.form.submit()"
                                            class="rounded-md border border-slate-700 bg-slate-950 px-2 py-1 text-sm text-white focus:border-sky-500 focus:outline-none">
                                        @foreach ($platforms as $code => $label)
                                            <option value="{{ $code }}" @selected($player->platform === $code)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </form>

                                <form method="POST" action="{{ route('admin.players.refresh', $player) }}">
                                    @csrf
                                    <button type="submit" class="rounded-md border border-slate-700 px-3 py-1 text-xs font-medium text-slate-200 hover:bg-slate-800">
                                        Segarkan Riot ID dari Riot
                                    </button>
                                </form>

                                <form method="POST" action="{{ route('admin.players.destroy', $player) }}"
                                      onsubmit="return confirm('Hapus {{ e($player->name) }} secara permanen? Riwayat rank, pencapaian, dan target ikut hilang. Match tetap tersimpan. Ini tidak bisa dibatalkan.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rounded-md border border-rose-500/40 px-3 py-1 text-xs font-medium text-rose-300 hover:bg-rose-500/10">
                                        Hapus permanen
                                    </button>
                                </form>
                            </div>
                        </details>
                    </li>
                @endforeach
            </ul>

            <p class="mt-3 text-xs text-slate-500">
                Pemain yang disembunyikan hilang dari leaderboard dan Compare, dan tidak di-sync lagi. Datanya tetap tersimpan.
            </p>
        @endif
    </section>
@endsection
