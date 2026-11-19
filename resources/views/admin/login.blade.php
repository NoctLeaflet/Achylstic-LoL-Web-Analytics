@extends('layouts.app')

@section('title', 'Login Admin · '.config('app.name'))

@section('content')
    <div class="mx-auto mt-4 w-full max-w-sm sm:mt-10">
        <h1 class="text-2xl font-semibold tracking-tight text-white">Login Admin</h1>
        <p class="mt-1 text-sm text-slate-400">Khusus admin untuk mengelola daftar teman di leaderboard.</p>

        <form method="POST" action="{{ route('admin.login.store') }}"
              class="mt-6 space-y-4 rounded-xl border border-slate-800 bg-slate-900/50 p-5"
              x-data="{ busy: false }" @submit="busy = true">
            @csrf

            <div>
                <label for="username" class="block text-sm font-medium text-slate-300">Username</label>
                <input id="username" name="username" type="text" value="{{ old('username') }}"
                       required autofocus autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="50"
                       class="mt-1.5 block w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white placeholder-slate-500 focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-slate-300">Password</label>
                <input id="password" name="password" type="password"
                       required autocomplete="current-password" maxlength="200"
                       class="mt-1.5 block w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white placeholder-slate-500 focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500">
            </div>

            @if ($errors->any())
                <p role="alert" class="rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-200">
                    {{ $errors->first() }}
                </p>
            @endif

            <button type="submit" x-bind:disabled="busy"
                    class="w-full rounded-md bg-sky-500 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-sky-400 disabled:opacity-60">
                Masuk
            </button>
        </form>

        <p class="mt-4 text-center text-sm">
            <a href="{{ route('leaderboard') }}" class="text-slate-400 hover:text-slate-200">&larr; Kembali ke leaderboard</a>
        </p>
    </div>
@endsection
