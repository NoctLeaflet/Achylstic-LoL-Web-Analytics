<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>@yield('title', config('app.name'))</title>

    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen flex-col">
    <header class="sticky top-0 z-10 border-b border-slate-800 bg-slate-900/80 backdrop-blur">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
            <a href="{{ route('leaderboard') }}" class="text-lg font-semibold tracking-tight text-white">
                {{ config('app.name') }}
            </a>

            <nav class="flex items-center gap-1 text-sm" aria-label="Navigasi utama">
                <a href="{{ route('leaderboard') }}"
                   @class([
                       'rounded-md px-3 py-1.5 font-medium transition',
                       'bg-slate-800 text-white' => request()->routeIs('leaderboard'),
                       'text-slate-400 hover:text-slate-200' => ! request()->routeIs('leaderboard'),
                   ])>Leaderboard</a>
                <a href="{{ route('compare') }}"
                   @class([
                       'rounded-md px-3 py-1.5 font-medium transition',
                       'bg-slate-800 text-white' => request()->routeIs('compare'),
                       'text-slate-400 hover:text-slate-200' => ! request()->routeIs('compare'),
                   ])>Compare</a>
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
