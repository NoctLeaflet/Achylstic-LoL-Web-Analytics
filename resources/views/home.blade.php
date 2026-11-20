@extends('layouts.app')

@section('title', config('app.name').' · League of Legends Analysis')
@section('description', config('app.name').' memantau akun League of Legends kamu dan teman satu grup, lalu membandingkan performa lewat leaderboard, grafik LP, dan detail match.')

@section('content')
    {{-- Hero --}}
    <section class="relative overflow-hidden rounded-3xl border border-slate-800 bg-gradient-to-b from-indigo-950/60 via-slate-900/40 to-transparent px-5 py-10 text-center sm:px-10 sm:py-14">
        <div class="pointer-events-none absolute inset-x-0 -top-24 mx-auto h-72 max-w-2xl rounded-full bg-indigo-500/20 blur-3xl" aria-hidden="true"></div>

        <img src="{{ asset('images/logo.webp') }}" alt="{{ config('app.name') }} — League of Legends Analysis"
             width="1774" height="887" fetchpriority="high"
             class="relative mx-auto h-auto w-full max-w-md drop-shadow-[0_8px_40px_rgba(99,102,241,0.35)] sm:max-w-xl">

        <h1 class="relative mx-auto mt-6 max-w-2xl text-2xl font-semibold tracking-tight text-white sm:text-4xl">
            Siapa yang paling <span class="text-sky-300">carry</span> di grupmu?
        </h1>
        <p class="relative mx-auto mt-4 max-w-2xl text-base leading-relaxed text-slate-300">
            {{ config('app.name') }} memantau akun League of Legends milik kamu dan teman-teman satu grup, lalu
            membandingkan performa kalian lewat leaderboard, grafik LP, dan detail setiap match.
        </p>

        <div class="relative mt-7 flex flex-wrap items-center justify-center gap-3">
            <a href="{{ route('leaderboard') }}"
               class="rounded-lg bg-sky-500 px-5 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-sky-400">Lihat Leaderboard</a>
            <a href="{{ route('compare') }}"
               class="rounded-lg border border-slate-600 px-5 py-2.5 text-sm font-semibold text-slate-100 transition hover:bg-slate-800">Bandingkan pemain</a>
        </div>

        <dl class="relative mx-auto mt-9 grid max-w-md grid-cols-2 gap-3 text-center">
            <div class="rounded-xl border border-slate-800 bg-slate-950/60 px-4 py-3">
                <dt class="text-xs uppercase tracking-wide text-slate-500">Pemain dipantau</dt>
                <dd class="mt-1 text-2xl font-semibold tabular-nums text-white">{{ number_format($playerCount, 0, ',', '.') }}</dd>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-950/60 px-4 py-3">
                <dt class="text-xs uppercase tracking-wide text-slate-500">Match tercatat</dt>
                <dd class="mt-1 text-2xl font-semibold tabular-nums text-white">{{ number_format($matchCount, 0, ',', '.') }}</dd>
            </div>
        </dl>
    </section>

    {{-- What it does --}}
    <section class="mt-12" aria-labelledby="fitur">
        <h2 id="fitur" class="text-xl font-semibold tracking-tight text-white sm:text-2xl">Apa yang bisa kamu lihat di sini?</h2>
        <p class="mt-1 text-sm text-slate-400">Riot hanya menunjukkan rank kamu saat ini. Di sini riwayatnya disimpan, jadi semuanya bisa dibandingkan.</p>

        @php
            $features = [
                ['Leaderboard', 'Peringkat teman satu grup berdasarkan tier, divisi, dan LP, lengkap dengan winrate dan Climber of the Week.', 'M3 17l6-6 4 4 8-8M14 7h7v7'],
                ['Grafik LP', 'Naik turunnya LP dari waktu ke waktu, karena snapshot rank disimpan secara berkala.', 'M4 19V5m0 14h16M8 15l3-4 3 2 4-6'],
                ['Compare', 'Adu 2 sampai 5 pemain dengan radar chart dan grafik batang: KDA, CS, damage, vision, dan gold per menit.', 'M12 3v18M5 8l-3 7h6L5 8zm14 0l-3 7h6l-3-7zM5 8h14'],
                ['Detail match', 'Scoreboard 10 pemain dengan skor, badge MVP dan ACE, plus item, skill, dan rune tiap pemain.', 'M5 4h14v16H5zM9 8h6M9 12h6M9 16h3'],
                ['Duo & head-to-head', 'Rekor menang kalah saat satu tim dan saat berhadapan dengan teman sendiri.', 'M16 11a4 4 0 10-8 0m-4 9a8 8 0 0116 0'],
                ['Notifikasi Discord', 'Kabar match baru, naik atau turun rank, dan win streak bisa dikirim ke server Discord grup.', 'M15 17h5l-1.4-1.4A7 7 0 0018 11V8a6 6 0 10-12 0v3a7 7 0 01-.6 4.6L4 17h5m6 0a3 3 0 11-6 0'],
            ];
        @endphp
        <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($features as [$title, $text, $icon])
                <article class="rounded-xl border border-slate-800 bg-slate-900/50 p-5">
                    <span class="flex size-10 items-center justify-center rounded-lg bg-indigo-500/15 text-indigo-300">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                    </span>
                    <h3 class="mt-3 font-semibold text-white">{{ $title }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-slate-400">{{ $text }}</p>
                </article>
            @endforeach
        </div>
    </section>

    {{-- How it works --}}
    <section class="mt-12" aria-labelledby="cara-kerja">
        <h2 id="cara-kerja" class="text-xl font-semibold tracking-tight text-white sm:text-2xl">Cara kerjanya</h2>
        <ol class="mt-5 grid gap-3 sm:grid-cols-3">
            @foreach ([
                ['Admin menambah teman', 'Cukup masukkan Riot ID seperti Nama#TAG lewat halaman admin.'],
                ['Data disinkronkan otomatis', 'Rank dan match diambil dari Riot API di latar belakang, lalu disimpan.'],
                ['Lihat dan bandingkan', 'Buka leaderboard, grafik, dan detail match kapan saja, tanpa menunggu Riot.'],
            ] as $i => [$title, $text])
                <li class="rounded-xl border border-slate-800 bg-slate-900/50 p-5">
                    <span class="flex size-8 items-center justify-center rounded-full bg-sky-500/15 text-sm font-semibold text-sky-300">{{ $i + 1 }}</span>
                    <h3 class="mt-3 font-semibold text-white">{{ $title }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-slate-400">{{ $text }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- Closing call to action --}}
    <section class="mt-12 rounded-2xl border border-indigo-500/30 bg-indigo-500/10 p-6 text-center sm:p-8">
        <h2 class="text-xl font-semibold text-white">Siap melihat siapa yang di puncak?</h2>
        <a href="{{ route('leaderboard') }}"
           class="mt-4 inline-block rounded-lg bg-sky-500 px-5 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-sky-400">Buka Leaderboard</a>
    </section>
@endsection
