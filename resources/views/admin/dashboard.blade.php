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

    @if ($errors->has('display_name'))
        <p role="alert" class="mt-5 rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
            {{ $errors->first('display_name') }}
        </p>
    @endif

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
                    <li @class(['flex flex-col gap-3 p-4 md:flex-row md:items-center', 'opacity-60' => ! $player->is_active])>
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
                    </li>
                @endforeach
            </ul>

            <p class="mt-3 text-xs text-slate-500">
                Pemain yang disembunyikan hilang dari leaderboard dan Compare, dan tidak di-sync lagi. Datanya tetap tersimpan.
            </p>
        @endif
    </section>
@endsection
