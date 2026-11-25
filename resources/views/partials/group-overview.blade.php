{{-- Top champions of the group, best duos and the winrate per lane. --}}
<div class="mt-10 grid gap-4 lg:grid-cols-2">
    <section class="rounded-xl border border-slate-800 bg-slate-900/50 p-4" aria-labelledby="group-champions">
        <h2 id="group-champions" class="text-base font-semibold text-white">Top champion grup</h2>
        <p class="text-xs text-slate-500">Paling sering dimainkan, semua mode.</p>

        @forelse ($topChampions as $champion)
            <div class="mt-3 flex items-center gap-3">
                <x-champion-icon :name="$champion['champion_name']" :id="$champion['champion_id']" size="size-10" />
                <div class="min-w-0 flex-1">
                    <p class="truncate font-semibold text-white">{{ $champion['champion_name'] }}</p>
                    <p class="truncate text-xs text-slate-500">{{ collect($champion['players'])->map(fn ($p) => $p['player']->name)->implode(', ') }}</p>
                </div>
                <div class="text-right">
                    <p class="text-sm tabular-nums text-slate-300">{{ $champion['games'] }} game</p>
                    <p @class(['text-xs font-semibold tabular-nums', 'text-emerald-400' => $champion['winrate'] >= 50, 'text-rose-400' => $champion['winrate'] < 50])>{{ $champion['winrate'] }}%</p>
                </div>
            </div>
        @empty
            <p class="mt-3 text-sm text-slate-500">Belum ada data.</p>
        @endforelse
    </section>

    <section class="rounded-xl border border-slate-800 bg-slate-900/50 p-4" aria-labelledby="best-duos">
        <h2 id="best-duos" class="text-base font-semibold text-white">Duo terbaik</h2>
        <p class="text-xs text-slate-500">Winrate saat satu tim, minimal 3 game bersama.</p>

        @forelse ($duos as $duo)
            <div class="mt-3 flex items-center gap-3">
                <span class="flex -space-x-2">
                    <x-profile-icon :player="$duo['a']" size="size-10" class="ring-2 ring-slate-900" />
                    <x-profile-icon :player="$duo['b']" size="size-10" class="ring-2 ring-slate-900" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate font-semibold text-white">{{ $duo['a']->name }} &amp; {{ $duo['b']->name }}</p>
                    <p class="text-xs text-slate-500">{{ $duo['record']['wins'] }}W {{ $duo['record']['losses'] }}L &middot; {{ $duo['record']['games'] }} game</p>
                </div>
                <span @class(['text-sm font-semibold tabular-nums', 'text-emerald-400' => $duo['record']['winrate'] >= 50, 'text-rose-400' => $duo['record']['winrate'] < 50])>{{ \App\Support\Format::number($duo['record']['winrate']) }}%</span>
            </div>
        @empty
            <p class="mt-3 text-sm text-slate-500">Belum ada duo dengan minimal 3 game bersama.</p>
        @endforelse
        <a href="{{ route('duos') }}" class="mt-4 inline-block text-xs font-medium text-sky-300 hover:underline">Lihat semua duo &rarr;</a>
    </section>
</div>

<section class="mt-10" aria-labelledby="role-winrate">
    <h2 id="role-winrate" class="text-xl font-semibold tracking-tight text-white">Winrate per role</h2>
    <p class="mt-1 text-sm text-slate-400">Total menang dan kalah semua pemain grup di tiap lane.</p>

    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        @foreach ($roleWinrates as $role)
            <div class="rounded-xl border border-slate-800 bg-slate-900/50 p-4">
                <p class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ $role['label'] }}</p>
                @if ($role['winrate'] === null)
                    <p class="mt-2 text-2xl font-extrabold text-slate-600">–</p>
                    <p class="text-xs text-slate-500">Belum ada game</p>
                @else
                    <p @class(['mt-2 text-2xl font-extrabold tabular-nums', 'text-emerald-400' => $role['winrate'] >= 50, 'text-rose-400' => $role['winrate'] < 50])>{{ \App\Support\Format::number($role['winrate']) }}%</p>
                    <p class="text-xs tabular-nums text-slate-500">{{ $role['wins'] }}W {{ $role['losses'] }}L</p>
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-800" aria-hidden="true">
                        <div class="h-full rounded-full bg-sky-400" style="width: {{ $role['winrate'] }}%"></div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</section>
