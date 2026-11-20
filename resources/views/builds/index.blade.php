@extends('layouts.app')

@section('title', 'Build member · '.config('app.name'))
@section('description', 'Rune dan item yang dipakai member grup di tiap champion, lengkap dengan winrate-nya.')

@section('content')
    @php
        $select = 'rounded-md border border-slate-700 bg-slate-950 px-3 py-1.5 text-sm text-white focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500';
    @endphp

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-white">Build member</h1>
            <p class="mt-1 text-sm text-slate-400">
                Pilih champion untuk melihat rune, item, dan urutan skill yang dipakai
                {{ $member ? $member->name : 'member grup' }}{{ $mode ? ' di '.$mode->label() : '' }}, beserta winrate-nya.
            </p>
        </div>

        <form method="GET" action="{{ route('builds') }}" class="flex flex-wrap gap-2">
            <label for="b-player" class="sr-only">Member</label>
            <select id="b-player" name="player" onchange="this.form.submit()" class="{{ $select }}">
                <option value="">Semua member</option>
                @foreach ($members as $m)
                    <option value="{{ $m->id }}" @selected($member?->id === $m->id)>{{ $m->name }}</option>
                @endforeach
            </select>
            <label for="b-mode" class="sr-only">Mode game</label>
            <select id="b-mode" name="mode" onchange="this.form.submit()" class="{{ $select }}">
                <option value="">Semua mode</option>
                @foreach ($modes as $m)
                    <option value="{{ $m->slug() }}" @selected($mode === $m)>{{ $m->label() }}</option>
                @endforeach
            </select>
            <noscript><button type="submit" class="rounded-md border border-slate-700 px-3 py-1.5 text-sm text-slate-200">Terapkan</button></noscript>
        </form>
    </div>

    @if (session('notice'))
        <p role="status" class="mt-3 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-200">{{ session('notice') }}</p>
    @endif

    <p class="mt-3 rounded-lg border border-slate-800 bg-slate-900/40 px-3 py-2 text-xs text-slate-500">
        Datanya hanya dari game member grup, jadi jumlah sampelnya kecil. Itu bukan statistik global; anggap sebagai gambaran
        kebiasaan kalian sendiri.
    </p>

    @if (count($champions) === 0)
        <p class="mt-6 rounded-xl border border-dashed border-slate-700 p-8 text-center text-sm text-slate-400">Belum ada game untuk pilihan ini.</p>
    @else
        <ul class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($champions as $champion)
                <li>
                    <a href="{{ route('builds.show', array_filter(['championId' => $champion['champion_id'], 'player' => $member?->id, 'mode' => $mode?->slug()])) }}"
                       class="flex items-center gap-3 rounded-xl border border-slate-800 bg-slate-900/50 p-3 transition hover:bg-slate-800/60">
                        <x-champion-icon :name="$champion['name']" :id="$champion['champion_id']" size="size-12" />
                        <span class="min-w-0">
                            <span class="block truncate font-semibold text-white">{{ $champion['name'] }}</span>
                            <span class="block text-xs tabular-nums text-slate-400">
                                {{ $champion['games'] }} game ·
                                <span @class(['font-semibold', 'text-emerald-400' => $champion['winrate'] >= 50, 'text-rose-400' => $champion['winrate'] < 50])>{{ $champion['winrate'] }}%</span>
                            </span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
