<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>@yield('title', config('app.name'))</title>

    {{-- Link previews (Discord, WhatsApp, social media). Pages can set @section('description'). --}}
    @php
        $metaDescription = trim($__env->yieldContent('description')) ?: 'Pantau rank, bandingkan performa, dan lihat detail match League of Legends untuk satu grup teman: leaderboard, grafik LP, duo, dan banyak lagi.';
        $metaTitle = trim($__env->yieldContent('title')) ?: config('app.name');
    @endphp
    <meta name="description" content="{{ $metaDescription }}">
    <meta name="theme-color" content="#020617">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:title" content="{{ $metaTitle }}">
    <meta property="og:description" content="{{ $metaDescription }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ asset('og-image.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ config('app.name') }}, League of Legends Analysis">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('icon-192.png') }}" sizes="192x192">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen flex-col">
    <header class="sticky top-0 z-10 border-b border-slate-800 bg-slate-900/80 backdrop-blur">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3">
            <a href="{{ route('home') }}" class="flex items-center gap-2.5 text-lg font-semibold tracking-tight text-white">
                <img src="{{ asset('icon-192.png') }}" alt="" width="32" height="32" class="size-8 rounded-lg">
                {{ config('app.name') }}
            </a>

            <nav class="flex flex-wrap items-center gap-1 text-sm" aria-label="Navigasi utama">
                <a href="{{ route('leaderboard') }}"
                   @class([
                       'rounded-md px-2 py-1.5 font-medium transition sm:px-3',
                       'bg-slate-800 text-white' => request()->routeIs('leaderboard'),
                       'text-slate-400 hover:text-slate-200' => ! request()->routeIs('leaderboard'),
                   ])>Leaderboard</a>
                <a href="{{ route('matches.index') }}"
                   @class([
                       'rounded-md px-2 py-1.5 font-medium transition sm:px-3',
                       'bg-slate-800 text-white' => request()->routeIs('matches.index'),
                       'text-slate-400 hover:text-slate-200' => ! request()->routeIs('matches.index'),
                   ])>Matches</a>
                <a href="{{ route('duos') }}"
                   @class([
                       'rounded-md px-2 py-1.5 font-medium transition sm:px-3',
                       'bg-slate-800 text-white' => request()->routeIs('duos'),
                       'text-slate-400 hover:text-slate-200' => ! request()->routeIs('duos'),
                   ])>Duo</a>
                <a href="{{ route('champions') }}"
                   @class([
                       'rounded-md px-2 py-1.5 font-medium transition sm:px-3',
                       'bg-slate-800 text-white' => request()->routeIs('champions'),
                       'text-slate-400 hover:text-slate-200' => ! request()->routeIs('champions'),
                   ])>Champion</a>
                <a href="{{ route('compare') }}"
                   @class([
                       'rounded-md px-2 py-1.5 font-medium transition sm:px-3',
                       'bg-slate-800 text-white' => request()->routeIs('compare'),
                       'text-slate-400 hover:text-slate-200' => ! request()->routeIs('compare'),
                   ])>Compare</a>

                <span class="mx-1 h-5 w-px bg-slate-800" aria-hidden="true"></span>

                @auth('admin')
                    <a href="{{ route('admin.dashboard') }}"
                       @class([
                           'rounded-md px-2 py-1.5 font-medium transition sm:px-3',
                           'bg-slate-800 text-white' => request()->routeIs('admin.*'),
                           'text-slate-400 hover:text-slate-200' => ! request()->routeIs('admin.*'),
                       ])>Admin</a>
                    <form method="POST" action="{{ route('admin.logout') }}">
                        @csrf
                        <button type="submit"
                                class="rounded-md px-2 py-1.5 font-medium text-slate-400 transition hover:text-rose-300 sm:px-3">Keluar</button>
                    </form>
                @else
                    <a href="{{ route('admin.login') }}" aria-label="Login Admin"
                       @class([
                           'inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 font-medium transition sm:px-3',
                           'border-sky-400 bg-sky-500/20 text-sky-200' => request()->routeIs('admin.login'),
                           'border-sky-500/40 bg-sky-500/10 text-sky-300 hover:bg-sky-500/20' => ! request()->routeIs('admin.login'),
                       ])>
                        <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1Zm3 8V5.5a3 3 0 1 0-6 0V9h6Z" clip-rule="evenodd" />
                        </svg>
                        <span><span class="hidden sm:inline">Login </span>Admin</span>
                    </a>
                @endauth
            </nav>
        </div>
    </header>

    <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-6">
        @yield('content')
    </main>

    <footer class="border-t border-slate-800 px-4 py-6 text-center text-xs leading-relaxed text-slate-500">
        <p>Data dari Riot Games API, aset dari Data Dragon.</p>
        <p class="mt-1">
            {{ config('app.name') }} isn't endorsed by Riot Games and doesn't reflect the views or opinions of
            Riot Games or anyone officially involved in producing or managing Riot Games properties.
        </p>
    </footer>
</body>
</html>
